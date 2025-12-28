<?php
#
# Generate AI illustrations for messages without photos.
# This fetches line drawings from Pollinations.ai for messages that have no attachments.
# Tracks the last processed arrival time to avoid re-adding illustrations that were deleted.
# Uses messages_groups.arrival rather than msgid so that moderated messages (which arrive later)
# are still processed.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Get the last arrival time we've processed. This prevents re-adding illustrations
# that a mod or user has deleted. We use arrival time rather than msgid because
# moderated messages may have lower msgids but arrive later.
#
# Note: there's an edge case where if a user deletes an illustration from a message
# at exactly the lastArrival time, we might re-add it. This is rare and the user
# can add their own photo to prevent it permanently.
$CONFIG_KEY = 'illustrations_last_arrival';
$lastArrival = NULL;
$configRow = $dbhr->preQuery("SELECT value FROM config WHERE `key` = ?", [$CONFIG_KEY]);
if (count($configRow) > 0) {
    $lastArrival = $configRow[0]['value'];
}

# On first run or migration from old msgid-based tracking, start from 1 day ago.
if (!$lastArrival) {
    $lastArrival = date('Y-m-d H:i:s', strtotime('-1 day'));
}

# First, clean up any messages that have both AI and non-AI attachments.
# This can happen if a user adds their own photo after an AI illustration was created.
$duplicates = $dbhr->preQuery("
    SELECT DISTINCT ma_ai.id, ma_ai.msgid
    FROM messages_attachments ma_ai
    INNER JOIN messages_attachments ma_real ON ma_real.msgid = ma_ai.msgid
    WHERE JSON_EXTRACT(ma_ai.externalmods, '$.ai') = TRUE
    AND (ma_real.externalmods IS NULL OR JSON_EXTRACT(ma_real.externalmods, '$.ai') IS NULL OR JSON_EXTRACT(ma_real.externalmods, '$.ai') = FALSE)
");

foreach ($duplicates as $dup) {
    error_log("Removing AI illustration {$dup['id']} from message {$dup['msgid']} - user added their own photo");
    $dbhm->preExec("DELETE FROM messages_attachments WHERE id = ?", [$dup['id']]);

    # Ensure one attachment has primary=1 if none currently does.
    $hasPrimary = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? AND `primary` = 1 LIMIT 1", [$dup['msgid']]);
    if (count($hasPrimary) == 0) {
        $dbhm->preExec("UPDATE messages_attachments SET `primary` = 1
                        WHERE msgid = ? ORDER BY id ASC LIMIT 1", [$dup['msgid']]);
    }
}

# Keep processing until no messages found or abort requested
do {
    if (file_exists('/tmp/iznik.mail.abort')) {
        error_log("Abort file found, exiting");
        break;
    }

    # Find a message that has no attachments and hasn't been processed yet.
    # We use messages_groups.arrival to track progress, so that moderated messages
    # (which may have lower msgids but arrive later) are still processed.
    # Multiple messages can arrive at the same time, so we may re-check messages
    # that already have illustrations - that's fine, we just skip them.
    $msgs = $dbhr->preQuery("
        SELECT DISTINCT mg.msgid, m.subject, mg.arrival
        FROM messages_groups mg
        INNER JOIN messages m ON m.id = mg.msgid
        INNER JOIN messages_spatial ms ON ms.msgid = mg.msgid
        LEFT JOIN messages_attachments ma ON ma.msgid = m.id
        WHERE mg.arrival >= ?
        AND mg.collection = 'Approved'
        AND ma.id IS NULL
        AND m.subject IS NOT NULL
        AND m.subject != ''
        ORDER BY mg.arrival ASC, mg.msgid ASC
        LIMIT 1
    ", [$lastArrival]);

    if (count($msgs) == 0) {
        break;
    }

    $msg = $msgs[0];
    $msgid = $msg['msgid'];
    $subject = $msg['subject'];
    $arrival = $msg['arrival'];

    # Update the last processed arrival time immediately. We do this regardless of whether we add an
    # illustration or not - the point is we've considered this message and shouldn't process it
    # again (e.g., if someone deletes the illustration).
    # We use > not >= in the comparison because multiple messages can have the same arrival time,
    # and we want to process all of them before advancing.
    if ($arrival > $lastArrival) {
        $lastArrival = $arrival;
        $dbhm->preExec("INSERT INTO config (`key`, value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE value = ?", [
            $CONFIG_KEY,
            $lastArrival,
            $lastArrival
        ]);
    }

    # Extract just the item name from the subject (remove OFFER/WANTED prefix and location suffix)
    $itemName = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i', '', $subject);
    $itemName = preg_replace('/\s*\([^)]+\)\s*$/', '', $itemName);
    $itemName = trim($itemName);

    if (empty($itemName)) {
        continue;
    }

    # Check if we already have a cached image for this item name
    $cached = $dbhr->preQuery("SELECT externaluid FROM ai_images WHERE name = ?", [$itemName]);

    if (count($cached) > 0 && $cached[0]['externaluid']) {
        # Use the cached image
        $uid = $cached[0]['externaluid'];

        # Re-check that message still has no attachments
        $check = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? LIMIT 1", [$msgid]);

        if (count($check) == 0) {
            # Create the attachment using the cached image uid
            $dbhm->preExec("INSERT INTO messages_attachments (msgid, externaluid, externalmods) VALUES (?, ?, ?)", [
                $msgid,
                $uid,
                json_encode(['ai' => TRUE])
            ]);

            error_log("Used cached illustration for message $msgid: " . $itemName);
        }
    } else {
        # No cached image - fetch from Pollinations
        error_log("Fetching illustration for message $msgid: " . $itemName);

        # Prompt injection defense (defense in depth):
        # Strip common prompt injection keywords from user input.
        # Note: No prompt injection defense is foolproof, but risk here is low (worst case: odd image).
        $cleanName = str_replace('CRITICAL:', '', $itemName);
        $cleanName = str_replace('Draw only', '', $cleanName);

        $prompt = urlencode(
            "Draw a single friendly cartoon white line drawing on dark green background, moderate shading, " .
            "cute and quirky style, UK audience, centered, gender-neutral, " .
            "if showing people use abstract non-gendered figures. " .
            "CRITICAL: Do not include any text, words, letters, numbers or labels anywhere in the image. " .
            "Draw only a picture of: " . $cleanName
        );
        $url = "https://image.pollinations.ai/prompt/{$prompt}?width=640&height=480&nologo=true&seed=1";

        # Fetch the image with 2 minute timeout
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120
            ]
        ]);

        $data = @file_get_contents($url, FALSE, $ctx);

        if ($data && strlen($data) > 0) {
            # Re-check that message still has no attachments. The fetch takes a long time and the user may
            # have added their own photo in the meantime.
            $check = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? LIMIT 1", [$msgid]);

            if (count($check) == 0) {
                # Create the attachment, marking it as AI-generated via externalmods.
                $a = new Attachment($dbhr, $dbhm, NULL, Attachment::TYPE_MESSAGE);
                $ret = $a->create($msgid, $data, NULL, NULL, TRUE, ['ai' => TRUE]);

                if ($ret) {
                    # Also cache in ai_images for future reuse
                    $uid = $a->getExternalUid();
                    if ($uid) {
                        $dbhm->preExec("INSERT INTO ai_images (name, externaluid) VALUES (?, ?)
                                       ON DUPLICATE KEY UPDATE externaluid = VALUES(externaluid), created = NOW()", [
                            $itemName,
                            $uid
                        ]);
                    }

                    error_log("Created illustration for message $msgid: " . $itemName);
                }
            } else {
                error_log("Skipped illustration for message $msgid - attachments added during fetch");
            }
        } else {
            error_log("Failed to fetch illustration for message $msgid: " . $itemName);
        }
    }
} while (TRUE);

Utils::unlockScript($lockh);

<?php
#
# Generate AI illustrations for messages without photos.
# This fetches line drawings from Pollinations.ai for messages that have no attachments.
# Tracks the last processed arrival time to avoid re-adding illustrations that were deleted.
# Uses messages_groups.arrival rather than msgid so that moderated messages (which arrive later)
# are still processed.
#
# Uses batch fetching to detect rate-limiting before saving any images.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Batch size for fetching new images.
const BATCH_SIZE = 5;

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

    # Find messages that have no attachments and haven't been processed yet.
    # We use messages_groups.arrival to track progress, so that moderated messages
    # (which may have lower msgids but arrive later) are still processed.
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
        LIMIT " . (BATCH_SIZE * 2) . "
    ", [$lastArrival]);

    if (count($msgs) == 0) {
        break;
    }

    # Separate messages into those with cached images and those needing new images.
    $cachedMessages = [];
    $newMessages = [];
    $maxArrival = $lastArrival;

    foreach ($msgs as $msg) {
        $msgid = $msg['msgid'];
        $subject = $msg['subject'];
        $arrival = $msg['arrival'];

        if ($arrival > $maxArrival) {
            $maxArrival = $arrival;
        }

        # Extract just the item name from the subject.
        $itemName = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i', '', $subject);
        $itemName = preg_replace('/\s*\([^)]+\)\s*$/', '', $itemName);
        $itemName = trim($itemName);

        if (empty($itemName)) {
            continue;
        }

        # Check if we already have a cached image for this item name.
        $cached = $dbhr->preQuery("SELECT externaluid FROM ai_images WHERE name = ?", [$itemName]);

        if (count($cached) > 0 && $cached[0]['externaluid']) {
            $cachedMessages[] = [
                'msgid' => $msgid,
                'itemName' => $itemName,
                'uid' => $cached[0]['externaluid']
            ];
        } else {
            # Only collect up to BATCH_SIZE new messages.
            if (count($newMessages) < BATCH_SIZE) {
                $newMessages[] = [
                    'msgid' => $msgid,
                    'itemName' => $itemName
                ];
            }
        }
    }

    # Process cached messages immediately.
    foreach ($cachedMessages as $cached) {
        $check = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? LIMIT 1", [$cached['msgid']]);

        if (count($check) == 0) {
            $dbhm->preExec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, contenttype) VALUES (?, ?, ?, ?)", [
                $cached['msgid'],
                $cached['uid'],
                json_encode(['ai' => TRUE]),
                'image/jpeg'
            ]);
            error_log("Used cached illustration for message {$cached['msgid']}: {$cached['itemName']}");
        }
    }

    # Process new messages in a batch.
    if (count($newMessages) > 0) {
        # Build batch request.
        $batchItems = [];
        foreach ($newMessages as $msg) {
            $batchItems[] = [
                'name' => $msg['itemName'],
                'prompt' => Pollinations::buildMessagePrompt($msg['itemName']),
                'width' => 640,
                'height' => 480,
                'msgid' => $msg['msgid']
            ];
        }

        error_log("Fetching batch of " . count($batchItems) . " illustrations");
        $results = Pollinations::fetchBatch($batchItems, 120);

        if ($results === FALSE) {
            # Rate-limited - wait before trying again.
            error_log("Batch rate-limited, waiting 60 seconds");
            sleep(60);
            continue;
        }

        # Save all successful images.
        foreach ($results as $i => $result) {
            $msgid = $batchItems[$i]['msgid'];
            $itemName = $result['name'];
            $data = $result['data'];
            $hash = $result['hash'];

            # Re-check that message still has no attachments.
            $check = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? LIMIT 1", [$msgid]);

            if (count($check) == 0) {
                $a = new Attachment($dbhr, $dbhm, NULL, Attachment::TYPE_MESSAGE);
                $ret = $a->create($msgid, $data, NULL, NULL, TRUE, ['ai' => TRUE]);

                if ($ret) {
                    $uid = $a->getExternalUid();
                    if ($uid) {
                        Pollinations::cacheImage($itemName, $uid, $hash);
                    }
                    error_log("Created illustration for message $msgid: $itemName");
                }
            } else {
                error_log("Skipped illustration for message $msgid - attachments added during fetch");
            }
        }
    }

    # Update the last processed arrival time.
    if ($maxArrival > $lastArrival) {
        $lastArrival = $maxArrival;
        $dbhm->preExec("INSERT INTO config (`key`, value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE value = ?", [
            $CONFIG_KEY,
            $lastArrival,
            $lastArrival
        ]);
    }

    # If we only had cached messages and no new ones, we're done with this batch.
    if (count($newMessages) == 0 && count($cachedMessages) == 0) {
        break;
    }

} while (TRUE);

Utils::unlockScript($lockh);

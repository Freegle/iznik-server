<?php
#
# Generate AI illustrations for messages without photos.
# This fetches line drawings from Pollinations.ai for messages that have no attachments.
# Only processes messages from the last hour in messages_spatial to limit retries.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Find messages in messages_spatial from the last hour that have no attachments
$msgs = $dbhr->preQuery("
    SELECT DISTINCT ms.msgid, m.subject
    FROM messages_spatial ms
    INNER JOIN messages m ON m.id = ms.msgid
    LEFT JOIN messages_attachments ma ON ma.msgid = m.id
    WHERE ms.arrival > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    AND ma.id IS NULL
    AND m.subject IS NOT NULL
    AND m.subject != ''
    ORDER BY ms.arrival ASC
    LIMIT 10
");

foreach ($msgs as $msg) {
    $msgid = $msg['msgid'];
    $subject = $msg['subject'];

    # Extract just the item name from the subject (remove OFFER/WANTED prefix and location suffix)
    $itemName = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i', '', $subject);
    $itemName = preg_replace('/\s*\([^)]+\)\s*$/', '', $itemName);
    $itemName = trim($itemName);

    if (empty($itemName)) {
        continue;
    }

    # Build the Pollinations.ai URL
    # Use 640x480 as that's what the frontend uses
    $prompt = urlencode("friendly cartoon dark green line drawing on white background, simple sketch of " . $itemName . ", minimal shading, cute style");
    $url = "https://image.pollinations.ai/prompt/{$prompt}?width=640&height=480&nologo=true";

    # Fetch the image with 2 minute timeout
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120
        ]
    ]);

    $data = @file_get_contents($url, FALSE, $ctx);

    if ($data && strlen($data) > 0) {
        # Create the attachment
        $a = new Attachment($dbhr, $dbhm, NULL, Attachment::TYPE_MESSAGE);
        $ret = $a->create($msgid, $data);

        if ($ret) {
            error_log("Created illustration for message $msgid: " . $itemName);
        }
    } else {
        error_log("Failed to fetch illustration for message $msgid: " . $itemName);
    }
}

Utils::unlockScript($lockh);

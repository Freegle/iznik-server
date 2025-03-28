<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get stats.
$msgs = $dbhr->preQuery("SELECT DISTINCT(msgid) FROM messages_attachments_recognise
INNER JOIN messages_attachments ON messages_attachments.id = attid
WHERE msgid IS NOT NULL
ORDER BY msgid DESC;");

error_log("Found " . count($msgs) . " messages to process");

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);

    # Get the approximateWeightInKg from the row in messages_Attachements_recognise where the msgid is the same as the current msgid with the lowest id
    $recognise = $dbhr->preQuery("SELECT JSON_EXTRACT(info, '$.ElectricalItem') AS electrical FROM messages_attachments 
               LEFT JOIN messages_attachments_recognise ON messages_attachments_recognise.attid = messages_attachments.id 
               WHERE msgid = ? ORDER BY messages_attachments.id ASC LIMIT 1;", [
        $msg['msgid']
    ]);

    error_log("{$recognise[0]['electrical']} {$msg['msgid']} {$m->getSubject()} ");
}

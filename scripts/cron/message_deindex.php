<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Only interested in being able to search within the last 30 days.
$date = date('Y-m-d', strtotime("30 days ago"));
$msgs = $dbhr->preQuery("SELECT DISTINCT messages_groups.msgid FROM messages_groups INNER JOIN messages_index ON messages_index.msgid = messages_groups.msgid WHERE messages_groups.collection = 'Approved' AND messages_groups.arrival < '$date' ORDER BY messages_groups.arrival DESC;");

error_log("Deindex " . count($msgs));

$done = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);
    $m->deindex();

    $done++;

    if ($done % 1000 === 0) {
        error_log("...$done / " . count($msgs));
    }
}
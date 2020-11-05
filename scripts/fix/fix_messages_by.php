<?php
namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$outcomes = $dbhr->preQuery("SELECT msgid, userid, timestamp FROM messages_outcomes WHERE userid IS NOT NULL AND outcome IN ('Taken', 'Received');");
$total = count($outcomes);
$count = 0;

foreach ($outcomes as $outcome) {
    try {
        $dbhm->preExec("INSERT IGNORE INTO messages_by (msgid, userid, timestamp) VALUES (?, ?, ?);", [
            $outcome['msgid'],
            $outcome['userid'],
            $outcome['timestamp'],
        ]);
    } catch (Exception $e) {}

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count / $total");
    }
}
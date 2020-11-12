<?php
namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$outcomes = $dbhr->preQuery("SELECT COUNT(*) AS count, msgid FROM `messages_by` GROUP BY msgid HAVING count > 1;");
$total = count($outcomes);
$count = 0;

error_log("Found $total");

foreach ($outcomes as $outcome) {
    $first = $dbhr->preQuery("SELECT id FROM messages_by WHERE msgid = ? ORDER BY id DESC LIMIT 1;", [
        $outcome['msgid']
    ]);

    $dbhm->preExec("DELETE FROM messages_by WHERE msgid = ? AND id < ?;", [
        $outcome['msgid'],
        $first[0]['id']
    ]);

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count / $total");
    }
}
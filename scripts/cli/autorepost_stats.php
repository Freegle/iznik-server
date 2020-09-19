<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$date = date('Y-m-d', strtotime("100 days ago"));
$msgs = $dbhr->preQuery("SELECT DISTINCT msgid FROM messages_postings WHERE date >= '$date';");

$total = count($msgs);
error_log("Total $total");
$count = 0;
$stats = [];

foreach ($msgs as $msg) {
    $reposts = $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_postings WHERE msgid = ?;", [
        $msg['msgid']
    ]);

    $num = $reposts[0]['count'];

    $success = $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_outcomes WHERE msgid = ? AND outcome IN (?, ?);", [
        $msg['msgid'],
        Message::OUTCOME_TAKEN,
        Message::OUTCOME_RECEIVED
    ]);

    $succeeded = $success[0]['count'];

    if (!array_key_exists($num, $stats)) {
        $stats[$num] = [
            'success' => 0,
            'failure' => 0
        ];
    }

    if ($succeeded) {
        $stats[$num]['success']++;
    } else {
        $stats[$num]['failure']++;
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

foreach ($stats as $num => $outcome) {
    error_log("$num, {$outcome['success']}, {$outcome['failure']}");
}
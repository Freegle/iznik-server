<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$p = new Pledge($dbhr, $dbhm);
$postings = [];

$users = $dbhr->preQuery("SELECT id FROM users WHERE lastaccess >= '2024-12-20' AND JSON_EXTRACT(settings, '$.pledge2025');");

foreach ($users as $user) {
    $postings[$user['id']] = [];
}

for ($month = 1; $month < 12; $month++) {
    foreach ($users as $user) {
        list($start, $count) = $p->countPosted($user['id'], $month);

        if ($count) {
            $postings[$user['id']][$month] = $count;
        }
    }
}

$counts = [];
$counts[0] = count($users);

foreach ($postings as $userid => $months) {
    # Get number of months posted in.
    $months = count(array_keys($months));

    $counts[$months] = isset($counts[$months]) ? $counts[$months] + 1 : 1;
}

ksort($counts);
error_log("Counts: " . json_encode($counts));
Utils::unlockScript($lockh);
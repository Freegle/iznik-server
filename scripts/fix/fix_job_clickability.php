<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$jobs = $dbhr->preQuery("SELECT id, title FROM jobs;");
$j = new Jobs($dbhr, $dbhm);
$count = 0;

$maxish = $j->getMaxish();

foreach ($jobs as $job) {
    $score = $j->clickability($job['id'], $job['title'], $maxish);
    $dbhm->preExec("UPDATE jobs SET clickability = ? WHERE id = ?;", [
        $score,
        $job['id']
    ]);

    #error_log("{$job['title']} => $score");

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count / " . count($jobs));
    }
}
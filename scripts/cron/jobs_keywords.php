<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# See which jobs keywords are used most frequently.
$keywords = [];

$date = date('Y-m-d', strtotime("30 days ago"));
$jobs = $dbhr->preQuery("SELECT DISTINCT userid, link FROM logs_jobs WHERE timestamp > '$date';");

foreach ($jobs as $job) {
    if (preg_match('/.*\/(.*)\?/', $job['link'], $matches)) {
        $words = explode('-', $matches[1]);

        foreach ($words as $word) {
            if (!is_numeric($word)) {
                if (Utils::pres($word, $keywords)) {
                    $keywords[$word]++;
                } else {
                    $keywords[$word] = 1;
                }
            }
        }
    }
}

arsort($keywords);

foreach ($keywords as $word => $count) {
    $dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, ?) ON DUPLICATE KEY UPDATE count = ?;", [
        $word,
        $count,
        $count
    ]);
}
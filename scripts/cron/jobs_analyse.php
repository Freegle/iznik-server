<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Prune old job logs - old data not worth analysing.
$mysqltime = date("Y-m-d H:i:s", strtotime("midnight 31 days ago"));
$dbhm->preExec("DELETE FROM logs_jobs WHERE timestamp < ?", [
    $mysqltime
]);

if (TRUE) {
# Find any jobs which have the link but not the id.
# TODO remove link field after 2021-06-01.
    $logs = $dbhr->preQuery("SELECT * FROM logs_jobs WHERE jobid IS NULL AND link IS NOT NULL ORDER BY id DESC;");
    error_log("Logs to fix " . count($logs));
    $count = 0;

    foreach ($logs as $log) {
        $jobs = $dbhr->preQuery("SELECT id FROM jobs WHERE jobs.url = ?;", [
            $log['link']
        ]);

        if (count($jobs)) {
            $dbhm->preExec("UPDATE logs_jobs SET jobid = ? WHERE id = ?;", [
                $jobs[0]['id'],
                $log['id']
            ]);
        }

        $count++;

        error_log("...$count / " . count($logs));
    }
}

# Now process the clicked jobs to extract keywords.  Use DISTINCT as some people click obsessively on the same job.
$jobs = $dbhr->preQuery("SELECT DISTINCT  jobs.* FROM logs_jobs INNER JOIN jobs ON logs_jobs.jobid = jobs.id");
$dbhm->preExec("TRUNCATE TABLE jobs_keywords");

error_log("Process " . count($jobs));

foreach ($jobs as $job) {
    $keywords = Jobs::getKeywords($job['title']);

    if (count($keywords)) {
        #error_log("{$job['title']} => " . json_encode($keywords));
        foreach ($keywords as $k) {
            $dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1;", [
                $k
            ]);
        }
    }
}

Utils::unlockScript($lockh);
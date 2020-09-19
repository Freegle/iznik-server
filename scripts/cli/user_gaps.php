<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Users who have posted.
$recents = $dbhr->preQuery("SELECT DISTINCT fromuser FROM messages WHERE arrival >= '2019-01-01' AND arrival <= '2019-12-31';");
$total = count($recents);
error_log("Found $total");
$count = 0;

foreach ($recents as $recent) {
    # Find biggest gap between post.
    $logs = $dbhr->preQuery("SELECT arrival FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1000;", [
        $recent['fromuser']
    ]);

    if (count($logs)) {
        # Someone who has posted.
        $lasttime = NULL;
        $maxgap = 0;

        foreach ($logs as $log) {
            if ($lasttime !== NULL) {
                $thistime = strtotime($log['arrival']);
                $gap = $lasttime - $thistime;
                #error_log("Consider gap $gap vs %maxgap from {$log['timestamp']}");
                if ($gap > $maxgap) {
                    $maxgap = $gap;
                }
            }

            $lasttime = strtotime($log['arrival']);
        }

        $months = round($maxgap / 24 / 60 / 60 / 31);
        printf("{$recent['fromuser']}, $months\n");
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("$count / $total");
    }
}

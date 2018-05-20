<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$handle = fopen("/tmp/pflogsumm.out", "r");
while (($line = fgets($handle)) !== false) {
    if (strpos($line, "Message Delivery") != FALSE) {
        error_log("Found delivery");
        $line = fgets($handle);
        $line = fgets($handle);
        $line = fgets($handle);

        while (($line = fgets($handle)) !== false && strlen(trim($line)) > 0) {
            # Line is of format:
            #
            # 173200    25544m       0    44.6 s    7.2 m  user.trashnothing.com
            $parts = preg_split('/\s+/', $line);
            $count = $parts[1];
            $defers = $parts[3];
            $delay = floatval($parts[4]);
            $delayunit = $parts[5];

            switch (trim($delayunit)) {
                case 'm': $delay *= 60; break;
                case 'h': $delay *= 60*60; break;
                case 'd': $delay *= 60*60*24; break;
            }
            $domain = $parts[8];

            if ($count > 100) {
                $problem = $defers / $count > 0.2 || $delay > 30 * 60;
                if ($problem) {
                    error_log("Problem domain: sent $count defers $defers delay $delay to $domain");
                }

                $dbhm->preExec("REPLACE INTO domains (domain, sent, defers, avgdly, problem) VALUES (?, ?, ?, ?, ?);", [
                    $domain,
                    $count,
                    $defers,
                    $delay,
                    $problem
                ]);
            }
        }

        exit(0);
    }
}

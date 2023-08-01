<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$ips = $dbhr->preQuery("select `iznik`.`logs_api`.`ip` AS `ip`,count(distinct `iznik`.`logs_api`.`userid`) AS `count` from `iznik`.`logs_api` where ((`iznik`.`logs_api`.`userid` is not null) and (`iznik`.`logs_api`.`date` >= '2023-08-01 05:00') and (not((`iznik`.`logs_api`.`response` like '%not from%'))) and (not((`iznik`.`logs_api`.`request` like '%partner%')))) group by `iznik`.`logs_api`.`ip` having (`count` > 1)");

foreach ($ips as $ip) {
    $uids = [];
    $logs = $dbhr->preQuery("SELECT * FROM logs_api WHERE ip = ? AND userid IS NOT NULL", [ $ip['ip'] ]);

    foreach ($logs as $log) {
        if (!array_key_exists($log['ip'], $uids)) {
            $uids[$log['ip']] = [
                'uids' => [],
                'uas' => []
            ];
        }

        $ip = $log['ip'];
        $req = json_decode($log['request'], true);

        if ($ip && $req && array_key_exists('headers', $req) && array_key_exists('User-Agent', $req['headers'])) {
            $ua = $req['headers']['User-Agent'];

            if (!in_array($ua, $uids[$ip]['uas'])) {
                $uids[$ip]['uas'][] = $ua;
            }

            if (!in_array($log['userid'], $uids[$ip]['uids'])) {
                $uids[$ip]['uids'][] = $log['userid'];
            }
        }
    }

    foreach ($uids as $ip => $uid) {
        if (count($uid['uas']) == 1) {
            // Probably same browser rather than different devices in the same household.
            $hostname = gethostbyaddr($ip);
            echo "IP " . $ip . " ($hostname) used for:\n";

            foreach ($uid['uids'] as $uid) {
                $u = new User($dbhr, $dbhm, $uid);
                echo "  " . $uid['userid'] . " " . $u->getName() . " (" . $u->getEmailPreferred() . ")\n";
            }
        }
    }
}
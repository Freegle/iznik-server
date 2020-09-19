<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$start = date('Y-m-d', strtotime("1000 days ago"));
$recent = date('Y-m-d', strtotime("30 days ago"));

$recents = $dbhr->preQuery("SELECT DISTINCT(byuser) FROM logs INNER JOIN groups ON logs.groupid = groups.id LEFT OUTER JOIN teams_members ON teams_members.userid = logs.byuser WHERE timestamp >= '$recent' AND logs.type = 'Message' AND subtype = 'Approved' AND groups.type = 'Freegle' AND teams_members.userid IS NULL;");
$userids = array_column($recents, 'byuser');

$counts = $dbhr->preQuery("SELECT COUNT(DISTINCT(CONCAT(YEAR(timestamp), '-', MONTH(timestamp)))) AS months, byuser FROM logs WHERE timestamp >= '$start' AND type = 'Message' AND subtype = 'Approved' AND byuser IN (-1" . implode(',', $userids) . ") GROUP BY byuser;");
uasort($counts, function($a, $b) {
    return($b['months'] - $a['months']);
});
$counts = array_slice($counts, 0, 100);

$mentorgroups = [];

foreach ($counts as $count) {
    $u = new User($dbhr, $dbhm, $count['byuser']);
    $membs = $u->getMemberships(TRUE);

    $modon = count($membs);
    $homegroup = '';

    $settings = $u->getPrivate('settings');
    $settings = json_decode($settings, TRUE);

    if (Utils::pres('mylocation', $settings)) {
        #error_log($u->getName() . " location {$settings['mylocation']['id']}");
        $l = new Location($dbhr, $dbhm, $settings['mylocation']['id']);
        $nears = $l->groupsNear(20, TRUE, 50);

        foreach ($nears as $near) {
            if (!strlen($homegroup)) {
                $homegroup = $near['nameshort'];
            }

            #error_log("Near {$near['id']}");
            if ($near['mentored']) {
                if (!array_key_exists($near['nameshort'], $mentorgroups)) {
                    $mentorgroups[$near['nameshort']] = [];
                }

                $mentorgroups[$near['nameshort']][] = $u->getName() . " (" . $u->getEmailPreferred() . ") home group $homegroup mod on $modon";
            }
        }
    }
}

ksort($mentorgroups);
foreach ($mentorgroups as $group => $possibles) {
    error_log($group);

    ksort($possibles);

    foreach ($possibles as $possible) {
        error_log("...$possible");
    }
}
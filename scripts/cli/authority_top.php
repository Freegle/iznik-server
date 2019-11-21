<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once IZNIK_BASE . '/include/misc/Authority.php';
require_once IZNIK_BASE . '/include/misc/Stats.php';

$auths = $dbhr->preQuery("SELECT * FROM authorities WHERE area_code IN ('CTY', 'DIS', 'MTD', 'UTA') ORDER BY LOWER(name);");

$weights = [];
$today = date ("Y-m-d", strtotime("today"));

foreach ($auths as $auth) {
    try {
        $a = new Authority($dbhr, $dbhm, $auth['id']);
        $atts = $a->getPublic();
        $total = 0;

        foreach ($atts['groups'] as $group) {
            $s = new Stats($dbhr, $dbhm);
            $stats = $s->getMulti($today, [ $group['id'] ], "365 days ago", "tomorrow");
            $weight = 0;
            foreach ($stats[Stats::WEIGHT] as $stat) {
                $weight += $stat['count'];
            }

            $total += $weight * $group['overlap'];
        }

        $total = round($total / 1000, 1);
        $weights[$auth['id']] = $total;
        error_log($a->getPrivate('name') . " weight {$total} tonnes");
    } catch (Exception $e) {}
}

arsort($weights);
$count = 0;

error_log("\r\n\r\n");

foreach ($weights as $authid => $weight) {
    $a = new Authority($dbhr, $dbhm, $authid);
    error_log($a->getPrivate('name') . " weight {$weight} tonnes");
    $count++;

    if ($count >= 5) {
        break;
    }
}
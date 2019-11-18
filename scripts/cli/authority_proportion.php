<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once IZNIK_BASE . '/include/misc/Authority.php';
require_once IZNIK_BASE . '/include/misc/Stats.php';

$auths = $dbhr->preQuery("SELECT * FROM authorities WHERE area_code IN ('CTY', 'DIS', 'MTD', 'UTA') ORDER BY LOWER(name);");
$total = 0;
$res = [];

foreach ($auths as $auth) {
    $a = new Authority($dbhr, $dbhm, $auth['id']);
    try {
        $atts = $a->getPublic();
        $acttotal = 0;

        foreach ($atts['groups'] as $group) {
            $g = new Group($dbhr, $dbhm, $group['id']);
            $acttotal = $g->getPrivate('activitypercent') * $group['overlap'];
        }

        echo "{$auth['name']}, " . round($acttotal, 4) . "\n";
    } catch (Exception $e) {
        error_log("Exception on {$auth['id']} {$auth['name']} " . $e->getMessage());
    }
}
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

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
    } catch (\Exception $e) {
        error_log("Exception on {$auth['id']} {$auth['name']} " . $e->getMessage());
    }
}
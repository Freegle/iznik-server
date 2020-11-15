<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$ratings = $dbhr->preQuery("SELECT * FROM ratings ORDER BY id DESC LIMIT 10000;");

$up = [];
$down = [];

foreach ($ratings as $r) {
    $u1 = new User($dbhr, $dbhm, $r['rater']);
    $u2 = new User($dbhr, $dbhm, $r['ratee']);
    $u1loc = $u1->getLatLng(FALSE, FALSE);
    $u2loc = $u2->getLatLng(FALSE, FALSE);

    if ($u1loc[0] && $u1loc[1] && $u2loc[0] && $u2loc[1]) {
        $dist = \GreatCircle::getDistance($u1loc[0], $u1loc[1], $u2loc[0], $u2loc[1]);

        if ($dist < 200000) {
            if ($r['rating'] == 'Up') {
                $up[] = $dist;
            } else {
                $down[] = $dist;
            }
        }
    }
}

error_log("Up " . Utils::calculate_median($up));
error_log("Down " . Utils::calculate_median($down));


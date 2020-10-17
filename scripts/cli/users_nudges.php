<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
global $dbhr, $dbhm;

$worked = [];
$failed = [];

$users = $dbhr->preQuery("SELECT * FROM users_nudges;");

$u = new User($dbhr, $dbhm);

foreach ($users as $user) {
    $u2 = new User($dbhr, $dbhm, $user['touser']);
    $tmp = [
        $user['fromuser'] => [
            'id' => $user['fromuser']
        ],
        $user['touser'] => [
            'id' => $user['touser']
        ],
    ];

    $latlngs = $u->getLatLngs($tmp, FALSE, FALSE);

    if (Utils::pres($user['touser'], $latlngs) && Utils::pres($user['fromuser'], $latlngs)) {
        $dist = \GreatCircle::getDistance($latlngs[$user['fromuser']]['lat'], $latlngs[$user['fromuser']]['lng'], $latlngs[$user['touser']]['lat'], $latlngs[$user['touser']]['lng']);

        if ($user['responded']) {
            error_log("$dist, 1, 0");
        } else {
            error_log("$dist, 0, 1");
        }
    }
}
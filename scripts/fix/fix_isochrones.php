<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$isochrones = $dbhr->preQuery("SELECT isochrones.id FROM isochrones LEFT JOIN isochrones_users ON isochrones_users.isochroneid = isochrones.id WHERE isochrones_users.id IS NULL");
error_log("...isochrones " . count($isochrones));

foreach ($isochrones as $isochrone) {
    $dbhm->preExec("DELETE FROM isochrones WHERE id = ?;", [
        $isochrone['id']
    ]);
}

$isochrones = $dbhr->preQuery("SELECT id FROM isochrones ORDER BY id ASC;");
$count = 0;

foreach ($isochrones as $isochrone) {
    $dbhm->preExec("UPDATE isochrones SET polygon = ST_Simplify(polygon, ?) WHERE id = ?;", [
        $isochrone['id'],
        0.01
    ]);

    $count++;

    if ($count % 100 == 0) {
        error_log("$count");
    }
}
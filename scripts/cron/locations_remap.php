<?php

namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$l = new Location($dbhr, $dbhm);

$mysqltime = date("Y-m-d", strtotime("Midnight 3 days ago"));
$sql = "SELECT locations.id, locations.name, ST_ASText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom FROM  `locations` WHERE  `type` =  'Polygon' AND  `timestamp` >= ? AND `timestamp` ORDER BY name ASC;";
$locs = $dbhr->preQuery($sql, [ $mysqltime ]);

$count = 0;

foreach ($locs as $loc) {
    $count++;
    error_log("#{$loc['id']} {$loc['name']} ($count / " . count($locs) . ")");
    $l->remapPostcodes($loc['geom']);
}

$sql = "SELECT locations.id, locations.name, ST_ASText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom FROM  `locations` INNER JOIN locations_excluded ON locations_excluded.locationid = locations.id WHERE  `type` = 'Polygon' AND  `date` >= ? ORDER BY name ASC;";
$locs = $dbhr->preQuery($sql, [ $mysqltime ]);

$count = 0;

foreach ($locs as $loc) {
    $count++;
    error_log("#{$loc['id']} {$loc['name']} ($count / " . count($locs) . ")");
    $l->remapPostcodes($loc['geom']);
}

Utils::unlockScript($lockh);
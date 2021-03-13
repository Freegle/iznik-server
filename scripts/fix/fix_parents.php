<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhm, $dbhm);

error_log("Search");
$locs = $dbhm->query("SELECT l1.id FROM `locations` l1 INNER JOIN locations l2 ON l1.areaid = l2.id WHERE LOCATE(' ', l1.name) > 0 AND l1.type = 'Postcode' AND NOT ST_Contains(COALESCE(l2.ourgeometry, l2.geometry), l1.geometry);");
error_log("Searched " . count($locs));;

$count = 0;

foreach ($locs as $loc) {
    try {
        $l->setParents($loc['id']);
        $count++;

        if ($count % 10 == 0) {
            error_log("$count...");
        }
    } catch (\Exception $e) {}
}

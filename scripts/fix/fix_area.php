<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/misc/Location.php');
global $dbhr, $dbhm;

$locs = $dbhr->query("SELECT id FROM locations WHERE areaid = 9683118;");

$count = 0;

foreach ($locs as $loc) {
    $l = new Location($dbhr, $dbhm);
    $l->setParents($loc['id']);
    $count++;
    error_log("$count ");
}



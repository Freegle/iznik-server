<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/misc/Location.php');

$locs = $dbhr->query("SELECT id FROM locations WHERE areaid = (SELECT id FROM locations WHERE name LIKE 'TV13');");

$count = 0;
foreach ($locs as $loc) {
    $l = new Location($dbhr, $dbhm);
    $l->setParents($loc['id']);
}



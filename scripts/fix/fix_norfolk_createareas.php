<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/misc/Location.php');

$fh = fopen('norfolkareas.csv', 'r');

if ($fh) {
    $l = new Location($dbhr, $dbhm);
    while (!feof($fh)) {
        $fields = fgetcsv($fh);
        $wkt = $fields[0];
        $name = $fields[1];

        if (strpos($wkt, 'POLYGON') !== FALSE) {
            $id = $l->create(NULL, $name, 'Polygon', $wkt, TRUE, TRUE);
        }
    }
}

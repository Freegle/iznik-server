<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get file argument f for CSV file.
$opts = getopt('f:');
$file = Utils::presdef('f', $opts, NULL);

$handle = fopen($file, "r");

$data = fgetcsv($handle, 1000, ",");

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    $city = $data[0];
    $lat = $data[1];
    $lng = $data[2];
    $population = $data[7];

    $groups = $dbhr->preQuery("SELECT * FROM `groups` where ST_Within(ST_SRID(POINT($lng, $lat), 3857), ST_GeomFromText(polyofficial, 3857));");

    if (count($groups)) {
        #error_log("Found {$groups[0]['nameshort']} for $city");
    } else {
        error_log("No group for $city, population $population");
    }
}
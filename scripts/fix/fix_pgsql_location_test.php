<?php
# This creates a table in Postgres with the geometries from the locations table.  This is so that we can
# explore Postgres' KNN function.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$pgsql = new \PDO("pgsql:host=localhost;dbname=postgres", "iznik", "iznik");

$fh = fopen('/tmp/checkmap.csv', 'w');

# Make sure we have no excluded locations.
//error_log("Delete excluded");
//$excludeds = $dbhr->preQuery("SELECT DISTINCT locationid FROM locations_excluded");
//$count = 0;
//$batch = [];
//
//foreach ($excludeds as $excluded) {
//    if (count($batch) >= 1000) {
//        $pgsql->query("DELETE FROM locations_tmp WHERE locationid IN (" . implode(',', $batch) . ");");
//        $batch = [];
//        error_log($count);
//    }
//
//    $batch[] = $excluded['locationid'];
//    $count++;
//}
//
//if (count($batch) >= 0) {
//    $pgsql->query("DELETE FROM locations_tmp WHERE locationid IN (" . implode(',', $batch) . ");");
//}

error_log("Get test set");
$pcs = $dbhr->preQuery("SELECT DISTINCT locations_spatial.locationid, locations.name, locations.lat, locations.lng, l1.name AS areaname, l1.id AS areaid FROM locations_spatial 
    INNER JOIN locations ON locations_spatial.locationid = locations.id
    INNER JOIN messages ON messages.locationid = locations_spatial.locationid
    INNER JOIN locations l1 ON locations.areaid = l1.id
    WHERE locations.type = 'Postcode'  
    AND locate(' ', locations.name) > 0
    ;");

$total = count($pcs);

$sth = $pgsql->prepare("SELECT locationid, name
    FROM (
        SELECT locationid,
            name,
            location,
            RANK() OVER(ORDER BY location <-> ST_SetSRID(ST_MakePoint(?, ?), 3857)) AS _rnk
        FROM  locations_tmp
        WHERE ST_Area(location) BETWEEN 0.00005 AND 0.15
        AND LOWER(name) != LOWER(?)
        LIMIT 200
    ) q
    WHERE  _rnk = 1
    ORDER BY
    ST_Area(location)
    LIMIT  1
;");

$same = 0;
$different = 0;
$count = 0;

foreach ($pcs as $pc) {
    $sth->execute([
        $pc['lng'],
        $pc['lat'],
        $pc['name']
    ]);

    while ($pgarea = $sth->fetch()) {

        if ($pc['areaname'] == $pgarea['name']) {
            $same++;
        } else {
            echo("#{$pc['locationid']} {$pc['name']} {$pc['lat']}, {$pc['lng']} = {$pc['areaid']} {$pc['areaname']} => {$pgarea['locationid']} {$pgarea['name']}\n");
            fwrite($fh, "{$pc['lat']}, {$pc['lng']}\n");
            $different++;
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count / $total");
    }
}

error_log("$same same, $different different = " . round(100 * $same / ($same + $different)));

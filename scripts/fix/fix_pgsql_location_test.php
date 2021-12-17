<?php
# This creates a table in Postgres with the geometries from the locations table.  This is so that we can
# explore Postgres' KNN function.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$pgsql = new \PDO("pgsql:host=localhost;dbname=postgres", "iznik", "iznik");

$dbhm->preExec("DELETE FROM locations_dodgy WHERE 1;");

error_log("Get test set");
$pcs = $dbhr->preQuery("SELECT DISTINCT locations_spatial.locationid, locations.name, locations.lat, locations.lng, l1.name AS areaname, l1.id AS areaid FROM locations_spatial 
    INNER JOIN locations ON locations_spatial.locationid = locations.id
    INNER JOIN messages ON messages.locationid = locations_spatial.locationid
    INNER JOIN locations l1 ON locations.areaid = l1.id
    WHERE locations.type = 'Postcode'  
    AND locate(' ', locations.name) > 0 
    LIMIT 10000
    ;");

$total = count($pcs);

$sth = $pgsql->prepare("
SELECT   *
FROM     (
                  SELECT   
                           locationid,
                           name,
                           area,
                           dist,
                           cdist,
                           _drnk,
                           st_contains(location, ST_SetSRID(ST_MakePoint(?,?), 3857)) AS inside
                  FROM     (
                                    SELECT   locationid,
                                             name,
                                             location,
                                             area,
                                             location <-> ST_SetSRID(ST_MakePoint(?,?), 3857)                       AS dist,
                                             ST_Centroid(location) <-> ST_SetSRID(ST_MakePoint(?,?), 3857)          AS cdist,
                                             RANK() OVER(ORDER BY location <-> ST_SetSRID(ST_MakePoint(?,?), 3857)) AS _drnk
                                    FROM     locations_tmp
                                    WHERE    area BETWEEN 0.00003 AND 0.15 limit 200 ) q
                  ORDER BY _drnk limit 10 ) r
ORDER BY _drnk, SQRT(area) LIMIT 10;
");

// ORDER BY cdist * _drnk LIMIT 10; best

$same = 0;
$different = 0;
$count = 0;

foreach ($pcs as $pc) {
    $sth->execute([
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat']
    ]);

    $pgareas = $sth->fetchAll();

    if (count($pgareas)) {
        $pgarea = $pgareas[0];
        if ($pc['areaname'] == $pgarea['name']) {
            $same++;
        } else {
            echo("#{$pc['locationid']} {$pc['name']} {$pc['lat']}, {$pc['lng']} = {$pc['areaid']} {$pc['areaname']} => {$pgarea['locationid']} {$pgarea['name']}\n");
            foreach ($pgareas as $pgarea) {
                echo("...{$pgarea['name']} area {$pgarea['area']} dist {$pgarea['dist']} cdist {$pgarea['cdist']} drnk {$pgarea['_drnk']}\n");
            }
            $dbhm->preExec("INSERT INTO locations_dodgy (lat, lng) VALUES (?, ?)", [
                $pc['lat'],
                $pc['lng']
            ]);
            $different++;
        }
    } else {
        echo("#{$pc['locationid']} {$pc['name']} {$pc['lat']}, {$pc['lng']} = {$pc['areaid']} {$pc['areaname']} => not mapped\n");
        $different++;
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count / $total");
    }
}

error_log("$same same, $different different = " . round(100 * $same / ($same + $different)) . "%");

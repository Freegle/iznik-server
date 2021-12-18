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
$pcs = $dbhr->preQuery("SELECT DISTINCT locations_spatial.locationid, locations.newareaid, locations.name, locations.lat, locations.lng, l1.name AS areaname, l1.id AS areaid FROM locations_spatial 
    INNER JOIN locations ON locations_spatial.locationid = locations.id
    INNER JOIN locations l1 ON locations.areaid = l1.id
    INNER JOIN messages ON messages.locationid = locations_spatial.locationid
    WHERE locations.type = 'Postcode'  
    AND locate(' ', locations.name) > 0
    ;");

//INNER JOIN `groups` ON ST_Contains(ST_GeomFromText(COALESCE(poly, polyofficial), 3857), locations_spatial.geometry) AND groups.id = 21589

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
                           ST_Contains(location, ST_SetSRID(ST_MakePoint(?, ?), 3857)) AS inside,
                           CASE
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.00015625), 3857)) THEN 1
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.0003125), 3857)) THEN 2
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.000625), 3857)) THEN 3
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.00125), 3857)) THEN 4
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.0025), 3857)) THEN 5
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.005), 3857)) THEN 6
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.01), 3857)) THEN 7
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.02), 3857)) THEN 8
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.04), 3857)) THEN 9
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.08), 3857)) THEN 10
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.16), 3857)) THEN 11
                               WHEN ST_Intersects(location, ST_SetSRID(ST_Buffer(ST_MakePoint(?, ?),0.32), 3857)) THEN 12
                           END AS intersects                         
                  FROM     (
                                    SELECT   locationid,
                                             name,
                                             location,
                                             ST_Area(location) AS area,
                                             location <-> ST_SetSRID(ST_MakePoint(?, ?), 3857)                       AS dist,
                                             ST_Centroid(location) <-> ST_SetSRID(ST_MakePoint(?, ?), 3857)          AS cdist,
                                             RANK() OVER(ORDER BY location <-> ST_SetSRID(ST_MakePoint(?, ?), 3857)) AS _drnk
                                    FROM     locations_tmp
                                    WHERE    ST_Area(location) BETWEEN 0.00001 AND 0.15
                                    LIMIT 200                                           
                      ) q
                  WHERE ST_Dimension(location) = 2
                  ORDER BY _drnk limit 10 ) r
ORDER BY intersects ASC, area ASC;
");

$same = 0;
$different = 0;
$count = 0;

foreach ($pcs as $pc) {
    if (!$sth->execute([
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat'],
        $pc['lng'],
        $pc['lat']
    ])) {
        var_dump($pgsql->errorInfo());
    }

    $pgareas = $sth->fetchAll();

    if (count($pgareas)) {
        $pgarea = $pgareas[0];

        if ($pc['areaname'] == $pgarea['name']) {
            $same++;
            if ($pc['newareaid'] !== $pc['areaid']) {
                $dbhm->preExec("UPDATE locations SET newareaid = ? WHERE id = ?", [
                    $pc['areaid'],
                    $pc['locationid']
                ]);
            }
        } else {
            echo("#{$pc['locationid']} {$pc['name']} {$pc['lat']}, {$pc['lng']} = {$pc['areaid']} {$pc['areaname']} => {$pgarea['locationid']} {$pgarea['name']}\n");
            foreach ($pgareas as $o) {
                echo("...{$o['name']} area {$o['area']} dist {$o['dist']} cdist {$o['cdist']} drnk {$o['_drnk']} inside {$o['inside']}, intersects: {$o['intersects']}\n");
            }

            $dbhm->preExec("UPDATE locations SET newareaid = ? WHERE id = ?", [
                $pgarea['locationid'],
                $pc['locationid']
            ]);

            $dbhm->preExec("INSERT INTO locations_dodgy (lat, lng, locationid, oldlocationid, newlocationid) VALUES (?, ?, ?, ?, ?)", [
                $pc['lat'],
                $pc['lng'],
                $pc['locationid'],
                $pc['areaid'],
                $pgarea['locationid']
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

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
                                    WHERE    area BETWEEN 0.00001 AND 0.15 limit 200 ) q
                  ORDER BY _drnk limit 20 ) r
ORDER BY dist LIMIT 20;
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
        # Exclude any areas which are significantly larger than most of the ones we found.  This is to handle
        # cases where there might be large areas on the map which cover a whole bunch of smaller areas.  In that
        # case we want to ignore the larger area, and map to one of the smaller ones.
        #
        # Also exclude ones which are significantly smaller.  These are likely to be things like schools rather
        # than areas.
        $medianArea = Utils::calculate_median(array_column($pgareas, 'area'));
        $found = count($pgareas);
        $originals = $pgareas;

        if ($medianArea) {
            $pgareas = array_filter($pgareas, function($a) use ($medianArea) {
                if ($a['area'] < $medianArea * 10 && $a['area'] > $medianArea / 10) {
//                    error_log("Keep {$a['name']} of size {$a['area']} vs $medianArea");
                    return TRUE;
                } else {
                    error_log("Remove {$a['name']} of size {$a['area']} vs $medianArea");
                    return FALSE;
                }
            });
        }

        if (count($pgareas) < $found) {
            error_log("Removed large areas, now have " . count($pgareas));
        }

        $inside = [];

        foreach ($pgareas as $pgarea) {
            if ($pgarea['inside']) {
                $inside[] = $pgarea;
            }
        }

        $pgarea = NULL;

        if (!count($inside)) {
            # If the postcode is not inside any areas, then we want the closest.
            error_log("Inside no areas - choose closest");
            $pgarea = array_shift($pgareas);
        } else if (count($inside) == 1) {
            # It's inside precisely one area, of a reasonable size.  That's the one we want.
            error_log("Inside just 1 - choose that");
            $pgarea = $inside[0];
        } else {
            # It's inside multiple areas, all of a reasonable size.  We want the smallest.
            error_log("Inside multiple - choose smallest.");
            array_multisort(array_column($inside, 'area'), SORT_ASC, $inside);
            $pgarea = array_shift($inside);
        }

        if ($pc['areaname'] == $pgarea['name']) {
            $same++;
        } else {
            echo("#{$pc['locationid']} {$pc['name']} {$pc['lat']}, {$pc['lng']} = {$pc['areaid']} {$pc['areaname']} => {$pgarea['locationid']} {$pgarea['name']}, median area $medianArea\n");
            foreach ($originals as $o) {
                echo("...{$o['name']} area {$o['area']} dist {$o['dist']} cdist {$o['cdist']} drnk {$o['_drnk']} inside {$o['inside']}\n");
            }

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

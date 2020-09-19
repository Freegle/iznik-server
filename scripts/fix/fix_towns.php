<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/misc/Location.php');

$l = new Location($dbhr, $dbhm);

$dbhm->preExec("UPDATE towns SET lat = NULL, lng = NULL, position = NULL;");

foreach ([1, 0] AS $osm) {
    error_log("--- Pass ---");
    $towns = $dbhr->preQuery("SELECT * FROM towns WHERE position IS NULL");

    foreach ($towns as $town) {
        $locs = $dbhr->preQuery("SELECT * FROM locations WHERE (name LIKE ? OR name LIKE ?) AND type IN ('Point', 'Polygon') AND osm_place = ?;", [
            $town['name'],
            str_replace(' ', '-', $town['name']),
            $osm
        ]);

        if (count($locs) == 1) {
            $loc = $locs[0];
            $dbhm->preExec("UPDATE towns SET lat = ?, lng = ?, position = GEOMFROMTEXT('POINT({$loc['lng']} {$loc['lat']})') WHERE id = ?;", [
                $loc['lat'],
                $loc['lng'],
                $town['id']
            ]);
        } else if (count($locs) > 1) {
            $ids = array_column($locs, 'id');
            #error_log("Duplicate for {$town['name']} " . json_encode($ids));
            $lat = 0;
            $lng = 0;

            foreach ($locs as $loc) {
                $lat += $loc['lat'];
                $lng += $loc['lng'];
            }

            $lat /= count($locs);
            $lng /= count($locs);

            $diff = PHP_INT_MAX;
            $bestlat = NULL;
            $bestlng = NULL;

            foreach ($locs as $loc) {
                $dist = abs($loc['lat'] - $lat) + abs($loc['lng'] - $lng);

                if ($dist < $diff) {
                    $bestlat = $loc['lat'];
                    $bestlng = $loc['lng'];
                }
            }

            #error_log("...chose $bestlat, $bestlng");
            $dbhm->preExec("UPDATE towns SET lat = ?, lng = ?, position = GEOMFROMTEXT('POINT($bestlng $bestlat)') WHERE id = ?;", [
                $bestlat,
                $bestlng,
                $town['id']
            ]);
        } else {
            error_log("None for {$town['name']} ");
        }
    }
}

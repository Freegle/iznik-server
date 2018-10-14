<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');

$opts = getopt('g:');

$groups = $dbhr->preQuery("SELECT id, region, nameshort FROM groups WHERE type = 'Freegle' AND publish = 1 AND onhere = 1 AND nameshort LIKE '%{$opts['g']}%' ORDER BY LOWER(nameshort) ASC;");
$l = new Location($dbhr, $dbhm);

foreach ($groups as $group) {
    error_log("...{$group['nameshort']}");
    $locs = $l->locsForGroup($group['id']);
    error_log("..." . count($locs) . " locations to check");

    $count = 0;

    foreach ($locs as $loc) {
        $isarea = $dbhr->preQuery("SELECT COUNT(*) AS count FROM locations WHERE areaid = ?;", [
            $loc['id']
        ]);

        if ($isarea[0]['count'] > 0) {
            $areas = $dbhr->preQuery("SELECT ST_AREA(COALESCE(ourgeometry, geometry)) AS area, COALESCE(ourgeometry, geometry) AS geom FROM locations LEFT JOIN locations_excluded ON locations.areaid = locations_excluded.locationid WHERE id = ? AND locations_excluded.locationid IS NULL;", [
                $loc['id']
            ], FALSE, FALSE);
            foreach ($areas as $area) {
                #error_log("...#{$loc['id']} {$loc['name']}, area {$area['area']}");

                if ($area['area'] < 0.001) {
                    # Small area.  Is it inside another one?
                    $overlaps = $dbhr->preQuery("SELECT locations_spatial.locationid, ST_AREA(geometry) AS area FROM locations_spatial LEFT JOIN locations_excluded ON locations_excluded.locationid = locations_spatial.locationid WHERE ST_Contains(geometry, ?) AND locations_spatial.locationid != ? AND locations_excluded.locationid IS NULL HAVING area < 0.001 AND area > 0.00001 ORDER BY ST_AREA(geometry) ASC LIMIT 1;", [
                        $area['geom'],
                        $loc['id']
                    ], FALSE, FALSE);

                    foreach ($overlaps as $overlap) {
                        $overlocs = $dbhr->preQuery("SELECT id, name FROM locations WHERE id = ?;", [
                            $overlap['locationid']
                        ], FALSE, FALSE);

                        foreach ($overlocs as $overloc) {
                            error_log("...hide #{$loc['id']} {$loc['name']}, area {$area['area']} as overlapped by #{$overloc['id']} {$overloc['name']}, area {$overlap['area']}");
                            $dbhm->preExec("INSERT IGNORE INTO locations_excluded (locationid, groupid) VALUES (?, ?);", [
                                $loc['id'],
                                $group['id']
                            ]);
                        }
                    }
                }
            }
        }

        $count++;
        if ($count % 1000 === 0) {
            error_log("...$count / " . count($locs));
        }
    }
}

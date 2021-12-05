<?php

# We have a list of UK postcodes in the DB, among other locations.  UK postcodes change fairly frequently.
#
# 1. Go to https://www.ordnancesurvey.co.uk/opendatadownload/products.html
# 2. Download Code-Point Open, in ZIP form
# 3. Unzip it somewhere
# 4. Run this script to process it.
#
# It will add any new postcodes to the DB.
# TODO Removal?

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/phpcoord.php');

$lockh = Utils::lockScript(basename(__FILE__));

$opts = getopt('f:');

if (count($opts) != 1) {
    echo "Usage: php doogal.php -f <CSV file>\n";
} else {
    $fn = Utils::presdef('f', $opts, NULL);

    if ($fn) {
        # We bypass the class for speed - this can take ages otherwise.
        error_log("Get locations");
        $locs = $dbhr->preQuery("SELECT id, name, lat, lng, type FROM locations WHERE LOCATE(' ', locations.name) > 0");
        error_log("Process locations");
        $locIndex = [];

        foreach ($locs as $loc) {
            $canon = strtolower(str_replace(' ', '', $loc['name']));
            $locIndex[$canon] = $loc;
        }

        error_log("Process CSV file");

        $l = new Location($dbhr, $dbhm);

        $fh = fopen($fn, 'r');

        if ($fh) {
            $added = 0;
            $failed = 0;
            $updated = 0;
            $count = 0;

            while (!feof($fh)) {
                # Format is:
                #
                # Postcode,In Use?,Latitude,Longitude,Easting,Northing,GridRef,County,District,Ward,DistrictCode,WardCode,Country,CountyCode,Constituency,Introduced,Terminated,Parish,NationalPark,Population,Households,Built up area,Built up sub-division,Lower layer super output area,Rural/urban,Region,Altitude,London zone,LSOA Code
                $fields = fgetcsv($fh);

                if ($fields) {
                    $count++;

                    if ($count % 1000 == 0) {
                        error_log("...$count");
                    }

                    if ($fields[1] == 'Yes') {
                        $pc = $fields[0];
                        $lat = $fields[2];
                        $lng = $fields[3];

                        if ($lat || $lng) {
                            $canon = strtolower(str_replace(' ', '', $pc));

                            if (array_key_exists($canon, $locIndex)) {
                                # We have the postcode.  Check the lat/lng in case they've changed.
                                #
                                # Ignore any differences in more than 2 decimal places, as this happens a lot and is a
                                # small actual difference.
                                $oldloc = $locIndex[$canon];
                                $newlat = round($lat, 2);
                                $newlng = round($lng, 2);
                                $oldlat = round($oldloc['lat'], 2);
                                $oldlng = round($oldloc['lng'], 2);

                                $l = new Location($dbhr, $dbhm, $oldloc['id']);

                                if ($newlat != $oldlat || $newlng != $oldlng || $oldloc['type'] != 'Postcode') {
                                    $dbhm->preExec("UPDATE locations SET lat = ?, lng = ?, type = ?, geometry = ST_GeomFromText('POINT($lng $lat)', {$dbhr->SRID()}), ourgeometry = NULL WHERE id = ?;", [
                                        $lat,
                                        $lng,
                                        'Postcode',
                                        $oldloc['id']
                                    ]);

                                    # Update the spatial index too.
                                    $dbhm->preExec(
                                        "REPLACE INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText('POINT($lng $lat)', {$dbhr->SRID()}));",
                                        [
                                            $oldloc['id']
                                        ]
                                    );

                                    error_log("...changed $pc {$oldloc['lat']} => $lat , {$oldloc['lng']} => $lng");
                                    $updated++;
                                }
                            } else {
                                # We don't have the postcode.  Create it.
                                $lid = $l->create(NULL, $pc, 'Postcode', "POINT($lng $lat)");

                                if ($lid) {
                                    error_log("...added $pc $lat, $lng");
                                    $added++;
                                } else {
                                    error_log("...failed to add $pc $lat, $lng");
                                    $failed++;
                                }
                            }
                        }
                    }
                }
            }

            fclose($fh);

            error_log("Added $added failed $failed updated $updated");
        }
    }
}

# Double-check that the location index is correct.
error_log("Check loc index");
$badlocs = $dbhr->preQuery("SELECT locations.id, ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE locations.geometry END) AS g FROM `locations` inner join locations_spatial on locations_spatial.locationid = locations.id where CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE locations.geometry END != locations_spatial.geometry");
error_log("Correct index for " . count($badlocs));

foreach ($badlocs as $badloc) {
    $dbhm->preExec("UPDATE locations_spatial SET geometry = ST_GeomFromText(?, {$dbhr->SRID()}) WHERE locationid = ?", [
        $badloc['g'],
        $badloc['id']
    ]);
}
Utils::unlockScript($lockh);
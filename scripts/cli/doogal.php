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
        $l = new Location($dbhr, $dbhm);

        $fh = fopen($fn, 'r');

        if ($fh) {
            $added = 0;
            $failed = 0;

            while (!feof($fh)) {
                # Format is:
                #
                # Postcode,In Use?,Latitude,Longitude,Easting,Northing,GridRef,County,District,Ward,DistrictCode,WardCode,Country,CountyCode,Constituency,Introduced,Terminated,Parish,NationalPark,Population,Households,Built up area,Built up sub-division,Lower layer super output area,Rural/urban,Region,Altitude,London zone,LSOA Code
                $fields = fgetcsv($fh);
                if ($fields[1] == 'Yes') {
                    $pc = $fields[0];
                    $lat = $fields[2];
                    $lng = $fields[3];
                    $lid = $l->findByName($pc);

                    if (!$lid) {
                        if ($lat || $lng) {
                            $lid = $l->create(NULL, $pc, 'Postcode', "POINT($lng $lat)");

                            if ($lid) {
                                error_log("...added $pc $lat, $lng");
                                $added++;
                            } else {
                                error_log("...failed to add $pc $lat, $lng");
                                $failed++;
                            }
                        }
                    } else {
                        $l = new Location($dbhr, $dbhm, $lid);

                        # Ignore any differences in more than 3 decimal places, as this happens a lot and is a very
                        # small actual difference.
                        $newlat = round($lat, 3);
                        $newlng = round($lng, 3);
                        $oldlat = round($l->getPrivate('lat'), 3);
                        $oldlng = round($l->getPrivate('lng'), 3);

                        if ($newlat != $oldlat || $newlng != $oldlng) {
                            error_log("...changed $pc " . $l->getPrivate('lat') . " => $lat , " . $l->getPrivate('lng') . " => $lng");
                            $l->setPrivate('lat', $lat);
                            $l->setPrivate('lng', $lng);
                            $l->setGeometry("POINT($lng $lat)");
                        }
                    }
                }
            }

            fclose($fh);

            error_log("Added $added failed $failed");
        }
    }
}

Utils::unlockScript($lockh);
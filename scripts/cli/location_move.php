<?php

# We have a list of UK postcodes in the DB, among other locations.  UK postcodes change fairly frequently.
#
# 1. Go to https://www.ordnancesurvey.co.uk/opendatadownload/products.html
# 2. Download Code-Point Open, in ZIP form
# 3. Unzip it somewhere
# 4. Run this script to process it.
#
# It will add any new postcodes to the DB.

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/phpcoord.php');

$opts = getopt('n:t:g:y:');

if (count($opts) != 4) {
    echo "Usage: php location_move -n <name> -t <lat> -g <lng> -y <type>\n";
} else {
    $name = Utils::presdef('n', $opts, NULL);
    $lat = Utils::presdef('t', $opts, NULL);
    $lng = Utils::presdef('g', $opts, NULL);
    $type = Utils::presdef('y', $opts, NULL);

    if ($name && $lat && $lng) {
        $l = new Location($dbhr, $dbhm);
        $lid = $l->findByName($name);
        $l = new Location($dbhr, $dbhm, $lid);

        if ($lid) {
            error_log("Location $name is $lid");
            $l->setGeometry('POINT(' . $lng . ' ' . $lat . ')');
            $l->setPrivate('lat', $lat);
            $l->setPrivate('lng', $lng);
            $l->setPrivate('type', $type);
        } else {
            error_log("Couldn't find $name");
        }
    } else {
        error_log("Invalid args");
    }
}

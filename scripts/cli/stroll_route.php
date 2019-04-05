<?php
# Create users for the emails in our Return Path seed list.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

$fh = fopen('/tmp/stroll.csv', 'r');

if ($fh) {
    $dbhm->preExec("DELETE FROM stroll_route WHERE 1");
    $lastlat = NULL;
    $lastlng = NULL;

    while (!feof($fh)) {
        $fields = fgetcsv($fh);
        $lat = $fields[1];
        $lng = $fields[0];

        if (is_numeric($lat) && is_numeric($lng)) {
            #error_log("Got point $lat, $lng");
            $dist = 0;

            if ($lastlat && $lastlng) {
                #error_log("Compare $lat, $lng, $lastlat, $lastlng");
                $dist = GreatCircle::getDistance($lat, $lng, $lastlat, $lastlng) / 1609.344;
            }

            $dbhm->preExec("INSERT INTO stroll_route (lat, lng, fromlast) VALUES (?, ?, ?)", [
                $lat,
                $lng,
                $dist
            ]);

            $lastlat = $lat;
            $lastlng = $lng;
        }
    }
}

<?php

# Given a WKT polygon, look for the users whose home location is within that polygon.  Then find where x% of them have
# they have received or given messages.  This helps us identify the areas which derive from the behaviour of the members.

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$opts = getopt('p:t:');

if (count($opts) != 2) {
    echo "Usage: php location_exchanges -p <WKT polygon> -t <% threshold>\n";
} else {
    $polygon = Utils::presdef('p', $opts, NULL);
    $threshold = Utils::presdef('t', $opts, NULL);

    if ($polygon && $threshold) {
        # Find the centre.
        $geom = \geoPHP::load($polygon, 'wkt');
        $centre = $geom->centroid();
        $clat = $centre->y();
        $clng = $centre->x();
        error_log("Centre is at $clat, $clng");

        # Find the locations of all offerers or wanters within the polygon.
        $sql = "SELECT visualise.*, messages.type FROM visualise INNER JOIN messages ON messages.id = visualise.msgid WHERE ST_CONTAINS(GeomFromText(?), POINT(fromlng, fromlat));";
        $points = $dbhr->preQuery($sql, [
            $polygon
        ]);
        error_log("Found " . count($points) . " active in area");

        # Find the other parties that they dealt with.
        $others = [];
        foreach ($points as $p) {
            $otheru = User::get($dbhr, $dbhm, $p['touser']);
            list ($tlat, $tlng, $loc) = $otheru->getLatLng(FALSE, FALSE, NULL);

            if ($tlat || $tlng) {
                $others[] = [ $tlat, $tlng ];
            }
        }

        error_log("Found " . count($others) . " people they dealt with");

        # Sort those in ascending order of distance from the centre.
        usort($others, function($a, $b) use ($clat, $clng, $dbhr, $dbhm) {
            $c = new POI($clat, $clng);

            $pa = new POI($a[0], $a[1]);
            $adist = $pa->getDistanceInMetersTo($c);
            $pb = new POI($b[0], $b[1]);
            $bdist = $pb->getDistanceInMetersTo($c);

            return ($adist - $bdist);
        });

        # Now find how far up the array we need to go to match the percentage.
        $upto = round(count($others) * $threshold / 100);

        # Now create the convex hull for that set.
        $g = new \geoPHP();
        $points = [];

        for ($i = 0; $i < $upto; $i++) {
            $pstr = "POINT({$others[$i][1]} {$others[$i][0]})";
            $points[] = $g::load($pstr);
        }

        $mp = new \MultiPoint($points);
        $hull = $mp->convexHull();
        $geom = $hull->asText();
        error_log("Hull $geom");
    }
}


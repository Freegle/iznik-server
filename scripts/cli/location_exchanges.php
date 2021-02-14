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

        # Find the groups which overlap this area.
        $groups = $dbhr->preQuery("SELECT id FROM groups WHERE ST_Intersects(polyindex, GeomFromText(?));", [
            $polygon
        ]);

        error_log("Found " . count($groups) . " group intersecting with this area");

        if (count($groups)) {
            $groupids = array_column($groups, 'id');
        } else {
            # TODO choose closest group.
            error_log("Area not within a group");
            $groupids = [];
        }

        if (count($groupids)) {
            # Find the members of those groups who are within the area.
            $members = $dbhr->preQuery("SELECT DISTINCT(userid) FROM memberships WHERE groupid IN (" . implode(',', $groupids) . ")");
            error_log("Found " . count($members) . " possible freeglers in the area");

            $freeglers = [];

            foreach ($members as $member) {
                $u = User::get($dbhr, $dbhm, $member['userid']);
                list ($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE, NULL);

                if (($lat || $lng)) {
                    # See if it's inside.
                    $g = new \geoPHP();
                    $pstr = "POINT($lng $lat)";
                    $point = $g::load($pstr, 'wkt');
                    if ($point->within($geom)) {
                        $freeglers[] = $member['userid'];
                    }
                }
            }

            error_log("Found " . count($freeglers) . " actually in the area");

            # Now find the locations of messages these freeglers replied to.
            $locs = [];

            foreach ($freeglers as $freegler) {
                $replies = $dbhr->preQuery("SELECT lat, lng FROM messages INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id WHERE chat_messages.userid = ? AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL", [
                    $freegler
                ]);

                foreach ($replies as $reply) {
                    $locs[] = [ $reply['lat'], $reply['lng'] ];
                }
            }

            # Sort those in ascending order of distance from the centre.
            usort($locs, function($a, $b) use ($clat, $clng, $dbhr, $dbhm) {
                $c = new POI($clat, $clng);

                $pa = new POI($a[0], $a[1]);
                $adist = $pa->getDistanceInMetersTo($c);
                $pb = new POI($b[0], $b[1]);
                $bdist = $pb->getDistanceInMetersTo($c);

                return ($adist - $bdist);
            });

            # Now find how far up the array we need to go to match the percentage.
            $upto = round(count($locs) * $threshold / 100);

            # Now create the convex hull for that set.
            $g = new \geoPHP();
            $points = [];

            for ($i = 0; $i < $upto; $i++) {
                $pstr = "POINT({$locs[$i][1]} {$locs[$i][0]})";
                $points[] = $g::load($pstr);
            }

            $mp = new \MultiPoint($points);
            $hull = $mp->convexHull();
            $geom = $hull->asText();
            error_log("Organic area $geom");
        }

//        # Find the messages within the polygon.
//        $sql = "SELECT id FROM messages WHERE ST_CONTAINS(GeomFromText(?), POINT(lng, lat));";
//        $points = $dbhr->preQuery($sql, [
//            $polygon
//        ]);
//        error_log("Found " . count($points) . " messages in area");
//
//        # Find the people who replied.
//        $others = [];
//        foreach ($points as $p) {
//            $replies = $dbhr->preQuery("SELECT userid FROM chat_messages WHERE regmsgid = ?", [
//                $p['id']
//            ]);
//
//            foreach ($replies as $reply) {
//                $otheru = User::get($dbhr, $dbhm, $reply['userid']);
//            }
//        }
//
//        error_log("Found " . count($others) . " people who replied");
//        $others = array_values($others);
//
    }
}


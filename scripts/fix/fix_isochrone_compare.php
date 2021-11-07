<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users INNER JOIN memberships m on users.id = m.userid WHERE users.added >= '2021-06-01' AND users.settings LIKE '%mylocation%' AND systemrole = ? ORDER BY users.added ASC LIMIT 1000;", [
    User::SYSTEMROLE_USER
]);

$failedIsochrone = 0;
$morePosts = 0;
$closerPosts = 0;
$total = 0;

const TRANS = Isochrone::DRIVE;
const MINS = 25;

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    list ($lat, $lng, $loc) = $u->getLatLng();
    $groups = $u->getMembershipGroupIds();

    $i = new Isochrone($dbhr, $dbhm);
    $isochrone = $i->find($user['userid'], TRANS, MINS);

    if (!$isochrone) {
        $isochrone = $i->create($user['userid'], TRANS, MINS);
    }

    if ($isochrone) {
        $i = new Isochrone($dbhr, $dbhm, $isochrone);

        # We currently store LINESTRING but we want to test on ST_Within which requires a polygon.
        $poly = str_replace('LINESTRING(', 'POLYGON((', $i->getPrivate('polygon'));
        $poly = str_replace(')', '))', $poly);

        # Find the posts within this isochrone.
        $isoWithin = $dbhr->preQuery("SELECT ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid FROM messages_spatial INNER JOIN isochrones ON ST_Within(messages_spatial.point, ST_GeomFromText(?, {$dbhr->SRID()})) AND isochrones.id = ?;", [
            $poly,
            $isochrone
        ]);
        $isoCount = count($isoWithin);
        
        $groupsWithin = $dbhr->preQuery("SELECT ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid FROM messages_spatial WHERE groupid IN (" . implode(',', $groups) . ")");
        $groupCount = count($groupsWithin);
        
        if ($isoCount && $groupCount) {
            $isoDist = 0;
            foreach ($isoWithin as $m) {
                $isoDist += \GreatCircle::getDistance($m['lat'], $m['lng'], $lat, $lng);
            }
            $isoDist = round($isoDist / $isoCount, 1);

            $groupDist = 0;
            foreach ($groupsWithin as $m) {
                $groupDist += \GreatCircle::getDistance($m['lat'], $m['lng'], $lat, $lng);
            }
            $groupDist = round($groupDist / $groupCount, 1);
        }

        error_log("{$user['userid']} on " . count($groups) . " groups isochrone $isochrone contains " . $isoCount . " vs " . $groupCount . " average $isoDist vs $groupDist");

        if ($isoCount > $groupCount) {
            $morePosts++;
        }

        if ($isoDist < $groupDist) {
            $closerPosts++;
        }

        $total++;
    } else {
        error_log("User {$user['userid']} at $lat, $lng failed isochrone.");
        $failedIsochrone++;
    }
}

error_log("\n\nScanned $total more posts " . round(100 * $morePosts / $total) . ", closer posts " . round(100 * $closerPosts / $total));

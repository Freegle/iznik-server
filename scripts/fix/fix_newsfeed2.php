<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/newsfeed/Newsfeed.php');

$n = new Newsfeed($dbhr, $dbhm);

$feeds = $dbhr->preQuery("SELECT id, userid, ST_Y(position) AS lat, ST_X(position) AS lng FROM newsfeed WHERE  ST_AsText(position) = 'POINT(-2.5209 53.945)';");

foreach ($feeds as $feed) {
    $u = new User($dbhr, $dbhm, $feed['userid']);
    list($lat, $lng, $loc) = $u->getLatLng();

    if($lat != $feed['lat'] || $lng != $feed['lng']) {
        error_log("{$feed['id']} {$feed['lat']}, {$feed['lng']} => $lat, $lng");
        $dbhm->preExec("UPDATE newsfeed SET position = ST_GeomFromText('POINT($lng $lat)') WHERE id = ?;", [
            $feed['id']
        ]);
    }
}
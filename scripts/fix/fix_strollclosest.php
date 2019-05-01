
<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$users = $dbhr->preQuery("SELECT users.id, locations.lat, locations.lng FROM users INNER JOIN locations ON locations.id = users.lastlocation WHERE lastlocation IS NOT NULL AND users.lastaccess > '2018-05-01';");

error_log(count($users) . " users");

$total = count($users);
$count = 0;

foreach ($users as $user) {
    try {
        #error_log("#{$user['id']} {$user['lat']}, {$user['lng']}");
        $sql = "SELECT MIN(ST_Distance_Sphere(GeomFromText(CONCAT('POINT(', stroll_route.lng , ' ', stroll_route.lat, ')')), POINT({$user['lng']}, {$user['lat']}))) AS dist FROM stroll_route;";
        $closests = $dbhr->preQuery($sql);
        foreach ($closests as $closest) {
            $dist = round($closest['dist'] / 1000 * 0.621371);
            #error_log("Closest for {$user['lat']}, {$user['lng']} dist $dist miles");

            if ($dist <= 15) {
                $dbhm->preExec("INSERT INTO stroll_close (userid, dist) VALUES ({$user['id']}, $dist)");
            }
        }
    } catch (Exception $e) {
        $count ++;

        if ($count % 1000 == 0) {
            error_log("...$count / $total");
        }
    }
}

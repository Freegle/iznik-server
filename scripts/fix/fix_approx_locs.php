<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT users.id, users.lastlocation FROM users INNER JOIN messages ON messages.fromuser = users.id WHERE lastlocation IS NOT NULL GROUP BY users.id;");
$count = 0;
$total = count($users);
error_log("Correct $total");
$l = new Location($dbhr, $dbhm);

foreach ($users as $user) {
    $msgs = $dbhr->preQuery("SELECT id, locationid, lat, lng FROM messages WHERE fromuser = ? AND type IN (?, ?) ORDER BY id DESC LIMIT 1;", [
        $user['id'],
        Message::TYPE_OFFER,
        Message::TYPE_WANTED
    ]);

    foreach ($msgs as $msg) {
        if ($msg['locationid'] && $msg['locationid'] != $user['lastlocation']) {
            error_log("Correct last location user #{$user['id']} old location #{$user['lastlocation']} => new location #{$msg['locationid']} from message #{$msg['id']} using locationid");
            $dbhm->preExec("UPDATE users SET lastlocation = ? WHERE id = ?;", [
                $msg['locationid'],
                $user['id']
            ]);
        } else if ($msg['lat'] || $msg['lng']) {
            $loc = $l->closestPostcode($msg['lat'], $msg['lng']);

            if ($loc && $loc['id'] != $user['lastlocation']) {
                error_log("Correct last location user #{$user['id']} old location #{$user['lastlocation']} => new location #{$loc['id']}  {$loc['name']} from message #{$msg['id']} using lat/lng");
                $dbhm->preExec("UPDATE users SET lastlocation = ? WHERE id = ?;", [
                    $loc['id'],
                    $user['id']
                ]);
            }
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...fix lastloc $count / $total");
    }
}

$mysqltime =  date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 24 * 60 * 60)));
$users = $dbhr->preQuery("SELECT DISTINCT users.id, users.lastaccess FROM users INNER JOIN memberships ON memberships.userid = users.id WHERE users.lastaccess >= '$mysqltime';");
$count = 0;
$total = count($users);

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['id']);

    # Pick up last

    # Get approximate location where we have one.
    list($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE, Utils::BLUR_USER);

    if ($lat || $lng) {
        # We found one.
        $dbhm->preExec("INSERT INTO users_approxlocs (userid, lat, lng, position, timestamp) VALUES (?, ?, ?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$dbhr->SRID()}), ?) ON DUPLICATE KEY UPDATE lat = ?, lng = ?, position = ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$dbhr->SRID()}), timestamp = ?;", [
            $user['id'],
            $lat,
            $lng,
            $lng,
            $lat,
            $user['lastaccess'],
            $lat,
            $lng,
            $lng,
            $lat,
            $user['lastaccess']
        ]);
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...set approx $count / $total");
    }
}

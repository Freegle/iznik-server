<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$opts = getopt('g:');

if (count($opts) < 1) {
    echo "Usage: php new_group_old_members.php\n";
} else {
    $g1 = new \geoPHP();

    $mysqltime =  date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 24 * 60 * 60)));
    $users = $dbhr->preQuery("SELECT DISTINCT users.id, users.lastaccess FROM users 
        INNER JOIN memberships ON memberships.userid = users.id
        LEFT JOIN users_banned ON users_banned.userid = users.id
        WHERE users.lastaccess >= ?
        AND users_banned.date IS NULL;", [
        $mysqltime
    ]);
    $count = 0;
    $found = 0;
    $already = 0;
    $total = count($users);
    $added = [];
    error_log("...$total users to scan");

    foreach ($users as $user) {
        $u = new User($dbhr, $dbhm, $user['id']);

        # Get location where we have one.
        list($lat, $lng, $loc) = $u->getLatLng(false, false, Utils::BLUR_NONE);

        if ($loc !== NULL) {
            #error_log("User #{$user['id']} at $loc");
            $l = new Location($dbhr, $dbhm);
            $locations = $l->typeahead($loc, 1, TRUE, TRUE);

            if (count($locations) && count($locations[0]['groupsnear'])) {
                $gid = $locations[0]['groupsnear'][0]['id'];
                $gname = $locations[0]['groupsnear'][0]['nameshort'];

                #error_log("...home group $gname");
                $membs = $u->getMembershipGroupIds();

                if (count($membs)) {
                    if (in_array($gid, $membs)) {
                        #error_log("...already a member");
                        $already++;
                    } else {
                        $freq = $u->getMembershipAtt($membs[0], 'emailfrequency');
                        $freq = $freq ? $freq : 0;
                        error_log("...add #{$user['id']} at $loc to $gname with freq $freq");

                        if (!array_key_exists($gname, $added)) {
                            $added[$gname] = 0;
                        }

                        $added[$gname]++;

//                    $u->addMembership($gid);
//                    $u->setMembershipAtt($gid, 'emailfrequency', $freq);
                    }
                }

                $found++;
            }
        }

        $count++;

        if ($count % 1000 == 0) {
            error_log("...$count / $total");
        }
    }

    error_log("\nFound added $found users, already $already");

    arsort($added);

    foreach ($added as $gname => $count) {
        error_log("$gname: $count");
    }
}
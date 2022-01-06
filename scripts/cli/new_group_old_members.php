<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$opts = getopt('g:');

if (count($opts) < 1) {
    echo "Usage: php new_group_old_members.php -g <group id>\n";
} else {
    $gid = $opts['g'];
    $g = new Group($dbhr, $dbhm, $gid);

    $g1 = new \geoPHP();
    $grouppoly = $g1::load($g->getPrivate('polyofficial'), 'wkt');

    error_log("Check group " . $g->getName());

    $mysqltime =  date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 24 * 60 * 60)));
    $users = $dbhr->preQuery("SELECT DISTINCT users.id, users.lastaccess FROM users 
    INNER JOIN memberships ON memberships.userid = users.id WHERE users.lastaccess >= '$mysqltime';");
    $count = 0;
    $found = 0;
    $already = 0;
    $total = count($users);
    error_log("...$total users to scan");

    foreach ($users as $user) {
        $u = new User($dbhr, $dbhm, $user['id']);

        # Get location where we have one.
        list($lat, $lng, $loc) = $u->getLatLng(false, false, Utils::BLUR_NONE);

        $g2 = new \geoPHP();
        $userloc = $g2::load("POINT($lng $lat)", 'wkt');

        if ($grouppoly->contains($userloc)) {
            error_log("User #{$user['id']}");
            $u = new User($dbhr, $dbhm, $user['id']);
            $membs = $u->getMembershipGroupIds();

            if (count($membs)) {
                if (in_array($gid, $membs)) {
                    error_log("...already a member");
                    $already++;
                } else {
                    $freq = $u->getMembershipAtt($membs[0], 'emailfrequency');
                    error_log("...add with freq $freq");
                    $u->addMembership($gid);
                    $u->setMembershipAtt($gid, 'emailfrequency', $freq);
                }
            }

            $found++;
        }

        $count++;

        if ($count % 1000 == 0) {
            error_log("...$count / $total");
        }
    }

    error_log("\nFound added $found users, already $already");
}
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:g:r:');

if (count($opts) < 3) {
    echo "Usage: php user_add_membership.php -e <email of user> -g <name of group> -r <Role>\n";
} else {
    $email = $opts['e'];
    $name = $opts['g'];
    $role = Utils::presdef('r', $opts, 'Member');
    $u = User::get($dbhr, $dbhm);
    $uid = $u->findByEmail($email);

    if ($uid) {
        $u = User::get($dbhr, $dbhm, $uid);
        error_log("Found user #$uid");

        $g = new Group($dbhr, $dbhm);
        $gid = $g->findByShortName($name);

        if ($gid) {
            $u->addMembership($gid, $role);
            error_log("Added membership of #$gid");
        }
    }
}

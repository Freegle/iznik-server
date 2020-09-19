<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:e:r:');

if (count($opts) < 3) {
    echo "Usage: php group_welcome -g <shortname of source group> -e <email> -r review\n";
} else {
    $group = $opts['g'];
    $email = $opts['e'];
    $review = $opts['r'];

    $g = Group::get($dbhr, $dbhm);
    $gid = $g->findByShortName($group);
    $g = Group::get($dbhr, $dbhm, $gid);

    $u = new User($dbhr, $dbhm);
    $uid = $u->findByEmail($email);
    $u = new User($dbhr, $dbhm, $uid);

    $u->sendWelcome($g->getPrivate('welcomemail'), $gid, NULL, NULL, $review);
}

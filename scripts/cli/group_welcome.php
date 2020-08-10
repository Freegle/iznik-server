<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$opts = getopt('g:e:');

if (count($opts) < 2) {
    echo "Usage: php group_welcome -g <shortname of source group> -e <email>\n";
} else {
    $group = $opts['g'];
    $email = $opts['e'];

    $g = Group::get($dbhr, $dbhm);
    $gid = $g->findByShortName($group);
    $g = Group::get($dbhr, $dbhm, $gid);

    $u = new User($dbhr, $dbhm);
    $uid = $u->findByEmail($email);
    $u = new User($dbhr, $dbhm, $uid);

    $u->sendWelcome($g->getPrivate('welcomemail'), $gid, NULL, NULL, TRUE);
}

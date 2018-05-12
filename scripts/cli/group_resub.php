<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$opts = getopt('n:i:');

if (count($opts) < 1) {
    echo "Usage: hhvm group_resub.php -n <shortname of group> <-i user id> \n";
} else {
    $name = $opts['n'];
    $uid = presdef('i', $opts, NULL);
    $g = Group::get($dbhr, $dbhm);
    $id = $g->findByShortName($name);

    if ($id) {
        error_log("Found group $id");
        $idq = $uid ? " AND userid = $uid " : '';

        $users = $dbhr->preQuery("SELECT DISTINCT userid FROM memberships WHERE groupid = ? $idq;", [ $id ]);

        foreach ($users as $user) {
            $u = new User($dbhr, $dbhm, $user['userid']);
            $u->triggerYahooApplication($id);
        }
    }
}

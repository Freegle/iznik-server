<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$opts = getopt('g:u:');

if (count($opts) < 1) {
    echo "Usage: hhvm user_kudos.php -g <gid> -u 0|1\n";
} else {
    $gid = $opts['g'];
    $update = $opts['u'];

    $g = new Group($dbhr, $dbhm, $gid);
    $u = User::get($dbhr, $dbhm);

    if ($update) {
        $members = $g->getMembers(PHP_INT_MAX);
        error_log("Got " . count($members));

        $count = 0;

        foreach ($members as $member) {
            $u->updateKudos($member['userid']);

            $count++;

            if ($count % 1000 === 0) {
                error_log("...$count");
            }
        }
    }

    $tops = $u->possibleMods($gid);

    foreach ($tops as $top) {
        $user = $top['user'];
        $kudos = $top['kudos'];
        error_log("#{$user['id']} {$user['displayname']} ({$user['email']}): {$kudos['kudos']} " . ($user['systemrole'] == User::ROLE_MODERATOR ? ' mod but not on this group' : ''));
    }
}

<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$opts = getopt('e:i:');

if (count($opts) < 1) {
    echo "Usage: php user_thank.php -e <email> or -i <user id>\n";
} else {
    $uid = presdef('i', $opts, NULL);
    $find = presdef('e', $opts, NULL);
    $u = User::get($dbhr, $dbhm);
    $uid = $uid ? $uid : $u->findByEmail($find);

    if ($uid) {
        error_log("Found user $uid");
        $u = User::get($dbhr, $dbhm, $uid);
        $u->thankDonation();
    } else {
        error_log("Couldn't find user for $find");
    }
}

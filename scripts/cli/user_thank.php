<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:i:');

if (count($opts) < 1) {
    echo "Usage: php user_thank.php -e <email> or -i <user id>\n";
} else {
    $uid = Utils::presdef('i', $opts, NULL);
    $find = Utils::presdef('e', $opts, NULL);
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

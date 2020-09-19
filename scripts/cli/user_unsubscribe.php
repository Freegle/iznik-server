<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:i:');

if (count($opts) < 1) {
    echo "Usage: php user_unsubscribe.php -i <user id> -g <group id>\n";
} else {
    $uid = $opts['i'];
    $gid = $opts['g'];

    $u = new User($dbhr, $dbhm, $uid);
    if ($u->getId() == $uid) {
        $u->removeMembership($gid);
    }
}

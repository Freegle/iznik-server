<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:t:');

if (count($opts) < 1) {
    echo "Usage: php chat_check_banned_in_common.php -f <from user id> -t <to user id>\n";
} else {
    $r = new ChatRoom($dbhr, $dbhm);

    if ($r->bannedInCommon($opts['f'], $opts['t'])) {
        error_log("Chat blocked");
    } else {
        error_log("Chat OK");
    }
}

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:t:r:');

if (count($opts) < 1) {
    echo "Usage: php user_merge.php -f <id of user to merge from> -t <id of user to merge into> -r <reason>\n";
} else {
    $from = $opts['f'];
    $to = $opts['t'];
    $reason = $opts['r'];
    $u = User::get($dbhr, $dbhm);
    $u->merge($to, $from, $reason, TRUE);
}

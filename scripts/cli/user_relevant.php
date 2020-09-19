<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php user_relevant.php -i <user id>\n";
} else {
    $id = $opts['i'];
    $r = new Relevant($dbhr, $dbhm);
    $ints = $r->interestedIn($id);

    error_log("Found " . count($ints));

    foreach ($ints as $int) {
        error_log("  Type {$int['type']} Item {$int['item']} Because {$int['reason']}");
    }
}

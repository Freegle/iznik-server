<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('h:');

if (count($opts) < 1) {
    echo "Usage: php dbdown.php -h <1-3>\n";
} else {
    $host = "db{$opts['h']}-internal";

    while (TRUE) {
        touch("/tmp/iznik.dbstatus.$host:3306.down");
        sleep(10);
    }
}
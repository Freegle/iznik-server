<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use GeoIp2\Database\Reader;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php geopip.php -i <IP\n";
} else {
    $ip = $opts['i'];

    $reader = new Reader(MMDB);
    $record = $reader->country($ip);

    error_log("Returned " . var_export($record, TRUE));
}

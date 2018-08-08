<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

use GeoIp2\Database\Reader;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php geopip.php -i <IP\n";
} else {
    $ip = $opts['i'];

    $reader = new Reader('/usr/share/GeoIP/GeoLite2-Country.mmdb');
    $record = $reader->country($ip);

    error_log("Returned " . var_export($record, TRUE));
}

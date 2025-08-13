<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$ips = [];

$logs = $dbhr->preQuery("SELECT request, ip FROM logs_api");

foreach ($logs as $log) {
    $request = json_decode($log['request'], TRUE);
    $headers = Utils::presdef('headers', $request, []);
    $ua = Utils::pres('User-Agent', $headers);
    $ip = $log['ip'];

    if (!$ua) {
        if (!Utils::pres($log['ip'], $ips)) {
            $ips[$ip] = 1;
        } else {
            $ips[$ip]++;
        }
    }
}

foreach ($ips as $ip => $count) {
    if ($count > 10) {
        touch("/tmp/noheaders-$ip");
    }
}

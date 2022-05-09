<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$locations = $dbhr->preQuery("SELECT DISTINCT locations.id,lat,lng,name FROM locations INNER JOIN users ON users.lastlocation = locations.id WHERE lat < 30 and lng > 30 AND locations.name != 'BF1 3AD';");

$str = '';

foreach ($locations as $location) {
    $str .= "{$location['id']}, {$location['name']}, {$location['lat']}, {$location['lng']}\n";
//    $dbhm->preExec("UPDATE locations SET lat = ?, lng = ? WHERE id = ?", [
//        $location['lng'],
//        $location['lat'],
//        $location['id']
//    ]);
}

if (count($locations) > 0) {
    $headers = 'From: geeks@ilovefreegle.org';
    mail('geek-alerts@ilovefreegle.org', count($locations) . " locations lat/lngs skewwhiff", $str, $headers);
} else {
    error_log("All locations ok");
}

Utils::unlockScript($lockh);
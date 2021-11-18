<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('l:');

if (count($opts) < 1) {
    echo "Usage: php location_tester.php -l <location name></location>\n";
} else {
    $name = Utils::presdef('l', $opts, NULL);
    $l = new Location($dbhr, $dbhm);
    $ret = [ 'ret' => 0, 'status' => 'Success', 'locations' => $l->typeahead($name, 1, TRUE, TRUE) ];
    error_log("Group " . $ret['locations'][0]['groupsnear'][0]['nameshort'] . " area " . $ret['locations'][0]['area']['name']);

    var_dump($ret);
}

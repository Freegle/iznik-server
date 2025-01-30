<?php

// Usage: php authority_create.php -n <name> -p <polyfilename> -a <areacode>
// php authority_create.php -n "NorthEngland" -p "combined.txt"
// polyfilename has POLYGON or MULTIPOLYGON on line one only

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$opts = getopt('n:p:a');

$name = $opts['n'];
$poly = $opts['p'];
$poly = file($poly)[0];
$area_code = $opts['a'];

$l = new Authority($dbhr, $dbhm);

$l->create($name, $area_code, $poly);
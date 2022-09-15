<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$opts = getopt('n:p:a');

$name = $opts['n'];
$poly = $opts['p'];
$area_code = $opts['a'];

$l = new Authority($dbhr, $dbhm);

$l->create($name, $area_code, $poly);
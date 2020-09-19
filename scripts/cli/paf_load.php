<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is for the initial load of the PAF file.  Don't run it for updates.

$opts = getopt('i:o:');

if (count($opts) < 1) {
    echo "Usage: php paf_load.php -i input PAF CSV filename -o output data CSV file prefix\n";
} else {
    $p = new PAF($dbhr, $dbhm);
    $p->load($opts['i'], $opts['o']);
}

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is for updates to the PAF file.  Don't run it for the initial load - it's too slow.

$opts = getopt('i:o:');

if (count($opts) < 1) {
    echo "Usage: php paf_update.php -i input PAF CSV filename\n";
} else {
    $p = new PAF($dbhr, $dbhm);
    $p->update($opts['i']);
}

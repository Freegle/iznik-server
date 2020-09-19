<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('a:i:f:');

if (count($opts) < 2) {
    echo "Usage: php modconfig.php -a (export|import) -i <id to export> -f <input or output file>\n";
} else {
    $a = $opts['a'];
    $i = Utils::presdef('i', $opts, NULL);
    $f = $opts['f'];
    $c = new ModConfig($dbhr, $dbhm);

    if ($a == 'export') {
        $c = new ModConfig($dbhr, $dbhm, $i);
        file_put_contents($f, $c->export());
    } else if ($a == 'import') {
        error_log("Created " . $c->import(file_get_contents($f)));
    }
}

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

const CLEAN = FALSE;



$c = new Catalogue($dbhr, $dbhm);

$opts = getopt('a:');

if (count($opts) < 1) {
    echo "Usage: php booktastic_search.php -a <author name>\n";
} else {
    $c->ISBNDB($opts['a']);
}
<?php

const CLEAN = FALSE;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/booktastic/Catalogue.php');

$c = new Catalogue($dbhr, $dbhm);

$opts = getopt('a:');

if (count($opts) < 1) {
    echo "Usage: php booktastic_search.php -a <author name>\n";
} else {
    $c->ISBNDB($opts['a']);
}
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

// Parse command line arguments
$feedUrl = REACH_FEED;
$useNewFieldNames = FALSE;

if ($argc > 1) {
    $feedUrl = $argv[1];
}

if ($argc > 2) {
    $useNewFieldNames = ($argv[2] === 'new');
}

$lockh = Utils::lockScript(basename(__FILE__));

$r = new ReachVolunteering($dbhr, $dbhm, $useNewFieldNames);
$r->processFeed($feedUrl);

Utils::unlockScript($lockh);

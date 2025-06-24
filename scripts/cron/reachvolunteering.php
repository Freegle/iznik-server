<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$r = new ReachVolunteering($dbhr, $dbhm);
$r->processFeed(REACH_FEED);

Utils::unlockScript($lockh);

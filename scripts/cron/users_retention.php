<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

error_log("Start at " . date("Y-m-d H:i:s"));

$lockh = Utils::lockScript(basename(__FILE__));

$u = new User($dbhr, $dbhm);
$u->userRetention();

error_log("Finish at " . date("Y-m-d H:i:s"));

Utils::unlockScript($lockh);

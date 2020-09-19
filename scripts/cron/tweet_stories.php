<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

error_log("Start at " . date("Y-m-d H:i:s"));

$t = new Twitter($dbhr, $dbhm, NULL);
$t->tweetStory();

error_log("Finish at " . date("Y-m-d H:i:s"));

Utils::unlockScript($lockh);
<?php

define('SQLLOG', FALSE);

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

global $dbhr, $dbhm;

error_log("Start at " . date("Y-m-d H:i:s"));

$lockh = lockScript(basename(__FILE__));

$u = new User($dbhr, $dbhm);
$u->userRetention();

error_log("Finish at " . date("Y-m-d H:i:s"));

unlockScript($lockh);

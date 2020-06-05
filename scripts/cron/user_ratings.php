<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$u = new User($dbhr, $dbhm);
$u->ratingVisibility();
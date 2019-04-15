<?php
# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

error_log("Start at " . date("Y-m-d H:i:s"));

$n = new Notifications($dbhr, $dbhm);

$count = $n->sendEmails(NULL, '30 minutes ago', '24 hours ago');
error_log("Send $count notification chaseups");

error_log("Finish at " . date("Y-m-d H:i:s"));

unlockScript($lockh);
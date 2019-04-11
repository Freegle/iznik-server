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

$n = new Notifications($dbhr, $dbhm);

$count = $n->sendEmails(NULL, 'half an hour ago', '24 hours ago');
error_log("Send $count notification chaseups");

unlockScript($lockh);
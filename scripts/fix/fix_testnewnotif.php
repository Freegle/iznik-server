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

$u = new User($dbhr, $dbhm);
$uid = $u->findByEmail('edward@ehibbert.org.uk');

$count = $n->sendEmails($uid, '24 hours ago', '30 days ago', FALSE);
error_log("Send $count notification chaseups");

unlockScript($lockh);
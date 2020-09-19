<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

error_log("Start at " . date("Y-m-d H:i:s"));

$n = new Notifications($dbhr, $dbhm);

$count = $n->sendEmails(NULL, '30 minutes ago', '24 hours ago');
error_log("Send $count notification chaseups");

error_log("Finish at " . date("Y-m-d H:i:s"));

Utils::unlockScript($lockh);
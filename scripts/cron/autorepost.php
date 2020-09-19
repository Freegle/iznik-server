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

$m = new Message($dbhr, $dbhm);
$mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
list($count, $warncount)  = $m->autoRepostGroup(Group::GROUP_FREEGLE, $mysqltime);

error_log("Sent $count reposts and $warncount warnings");

Utils::unlockScript($lockh);
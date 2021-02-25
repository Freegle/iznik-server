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
$mysqltime = date("Y-m-d", max(strtotime("06-sep-2016"), strtotime("Midnight " . Message::EXPIRE_TIME . " days ago")));
$count = $m->tidyOutcomes('2001-01-01');
error_log("Tidied $count outcomes");
$count = $m->processIntendedOutcomes();
error_log("Processed $count intended");
$m->notifyLanguishing();
$count = $m->chaseUp(Group::GROUP_FREEGLE, $mysqltime);
error_log("Sent $count chaseups");

Utils::unlockScript($lockh);
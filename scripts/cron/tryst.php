<?php

# Send test digest to a specific user
namespace Freegle\Iznik;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$t = new Tryst($dbhr, $dbhm);
$t->sendCalendarsDue();
$t->sendRemindersDue();

Utils::unlockScript($lockh);
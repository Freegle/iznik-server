<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Process incoming bounces into bounces_emails
$lockh = Utils::lockScript(basename(__FILE__));

$b = new Bounce($dbhr, $dbhm);
$b->process();

Utils::unlockScript($lockh);
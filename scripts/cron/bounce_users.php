<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Mark users as bouncing
$lockh = Utils::lockScript(basename(__FILE__));

$b = new Bounce($dbhr, $dbhm);
$b->suspendMail();

Utils::unlockScript($lockh);
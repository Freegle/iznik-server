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

$s = new Story($dbhr, $dbhm);
$count = $s->sendToCentral();
error_log("Sent $count stories");
Utils::unlockScript($lockh);
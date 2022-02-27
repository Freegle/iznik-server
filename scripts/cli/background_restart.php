<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Pheanstalk\Pheanstalk;

$lockh = Utils::lockScript(basename(__FILE__));

# For gracefully restarting the background processing.
touch('/tmp/iznik.background.abort');
sleep(30);
unlink('/tmp/iznik.background.abort');

Utils::unlockScript($lockh);
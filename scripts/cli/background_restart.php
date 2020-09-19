<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Pheanstalk\Pheanstalk;

# For gracefully restarting the background processing; signal to it.
$pheanstalk = new Pheanstalk(PHEANSTALK_SERVER);

$id = $pheanstalk->put(json_encode(array(
    'type' => 'exit'
)));

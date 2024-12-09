<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$s = new Story($dbhr, $dbhm);
$s->askForStories('2017-01-01', 43279009, -1, -1, NULL, TRUE);
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$id = $opts['i'];

$r = new ChatRoom($dbhr, $dbhm, $id);
$r->updateMessageCounts();

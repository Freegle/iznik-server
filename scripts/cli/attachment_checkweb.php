<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$id = $opts['i'];

$a = new Attachment($dbhr, $dbhm, $id);
$url = $a->findWebReferences();
error_log("Found $url");

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php message_route.php -i <id of message>\n";
} else {
    $id = $opts['i'];
    $r = new MailRouter($dbhr, $dbhm);
    $m = new Message($dbhr, $dbhm, $id);
    $r->route($m);
}

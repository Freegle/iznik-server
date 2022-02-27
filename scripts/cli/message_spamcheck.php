<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

use spamc;

if (!class_exists('spamc')) {
    require_once(IZNIK_BASE . '/lib/spamc.php');
}

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php message_spamcheck.php -i <id of message>\n";
} else {
    $id = $opts['i'];
    $m = new Message($dbhr, $dbhm, $id);

    $s = new spamc;
    $s->command = 'CHECK';
    $s->filter($m->getMessage());
    var_dump($s->result);

    $s = new Spam($dbhr, $dbhm);
    $ret = $s->checkMessage($m);
    var_dump($ret);
}

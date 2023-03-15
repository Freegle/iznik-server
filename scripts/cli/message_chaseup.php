<?php


namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php message_chaseup.php -i <id of message>\n";
} else {
    $id = $opts['i'];
    $m = new Message($dbhr, $dbhm, $id);
    $groupid = $m->getPublic()['groups'][0]['groupid'];
    $mysqltime = date("Y-m-d", strtotime("06-sep-2016"));
    error_log("Chaseup $id on $groupid");
    $m->chaseUp(Group::GROUP_FREEGLE, $mysqltime, $groupid, $id);
}

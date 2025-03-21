<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php message_repost.php -i <id of message>\n";
} else {
    $id = $opts['i'];
    $m = new Message($dbhr, $dbhm, $id);

    $groupid = $m->getPublic()['groups'][0]['groupid'];

    if ($groupid) {
        $u = User::get($dbhr, $dbhm, $m->getFromuser());
        $m->setPrivate('textbody', $m->stripGumf());
        $m->constructSubject($groupid);
        $m->submit($u, $m->getFromaddr(), $groupid);
        error_log("Reposted");
    }
}

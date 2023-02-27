<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT id from messages where arrival >= '2023-02-10' and subject like '%Membership Request' and lastroute = 'IncomingSpam';");

foreach ($msgs as $msg) {
    $msgid = $msg['id'];

    # Dispatch the message on its way.
    $r = new MailRouter($dbhr, $dbhm);
    $m = new Message($dbhr, $dbhm, $msgid);

    if ($m->getID() == $msgid) {
        $r->route($m);
    }
}

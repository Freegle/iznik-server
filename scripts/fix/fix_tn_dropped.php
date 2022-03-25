<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$r = new MailRouter($dbhr, $dbhm);
list ($id, $failok) = $r->received(Message::EMAIL, 'sj88ok-g4983@user.trashnothing.com', 'notify-12017336-40979534@users.ilovefreegle.org', file_get_contents('/tmp/a.c'));

if ($id) {
    $rc = $r->route();
    error_log("Routed $id to $rc");
}

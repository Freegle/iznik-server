<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$u = User::get($dbhr, $dbhm);

$messages = $dbhr->preQuery("SELECT id, fromuser, fromaddr FROM messages");

$count = 0;

foreach ($messages as $message) {
    $uid = $u->findByEmail($message['fromaddr']);
    if ($uid != $message['fromuser']) {
        $dbhm->preExec("UPDATE messages SET fromuser = ? WHERE id = ?;", [ $uid, $message['id']]);
    }

    $count++;

    if ($count % 100 == 0) {
        error_log("...$count");
    }
}

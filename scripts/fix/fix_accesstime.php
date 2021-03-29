<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhm, $dbhm);

$users = $dbhr->preQuery("SELECT DISTINCT(userid) FROM users INNER JOIN chat_messages ON chat_messages.userid = users.id WHERE users.lastaccess < chat_messages.date");

error_log("Got " . count($users));

$count = 0;

foreach ($users as $user) {
    $chats = $dbhr->preQuery("SELECT MAX(date) AS max FROM chat_messages WHERE userid = ?;", [
        $user['userid']
    ]);

    foreach ($chats as $chat) {
        $dbhm->preExec("UPDATE users SET lastaccess = ? WHERE id = ?;", [
            $chat['max'],
            $user['userid']
        ]);
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count");
    }
}
<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhm, $dbhm);

# This is a fallback script for setting lastaccess.  Look for recent chat messages or recent memberships.
$users = $dbhr->preQuery("SELECT DISTINCT(userid) FROM (SELECT DISTINCT(userid) FROM users 
    INNER JOIN chat_messages ON chat_messages.userid = users.id 
    WHERE users.lastaccess < chat_messages.date AND TIMESTAMPDIFF(SECOND, users.lastaccess, chat_messages.date) > 600
    UNION
    SELECT DISTINCT(userid) FROM memberships
    INNER JOIN users ON users.id = memberships.userid
    WHERE TIMESTAMPDIFF(SECOND, users.lastaccess, memberships.added) > 600) t;");

error_log("Got " . count($users));

$count = 0;
$real = 0;

foreach ($users as $user) {
    $chats = $dbhr->preQuery("SELECT GREATEST(
(SELECT MAX(date) AS date FROM chat_messages WHERE userid = ?), 
(SELECT MAX(added) AS added FROM memberships WHERE userid = ?)
    ) AS max;", [
        $user['userid'],
        $user['userid']
    ]);

    foreach ($chats as $chat) {
        $u = new User($dbhr, $dbhm, $user['userid']);
        $diff = strtotime($chat['max']) - strtotime($u->getPrivate('lastaccess'));

        error_log("Diff is $diff");

        if ($diff > 600) {
            $dbhm->preExec("UPDATE users SET lastaccess = ? WHERE id = ?;", [
                $chat['max'],
                $user['userid']
            ]);

            $real++;
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count");
    }
}

error_log("Real ones $real");
<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');


$chats = $dbhr->preQuery("SELECT count(*) as count, chatid FROM chat_roster inner join chat_rooms on chat_rooms.id = chat_roster.chatid and chat_rooms.chattype = 'User2User' group by chatid having count > 2;");
foreach ($chats as $chat) {
    $rooms = $dbhr->preQuery("SELECT * FROM chat_rooms WHERE id = ?;", [
        $chat['chatid']
    ]);

    foreach ($rooms as $r) {
        $interlopers = $dbhr->preQuery("SELECT userid FROM chat_roster WHERE chatid = ? AND userid != ? AND userid != ?;", [
            $r['id'],
            $r['user1'],
            $r['user2']
        ]);

        foreach ($interlopers as $i) {
            error_log("{$i['userid']} on {$r['id']}");
            $dbhm->preExec("DELETE FROM chat_roster WHERE chatid = ? AND userid = ?;", [
                $r['id'],
                $i['userid']
            ]);
        }
    }
}
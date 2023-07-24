<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is a fallback, so it's only run occasionally through cron.
#
# Ensure the counts are correct in the chat_room.  This is no longer relevant to FD because of the Go API, but
# is relevant to MT.
$mysqltime = date("Y-m-d", strtotime("31 days ago"));
$chatids = $dbhr->preQuery("SELECT DISTINCT chatid FROM chat_messages WHERE date >= ?;", [ $mysqltime ]);

$total = count($chatids);
$count = 0;

foreach ($chatids as $chatid) {
    $r = new ChatRoom($dbhr, $dbhm, $chatid['chatid']);
    $r->updateMessageCounts();

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

# If there are any User2Mod chats which have been closed by the user but which have unseen messages, then we
# need to reopen them.  This shouldn't arise very often as we hide the close button if there are unseen messages,
# and reopen them when we create a message.  But we want to make very sure that messages from mods are seen.
$chats = $dbhr->preQuery("SELECT DISTINCT chat_rooms.id, chat_roster.status, chat_roster.lastmsgseen, chat_rooms.user1 
    FROM `chat_rooms` 
    INNER JOIN chat_roster ON chat_roster.chatid = chat_rooms.id 
    WHERE chat_rooms.user1 = chat_roster.userid 
        AND chat_roster.status = ?
        AND chattype = ?
        AND chat_rooms.latestmessage > chat_roster.date 
        AND chat_rooms.latestmessage >= ?;", [ ChatRoom::STATUS_CLOSED, ChatRoom::TYPE_USER2MOD, $mysqltime ]);

error_log("Found chats which need reopening " . count($chats));

foreach ($chats as $chat) {
    $dbhm->preExec("UPDATE chat_roster SET status = ? WHERE chatid = ? AND userid = ?;", [
        ChatRoom::STATUS_AWAY,
        $chat['id'],
        $chat['user1']
    ]);
}
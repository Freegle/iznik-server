<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$chats = $dbhr->preQuery("SELECT DISTINCT chat_messages.id, chatid, userid, user1, user2 FROM chat_messages INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid WHERE date >= '2023-06-01' AND chat_messages.type = ? LIMIT 1;", [
    ChatMessage::TYPE_PROMISED
]);

error_log("Found " . count($chats));

$r = new ChatRoom($dbhr, $dbhm);

foreach ($chats as $chat) {
    $promisedby = $chat['userid'];
    $offerer = User::get($dbhr, $dbhm, $promisedby);
    $responder = User::get($dbhr, $dbhm, $chat['userid'] == $chat['user1'] ? $chat['user2'] : $chat['user1']);
    $text = "This is a conversation between two people, A and B.  A has offered an item, and B is asking for it.  Please
    read the conversation and decide whether A has agreed to give the item to B.  Answer Yes or No first, and then
    explain the reasoning.\n\n";

    $msgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE chatid = ? AND id > ? ORDER BY id ASC", [
        $chat['chatid'],
        $chat['id']
    ]);

    foreach ($msgs as $msg) {
        if ($msg['userid'] == $promisedby) {
            $text .= "A: ";
        } else {
            $text .= "B: ";
        }

        $s = "";
        $text .= $r->getTextSummary($msg, $offerer, $responder, 0, $s) . "\n\n";
    }

    error_log($text);
}
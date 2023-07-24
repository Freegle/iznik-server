<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$chats = $dbhr->preQuery("SELECT DISTINCT chat_messages.id, chatid, userid, user1, user2 FROM chat_messages INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid WHERE date >= '2023-06-01' AND chat_messages.type = ? ORDER BY date DESC LIMIT 1;", [
    ChatMessage::TYPE_PROMISED
]);

$r = new ChatRoom($dbhr, $dbhm);

foreach ($chats as $chat) {
    $promisedby = $chat['userid'];
    $offerer = User::get($dbhr, $dbhm, $promisedby);
    $responder = User::get($dbhr, $dbhm, $chat['userid'] == $chat['user1'] ? $chat['user2'] : $chat['user1']);
    $lines = [];
    $lines[] = "This is a conversation between two people, Fred and Bill.  Fred has offered an item, and Bill is asking for it.";

    // Find previous interested message
    $interested = $dbhr->preQuery("SELECT id FROM chat_messages WHERE chatid = ? AND type = ? AND id < ? ORDER BY date DESC LIMIT 1;", [
        $chat['chatid'],
        ChatMessage::TYPE_INTERESTED,
        $chat['id']
    ]);

    if (count($interested)) {
        $intid = $interested[0]['id'];
        error_log("Interested $intid vs {$chat['id']}");

        $msgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE chatid = ? AND id >= ? ORDER BY id ASC", [
            $chat['chatid'],
            $intid
        ]);

        foreach ($msgs as $msg) {
            if ($msg['userid'] == $promisedby) {
                $text = "Fred says: ";
            } else {
                $text = "Bill says: ";
            }

            $s = "";
            $text .= $r->getTextSummary($msg, $offerer, $responder, 0, $s) . "\n\n";

            // Remove all line breaks and carriage returns.
            $text = str_replace("\n", " ", $text);
            $text = str_replace("\r", " ", $text);

            $lines[] = $text;
        }
    }

    echo "data = [\n";
    foreach ($lines as $line) {
        echo '"' . str_replace('"', '\\"', $line) . '"' . ",\n\n";
    }

    echo "]\n\n";

    echo "questions = ['Has A agreed to give the item to B?', 'Why?']\n\n";
}


<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$f = STDOUT;

fputcsv($f, ['ChatID', 'Successful?', 'ChatMessageSender', 'ChatMessageTime', 'ChatMessageType', 'ChatMessage']);

# Find some successful chats.
$chats = $dbhr->preQuery("SELECT chatid, messages_by.userid, messages.fromuser FROM messages_by 
    INNER JOIN messages ON messages.id = messages_by.msgid
    INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id
    WHERE messages.type = ?
    ORDER BY messages_by.id DESC LIMIT 1000000;", [
        Message::TYPE_OFFER,
]);

foreach ($chats as $chat) {
    $r = new ChatRoom($dbhr, $dbhm, $chat['chatid']);

    $cms = $dbhr->preQuery("SELECT * FROM chat_messages WHERE chat_messages.chatid = ? ORDER BY chat_messages.id ASC;", [
        $chat['chatid']
    ]);

    $otheru = $chat['fromuser'] == $r->getPrivate('user1') ? $r->getPrivate('user2') : $r->getPrivate('user1');
    $successful = $otheru == $chat['userid'] ? 1 : 0;

    foreach ($cms as $cm) {
        switch ($cm['type']) {
            case ChatMessage::TYPE_ADDRESS: {
                $cm['message'] = '(Address sent from address book)';
                break;
            }
            case ChatMessage::TYPE_IMAGE: {
                $cm['message'] = '(Image sent)';
                break;
            }
        }

        $cm['message'] = preg_replace('/[0-9]{4,}/', '***', $cm['message']);
        $cm['message'] = preg_replace(Message::EMAIL_REGEXP, '***@***.com', $cm['message']);

        fputcsv($f, [
            $chat['chatid'],
            $successful,
            $cm['date'],
            $cm['userid'] == $chat['fromuser'] ? 1 : 2,
            $cm['type'],
            $cm['message'],
        ]);
    }
}
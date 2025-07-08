<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$f = STDOUT;

fputcsv($f, ['Date', 'ReplierUserId', 'OfferId', 'OfferUserId', 'ChatId', 'ReplyBackTime']);

$chats = $dbhr->preQuery("SELECT chatid, chat_messages.date, userid, refmsgid, fromuser FROM `chat_messages` INNER JOIN messages ON messages.id = chat_messages.refmsgid WHERE chat_messages.type = 'Interested' AND refmsgid IS NOT NULL ORDER BY date;");

foreach ($chats as $chat) {
    $chatid = $chat['chatid'];
    $date = $chat['date'];
    $replierUserId = $chat['userid'];
    $offerId = $chat['refmsgid'];
    $fromUser = $chat['fromuser'];

    $replyBackTime = NULL;

    $subsequent = $dbhr->preQuery("SELECT date FROM chat_messages WHERE chatid = ? AND date > ? AND userid = ? AND chat_messages.type IN (?, ?, ?, ?) ORDER BY date LIMIT 1;", [
        $chatid,
        $date,
        $fromUser,
        ChatMessage::TYPE_DEFAULT,
        ChatMessage::TYPE_IMAGE,
        ChatMessage::TYPE_PROMISED,
        ChatMessage::TYPE_ADDRESS,
    ]);

    foreach ($subsequent as $sub) {
        if ($sub['date']) {
            // We found a subsequent message after the initial chat message
            $replyBackTime = $sub['date'];
        }
    }

    fputcsv($f, [$date, $replierUserId, $offerId, $fromUser, $chatid, $replyBackTime]);
}

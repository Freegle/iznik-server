<?php

# Dataset for RSS volunteer.

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$start = date('Y-m-d', strtotime("2018-06-21"));
$msgs = $dbhr->preQuery("SELECT DISTINCT refmsgid, lat, lng FROM chat_messages
INNER JOIN messages ON messages.id = chat_messages.refmsgid
WHERE chat_messages.date > ? AND refmsgid IS NOT NULL AND chat_messages.type = ? AND messages.type = ? AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL", [
    $start,
    ChatMessage::TYPE_INTERESTED,
    Message::TYPE_OFFER
]);

error_log("OfferID,OfferLat,OfferLng,ReplyLat,ReplyLng,MessagesExchanged,KnownSuccessful,PositiveRating,NegativeRating");

foreach ($msgs as $msg) {
    $chats = $dbhr->preQuery("SELECT DISTINCT chatid FROM chat_messages WHERE refmsgid = ?", [
        $msg['refmsgid']
    ]);

    foreach ($chats as $chat) {
        $ref = $dbhr->preQuery("SELECT id, userid, date FROM chat_messages WHERE chatid = ? AND refmsgid = ? AND type = ?", [
            $chat['chatid'],
            $msg['refmsgid'],
            ChatMessage::TYPE_INTERESTED
        ]);

        $until = date('Y-m-d', strtotime($ref[0]['date']) + 7 * 24 * 60 * 60);

        $exchanged = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ? AND date <= ? AND id > ?;", [
            $chat['chatid'],
            $until,
            $ref[0]['id']
        ]);

        $u = User::get($dbhr, $dbhm, $ref[0]['userid']);
        list ($lat, $lng, $loc) = $u->getLatLng(FALSE, FALSE);

        if ($lat || $lng) {
            $takenby = $dbhr->preQuery("SELECT COUNT(*) AS count FROM messages_by WHERE msgid = ? AND userid = ?", [
                $msg['refmsgid'],
                $ref[0]['userid']
            ])[0]['count'];

            $r = new ChatRoom($dbhr, $dbhm, $chat['chatid']);

            $rating = $dbhr->preQuery("SELECT * FROM ratings WHERE rater = ? AND ratee = ?", [
                $r->getPrivate('user1') == $ref[0]['userid'] ? $r->getPrivate('user2') : $ref[0]['userid'],
                $r->getPrivate('user1') == $ref[0]['userid'] ? $ref[0]['userid'] : $r->getPrivate('user2')
            ]);

            $ratingup = count($rating) && $rating[0]['rating'] == User::RATING_UP ? '1' : '0';
            $ratingdown = count($rating) && $rating[0]['rating'] == User::RATING_DOWN ? '1' : '0';

            error_log("{$msg['refmsgid']}, {$msg['lat']}, {$msg['lng']}, $lat, $lng, {$exchanged[0]['count']}, $takenby, $ratingup, $ratingdown");
        }
    }
}
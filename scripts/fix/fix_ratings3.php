<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

error_log("Find candidates");
$ratings = $dbhr->preQuery("
SELECT ratings.*, chat_messages.chatid FROM ratings 
INNER JOIN chat_rooms ON (chat_rooms.user1 = ratings.ratee AND chat_rooms.user2 = ratings.rater OR chat_rooms.user2 = ratings.ratee AND chat_rooms.user1 = ratings.rater)
INNER JOIN chat_messages ON chat_messages.chatid = chat_rooms.id AND chat_messages.userid = ratings.ratee
WHERE chat_messages.type = ? AND rating = ? AND chat_messages.date < ratings.timestamp;
", [
    ChatMessage::TYPE_PROMISED,
    User::RATING_DOWN
]);

$total = count($ratings);
error_log("Found $total");
$retaliatory = 0;

$c = new ChatRoom($dbhr, $dbhm);

foreach ($ratings as $rating) {
    error_log("Possible retaliation: Down by {$rating['rater']} on {$rating['ratee']} chat {$rating['chatid']}");
}

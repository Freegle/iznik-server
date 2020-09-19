<?php
# Rescale large images in message_attachments

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');

$uids = array_column($dbhr->preQuery("SELECT userid FROM users_banned"), 'userid');
$c = new ChatRoom($dbhr, $dbhm);

foreach ($uids as $uid) {
    $chats = $dbhr->preQuery("SELECT id, user2 FROM chat_rooms WHERE user1 = ? AND chattype = ? AND latestmessage >= '2020-05-12';", [
        $uid,
        ChatRoom::TYPE_USER2USER
    ]);

    foreach ($chats as $chat) {
        if ($c->bannedInCommon($uid, $chat['user2'])) {
            error_log("Chat {$chat['id']} from $uid => {$chat['user2']}");
        }
    }
}
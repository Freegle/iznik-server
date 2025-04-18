<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$m = new ChatMessage($dbhr, $dbhm);

$mysqltime = date ("Y-m-d", strtotime("Midnight 7 days ago"));
$messages = $dbhr->preQuery("SELECT DISTINCT chat_messages.* FROM chat_messages INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END) INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = 'Freegle' WHERE date > '$mysqltime' AND reviewedby IS NULL AND chat_messages.type IN (?, ?) AND chat_rooms.chattype = ? ORDER BY chat_messages.id DESC;", [
    ChatMessage::TYPE_DEFAULT,
    ChatMessage::TYPE_INTERESTED,
    ChatRoom::TYPE_USER2USER
]);
$count = 0;
$newspam = 0;
$oldspam = 0;

foreach ($messages as $message) {
    if ($message['reviewrequired'] == 0 && $message['reviewrejected'] == 0) {
        # Not spam - check if it now is.
        if ($m->checkReview($message['message'], FALSE, $message['userid'])) {
            error_log("New spam {$message['id']} " . substr($message['message'], 0, 60));
            $dbhm->preExec("UPDATE chat_messages SET reviewrequired = 1 WHERE id = ?;", [ $message['id'] ]);
            $newspam++;
        }
    } else if (!$message['reviewrejected']) {
        # Currently not spam - check if it now is
        if (!$m->checkReview($message['message'], FALSE, $message['userid'])) {
            error_log("No longer spam {$message['id']} " . substr($message['message'], 0, 60));
            $dbhm->preExec("UPDATE chat_messages SET reviewrequired = 0 WHERE id = ?;", [ $message['id'] ]);
            $oldspam++;
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count / " . count($messages));
    }
}

error_log("New spam $newspam no longer spam $oldspam");
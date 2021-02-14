<?php
#
# Purge chats. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

# Purge any spam chat messages which are more than a week old.  This gives time for us to debug any issues.
error_log("Purge spam");
$start = date('Y-m-d', strtotime("midnight 7 days ago"));

$total = 0;
do {
    $sql = "SELECT id FROM chat_messages WHERE date < '$start' AND reviewrejected = 1 LIMIT 1000;";
    $msgs = $dbhm->query($sql)->fetchAll();
    foreach ($msgs as $msg) {
        $dbhm->exec("DELETE FROM chat_messages WHERE id = {$msg['id']};");
        $total++;

        if ($total % 1000 == 0) {
            error_log("...$total");
        }
    }
} while (count($msgs) > 0);

# Purge chats which have no messages.  This can happen for spam replies, which create a chat and then the message
# later gets deleted.
error_log("Purge empty");

$total = 0;
do {
    $sql = "SELECT chat_rooms.id FROM `chat_rooms` LEFT OUTER JOIN chat_messages ON chat_rooms.id = chat_messages.chatid WHERE chat_messages.chatid IS NULL AND chat_rooms.chattype IN ('User2User', 'User2Mod') LIMIT 1000;";
    $chats = $dbhm->query($sql)->fetchAll();
    foreach ($chats as $chat) {
        $dbhm->exec("DELETE FROM chat_rooms WHERE id = {$chat['id']};");
        $total++;

        if ($total % 1000 == 0) {
            error_log("...$total");
        }
    }
} while (count($chats) > 0);

# Purge chat images which have no parent chat message.
$total = 0;
do {
    $sql = "SELECT id FROM chat_images WHERE chatmsgid IS NULL LIMIT 1000;";
    $msgs = $dbhm->query($sql)->fetchAll();
    foreach ($msgs as $msg) {
        $dbhm->exec("DELETE FROM chat_images WHERE id = {$msg['id']};");
        $total++;

        if ($total % 1000 == 0) {
            error_log("...$total");
        }
    }
} while (count($msgs) > 0);
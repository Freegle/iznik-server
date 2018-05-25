<?php
# Rescale large images in message_attachments

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');

$apis = $dbhr->preQuery("SELECT date FROM logs_api ORDER BY date ASC LIMIT 1;");
$earliest = $apis[0]['date'];

error_log("Earliest API log $earliest");

$chats = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE date >= '$earliest' AND reviewrequired = 1;");
$outstanding = $chats[0]['count'];

$chats = $dbhr->preQuery("SELECT id, date, reviewedby FROM chat_messages WHERE date >= '$earliest' AND reviewedby IS NOT NULL;");
$delays = [];
foreach ($chats as $chat) {
    error_log("...chat {$chat['id']} reviewedby {$chat['reviewedby']}");
    $logs = $dbhr->preQuery("SELECT date FROM logs_api WHERE userid = {$chat['reviewedby']} AND request LIKE '%chatmessage%' AND request LIKE '%{$chat['id']}%' ORDER BY date ASC;");
    if (count($logs) > 0) {
        $reviewedat = $logs[0]['date'];
        $delay = strtotime($reviewedat) - strtotime($chat['date']);
        error_log("...{$chat['date']} reviewed at $reviewedat delay $delay");
        $delays[] = $delay;
    }
}


error_log("Median delay " . calculate_median($delays) . " from " . count($chats) . " earliest $earliest outstanding $outstanding");
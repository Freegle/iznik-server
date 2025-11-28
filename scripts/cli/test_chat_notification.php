<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

if (!isset($opts['e'])) {
    die("Usage: php test_chat_notification.php -e email@example.com\n");
}

$email = $opts['e'];

$u = new User($dbhr, $dbhm);
$uid = $u->findByEmail($email);

if (!$uid) {
    die("User not found: $email\n");
}

error_log("Found user $uid ($email)");

// Find last active chat for this user with at least 2 messages
$chats = $dbhr->preQuery("
    SELECT cr.id as chatid, COUNT(cm.id) as msgcount, MAX(cm.date) as lastmsg
    FROM chat_rooms cr
    INNER JOIN chat_messages cm ON cm.chatid = cr.id
    WHERE (cr.user1 = ? OR cr.user2 = ?)
    AND cm.reviewrequired = 0
    AND cm.reviewrejected = 0
    GROUP BY cr.id
    HAVING msgcount >= 2
    ORDER BY lastmsg DESC
    LIMIT 1
", [$uid, $uid]);

if (count($chats) === 0) {
    die("No active chats found with at least 2 messages\n");
}

$chat = $chats[0];
error_log("Found chat {$chat['chatid']} with {$chat['msgcount']} messages, last message: {$chat['lastmsg']}");

// Get some messages from this chat
$messages = $dbhr->preQuery("
    SELECT id, userid, message, date
    FROM chat_messages
    WHERE chatid = ?
    AND reviewrequired = 0
    AND reviewrejected = 0
    ORDER BY date DESC
    LIMIT 5
", [$chat['chatid']]);

error_log("Recent messages in this chat:");
foreach ($messages as $msg) {
    error_log("  [{$msg['date']}] User {$msg['userid']}: " . substr($msg['message'], 0, 50));
}

// Reset lastmsgnotified for this chat to force notifications for testing
error_log("\nResetting lastmsgnotified for chat {$chat['chatid']} to enable test notifications...");
$dbhm->preExec("UPDATE chat_roster SET lastmsgnotified = NULL WHERE chatid = ? AND userid = ?", [
    $chat['chatid'],
    $uid
]);

// Now trigger the notification system for this user
// This will go through the proper flow including notifyIndividualMessages for admin users
error_log("Triggering notification for user $uid...");

$p = new PushNotifications($dbhr, $dbhm);
$count = $p->notify($uid, FALSE);

error_log("Notification triggered, sent $count notifications");

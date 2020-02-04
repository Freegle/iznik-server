<?php
# Notify by email of unread chats

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$oldest = date("Y-m-d", strtotime("Midnight 7 days ago"));
$newest = date("Y-m-d H:i:s", strtotime("24 hours ago"));
$expecteds = $dbhr->preQuery("SELECT chat_messages.*, user1, user2 FROM chat_messages INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id WHERE chat_messages.date>= '$oldest' AND chat_messages.date <= '$newest' AND replyexpected = 1 AND replyreceived = 0;");
$received = 0;
$waiting = 0;

foreach ($expecteds as $expected) {
    $afters = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ? AND id > ? AND userid != ?;",
        [
            $expected['chatid'],
            $expected['id'],
            $expected['userid']
        ]);

    $count = $afters[0]['count'];
    $other = $expected['userid'] == $expected['user1'] ? $expected['user2'] : $expected['user1'];

    if ($count) {
        #error_log("Expected received to {$expected['date']} {$expected['id']} from user #{$expected['userid']}");
        $dbhm->preExec("UPDATE chat_messages SET replyreceived = 1 WHERE id = ?;", [
            $expected['id']
        ]);

        $dbhm->preExec("INSERT INTO users_expected (expecter, expectee, chatmsgid, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?;", [
            $expected['userid'],
            $other,
            $expected['id'],
            1,
            1
        ]);

        $received++;
    } else {
        $dbhm->preExec("INSERT INTO users_expected (expecter, expectee, chatmsgid, value) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?;", [
            $expected['userid'],
            $other,
            $expected['id'],
            -1,
            -1
        ]);

        $waiting++;
    }
}

error_log("Received $received waiting $waiting");
error_log("\nWorst:\n");
$expectees = $dbhr->preQuery("SELECT SUM(value) AS net, expectee FROM `users_expected` GROUP BY expectee HAVING net < 0 ORDER BY net ASC LIMIT 10;");

foreach ($expectees as $expectee) {
    $u = new User($dbhr, $dbhm, $expectee['expectee']);
    error_log("#{$expectee['expectee']} " . $u->getEmailPreferred() . " net {$expectee['net']}");
}

error_log("\nBest:\n");
$expectees = $dbhr->preQuery("SELECT SUM(value) AS net, expectee FROM `users_expected` GROUP BY expectee HAVING net > 0 ORDER BY net DESC LIMIT 10;");

foreach ($expectees as $expectee) {
    $u = new User($dbhr, $dbhm, $expectee['expectee']);
    error_log("#{$expectee['expectee']} " . $u->getEmailPreferred() . " net {$expectee['net']}");
}
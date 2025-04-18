<?php
# Notify by email of unread chats

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Update reply times for recent chats expecting replies.  Normally we update the reply time when processing a chat
# message, but we want to handle the case where someone stops replying entirely.
error_log("Update reply times");
$chats = $dbhr->preQuery("SELECT DISTINCT chatid FROM chat_messages WHERE chat_messages.date >= ? 
    AND chat_messages.replyexpected = 1 AND chat_messages.replyreceived = 0;", [
    date('Y-m-d', strtotime("midnight 31 days ago"))
]);

foreach ($chats as $chat) {
    $r = new ChatRoom($dbhr, $dbhm, $chat['chatid']);
    $u1 = $r->getPrivate('user1');
    $u2 = $r->getPrivate('user2');
    $r->replyTimes(array_filter([$u1, $u2]), TRUE);
}

# Tidy up any expected replies from deleted users, which shouldn't count.
$tidy = 0;
$mysqltime = date("Y-m-d", strtotime("24 hours ago"));
$users = $dbhr->preQuery("SELECT id FROM users WHERE deleted IS NOT NULL AND deleted >= ?;", [
    $mysqltime
]);

foreach ($users as $user) {
    $ids = $dbhr->preQuery("SELECT chat_messages.id FROM chat_messages WHERE userid = ? AND replyexpected = 1 AND replyreceived = 0;", [
        $user['id']
    ]);

    foreach ($ids as $id) {
        $dbhm->preExec("UPDATE chat_messages SET replyexpected = 0 WHERE id = ?;", [
            $id['id']
        ]);
        $tidy++;
    }
}

error_log("Tidied deleted $tidy");

# Tidy up any expected replies from spammer, which shouldn't count.
$tidy = 0;
$ids = $dbhr->preQuery("SELECT chat_messages.id FROM chat_messages INNER JOIN spam_users ON chat_messages.userid = spam_users.userid WHERE replyexpected = 1 AND replyreceived = 0;");

foreach ($ids as $id) {
    $dbhm->preExec("UPDATE chat_messages SET replyexpected = 0 WHERE id = ?;", [
        $id['id']
    ]);
    $tidy++;
}

error_log("Tidied spam $tidy");


$r = new ChatRoom($dbhr, $dbhm);
$waiting = $r->updateExpected();

error_log("waiting $waiting");
error_log("\nWorst:\n");
$expectees = $dbhr->preQuery("SELECT SUM(value) AS net, COUNT(*) AS count, expectee FROM `users_expected` GROUP BY expectee HAVING net < 0 ORDER BY net ASC LIMIT 10;");

foreach ($expectees as $expectee) {
    $u = new User($dbhr, $dbhm, $expectee['expectee']);
    error_log("#{$expectee['expectee']} " . $u->getEmailPreferred() . " net {$expectee['net']} of {$expectee['count']}");
}

error_log("\nBest:\n");
$expectees = $dbhr->preQuery("SELECT SUM(value) AS net, COUNT(*) AS count, expectee FROM `users_expected` GROUP BY expectee HAVING net > 0 ORDER BY net DESC LIMIT 10;");

foreach ($expectees as $expectee) {
    $u = new User($dbhr, $dbhm, $expectee['expectee']);
    error_log("#{$expectee['expectee']} " . $u->getEmailPreferred() . " net {$expectee['net']} of {$expectee['count']}");
}
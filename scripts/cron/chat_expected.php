<?php
# Notify by email of unread chats

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Tidy up any expected replies from deleted users, which shouldn't count.
$ids = $dbhr->preQuery("SELECT chat_messages.id FROM users INNER JOIN chat_messages ON chat_messages.userid = users.id WHERE chat_messages.date >= '2020-01-01' AND users.deleted IS NOT NULL AND chat_messages.replyexpected = 1 AND chat_messages.replyreceived = 0;");

foreach ($ids as $id) {
    $dbhm->preExec("UPDATE chat_messages SET replyexpected = 0 WHERE id = ?;", [
        $id['id']
    ]);
}

$r = new ChatRoom($dbhr, $dbhm);
$waiting = $r->updateExpected();

error_log("Received $received waiting $waiting");
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

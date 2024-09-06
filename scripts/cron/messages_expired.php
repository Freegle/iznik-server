<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We might have messages which have an explicit deadline.
$earliestmessage = date("Y-m-d", strtotime("Midnight " . Message::EXPIRE_TIME . " days ago"));
$msgs = $dbhr->preQuery("SELECT messages.id FROM messages
    LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
    WHERE messages.arrival >= ? AND deadline IS NOT NULL AND deadline < CURDATE() AND messages_outcomes.id IS NULL
;", [
    $earliestmessage
]);

foreach ($msgs AS $msg) {
    $m = new Message($dbhr, $dbhm, $msg['id']);
    error_log("Deadline expired for #{$msg['id']} " . $m->getSubject());
    $m->mark(Message::OUTCOME_EXPIRED, "reached deadline", NULL, NULL);
}

# We might have messages indexed which have expired because of group repost settings.  If so, add an actual
# expired outcome and remove them from the index.
$msgs = $dbhr->preQuery("SELECT msgid FROM messages_spatial WHERE successful = 0;");

$count = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);
    $m->processExpiry();
    $count++;

    if ($count % 100 == 0) {
        error_log("$count / " . count($msgs));
    }
}

Utils::unlockScript($lockh);
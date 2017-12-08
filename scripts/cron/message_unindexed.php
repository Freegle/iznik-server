<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

# Only interested in being able to search within the last 30 days.
$date = date('Y-m-d', strtotime("30 days ago"));
$msgs = $dbhr->preQuery("SELECT msgid FROM messages_groups WHERE messages_groups.collection = 'Approved' AND messages_groups.msgid NOT IN (SELECT msgid FROM messages_index) AND messages_groups.deleted = 0 AND messages_groups.arrival >= ? ORDER BY messages_groups.arrival DESC;", [
    $date
]);

$count = 0;

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['msgid']);
    error_log("#{$msg['msgid']} " . $m->getPrivate('arrival') . " " . $m->getSubject());
    $m->index();
    $count++;
    error_log("$count / " . count($msgs));
}
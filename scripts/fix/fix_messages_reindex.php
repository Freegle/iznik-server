<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/message/Message.php');

$msgs = $dbhr->preQuery("SELECT messages.id FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE messages_groups.deleted = 0 ORDER BY messages_groups.arrival DESC;");

foreach ($msgs as $msg) {
    $m = new Message($dbhr, $dbhm, $msg['id']);
    error_log("#{$msg['id']} " . $m->getPrivate('arrival') . " " . $m->getSubject());
    $m->index();
}
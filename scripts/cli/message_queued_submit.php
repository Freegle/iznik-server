<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

#$messages = $dbhr->preQuery("SELECT msgid, fromuser FROM messages_drafts INNER JOIN messages ON messages.id = messages_drafts.msgid WHERE timestamp >= '2020-05-01';");
$messages = $dbhr->preQuery("SELECT msgid, fromuser, groupid FROM messages_drafts INNER JOIN messages ON messages.id = messages_drafts.msgid WHERE msgid = 66383693;");

foreach ($messages as $message) {
    $m = new Message($dbhr, $dbhm, $message['msgid']);
    $u = new User($dbhr, $dbhm, $message['fromuser']);
    $email = $u->getEmailPreferred();

    error_log($email . " - " . $m->getSubject());
    $dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, collection,arrival, msgtype) VALUES (?,?,?,NOW(),?);", [
        $message['msgid'],
        $message['groupid'],
        MessageCollection::PENDING,
        $m->getType()
    ]);

    $m->submit($u, $email, $message['groupid']);
}
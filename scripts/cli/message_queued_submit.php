<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$messages = $dbhr->preQuery("SELECT msgid, groupid, fromuser FROM messages_drafts INNER JOIN messages ON messages.id = messages_drafts.msgid WHERE timestamp >= '2020-05-01';");
#$messages = $dbhr->preQuery("SELECT msgid, fromuser, groupid FROM messages_drafts INNER JOIN messages ON messages.id = messages_drafts.msgid WHERE msgid = 66383693;");

foreach ($messages as $message) {
    $m = new Message($dbhr, $dbhm, $message['msgid']);

    if (Utils::pres('fromuser', $message)) {
        $u = new User($dbhr, $dbhm, $message['fromuser']);
        $email = $u->getEmailPreferred();

        error_log($email . " - " . $m->getSubject());
        $dbhm->preExec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection,arrival, msgtype) VALUES (?,?,?,NOW(),?);", [
            $message['msgid'],
            $message['groupid'],
            MessageCollection::PENDING,
            $m->getType()
        ]);

        $m->constructSubject($message['groupid']);
        $m->submit($u, $email, $message['groupid']);
    } else {
        error_log("Skip message with no fromuser {$message['msgid']}");
    }
}
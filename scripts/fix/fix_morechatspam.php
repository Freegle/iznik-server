<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/message/Message.php');
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

$fh = fopen('/tmp/spam','r');

while ($line = fgets($fh)){
    if (preg_match('/Routed #(.*?) /', $line, $matches)) {
        $msgid = $matches[1];
        $msgs = $dbhr->preQuery("SELECT date, subject FROM messages WHERE id = ?;", [
            $msgid
        ]);

        foreach ($msgs as $msg) {
            error_log("$msgid {$msg['date']} {$msg['subject']}");
            $old = $dbhr->preQuery("SELECT * FROM chat_messages_byemail WHERE msgid = ?;", [
                $msgid
            ]);

            foreach ($old as $o) {
                $dbhm->preExec("DELETE FROM chat_messages WHERE id = ?", [
                    $o['chatmsgid']
                ]);
            }

            $m = new Message($dbhr, $dbhm, $msgid);
            $r = new MailRouter($dbhr, $dbhm);
            $rc = $r->route($m);
        }
    }
}

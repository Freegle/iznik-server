<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$chatmsgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE date >= '2019-09-01' and date < '2020-09-01' AND type = ? AND refmsgid IS NOT NULL ORDER BY id ASC;", [
    \Freegle\Iznik\ChatMessage::TYPE_PROMISED
]);

error_log("Found " . count($chatmsgs));

foreach ($chatmsgs as $chatmsg) {
    error_log("{$chatmsg['userid']} in {$chatmsg['id']} promised {$chatmsg['refmsgid']}");

    $reneges = $dbhr->preQuery("SELECT * FROM chat_messages WHERE chatid = ? AND refmsgid = ? AND type = ?", [
        $chatmsg['chatid'],
        $chatmsg['refmsgid'],
        \Freegle\Iznik\ChatMessage::TYPE_RENEGED
    ]);

    if (!count($reneges)) {
        error_log("...not reneged");
        $r = new \Freegle\Iznik\ChatRoom($dbhr, $dbhm, $chatmsg['chatid']);

        $otheru = $r->getPrivate('user1') == $chatmsg['userid'] ? $r->getPrivate('user2') : $r->getPrivate('user1');
        error_log("...to $otheru");

        $m = new \Freegle\Iznik\Message($dbhr, $dbhm, $chatmsg['refmsgid']);

        if (!$m->hasOutcome()) {
            error_log("...no outcome yet");
            $sql = "REPLACE INTO messages_promises (msgid, userid, promisedat) VALUES (?, ?, ?);";
            $dbhm->preExec($sql, [
                $chatmsg['refmsgid'],
                $otheru,
                $chatmsg['date']
            ]);
        }
    } else {
        error_log("...unpromised after");
    }
}

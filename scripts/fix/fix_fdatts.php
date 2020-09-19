<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
require_once dirname(__FILE__) . '/../../include/config.php';

require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

$start = date('Y-m-d', strtotime("midnight 31 days ago"));

error_log("Query");
$msgs = $dbhr->preQuery("SELECT * FROM `logs_api` WHERE request like '%call\":\"message\"%' and request like '%attachments%' ORDER BY date DESC;");
$count = count($msgs);
error_log("Got $count");

$at = 0;

foreach ($msgs as $msg) {
    $req = json_decode($msg['request'], TRUE);
    $rsp = json_decode($msg['response'], TRUE);
    if (Utils::pres('id', $rsp)) {
        $msgid = $rsp['id'];
        $atts = $req['attachments'];
        $current = $dbhr->preQuery("SELECT * FROM messages_attachments WHERE msgid = ?;", [
            $msgid
        ]);

        if (count($atts) > count($current)) {
            $currids = array_column($current, 'id');
            $diff = array_diff($atts, $currids);

            if (count($diff)) {
                foreach ($diff as $d) {
                    $already = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE id = ?;", [
                        $d
                    ]);

                    if (count($already) == 0) {
                        error_log("$msgid add $d");
                        $dbhm->preExec("INSERT INTO messages_attachments (id, msgid, contenttype, archived, data, identification, hash) VALUES (?, ?, 'image/jpeg', 1, NULL, NULL, NULL);", [
                            $d,
                            $msgid
                        ]);
                    }
                }
            }
        }
    }

    $at++;

    if ($at % 1000 === 0) {
        error_log("...$at / $count");
    }
}

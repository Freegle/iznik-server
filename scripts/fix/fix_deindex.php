<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$oldest = strtotime("30 days ago");

$total = 0;
//do {
//    $sql = "SELECT msgid FROM messages_index WHERE arrival > -$oldest LIMIT 1000;";
//    $msgs = $dbhr->preQuery($sql);
//
//    foreach ($msgs as $msg) {
//        $dbhm->exec("DELETE FROM messages_index WHERE msgid = {$msg['msgid']};");
//        #error_log($msg['msgid']);
//        $total++;
//
//        if ($total % 1000 == 0) {
//            error_log("...$total");
//        }
//    }
//} while (count($msgs) > 0);

do {
    $done = $dbhm->exec("DELETE FROM messages_index WHERE arrival > -$oldest LIMIT 1000;");

    $total += $done;
    if ($total % 1000 == 0) {
        error_log("...$total");
    }
} while ($done > 0);
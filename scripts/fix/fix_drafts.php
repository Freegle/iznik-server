<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');



$missings = $dbhr->preQuery("SELECT * FROM messages_drafts WHERE userid IS NULL;");

error_log("Missing users " . count($missings));

$found = 0;

foreach ($missings as $missing) {
    $logs = $dbhr->preQuery("SELECT * FROM logs_api WHERE date >= CURDATE() AND session = ? AND userid IS NOT NULL LIMIT 1;", [
        $missing['session']
    ]);

    foreach ($logs as $log) {
        error_log("...found {$missing['msgid']} for {$log['userid']}");
        $dbhm->preExec("UPDATE messages_drafts SET userid = ? WHERE id = ?;", [
            $log['userid'],
            $missing['id']
        ]);

        $found++;
    }
}

error_log("Identified $found / " . count($missings));
<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$logs = $dbhr->preQuery("SELECT * FROM logs_api WHERE request LIKE '%covid%' AND request LIKE '%\"action\":\"Suggest\"%'");

foreach ($logs as $log) {
    $req = json_decode($log['request'], TRUE);
    error_log("{$log['userid']} {$log['request']}");
//
//    $dbhm->preExec("UPDATE covid SET phone = ?, intro = ? WHERE userid = ?;", [
//        $req['phone'],
//        $req['intro'],
//        $log['userid']
//    ]);

    $dbhm->preExec("INSERT IGNORE INTO covid_matches (helper, helpee, suggestedat, emailed) VALUES (?, ?, ?, NOW());", [
        $req['helper'],
        $req['helpee'],
        $log['date']
    ]);
}

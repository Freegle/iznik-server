<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$logs = $dbhr->preQuery("SELECT * FROM logs_api WHERE request LIKE '%covid%' AND request LIKE '%phone%' AND request LIKE '%PATCH%'");

foreach ($logs as $log) {
    $req = json_decode($log['request'], TRUE);
    error_log("{$log['userid']} {$req['phone']}");

    $dbhm->preExec("UPDATE covid SET phone = ?, intro = ? WHERE userid = ?;", [
        $req['phone'],
        $req['intro'],
        $log['userid']
    ]);
}

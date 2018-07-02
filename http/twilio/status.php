<?php
require_once('/etc/iznik.conf');
require_once(dirname(__FILE__) . '/../../include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

error_log("Twilio status " . var_export($_REQUEST, TRUE));

$num = presdef('To', $_REQUEST, NULL);
$status = presdef('MessageStatus', $_REQUEST, NULL);
$u = new User($dbhr, $dbhm);

if ($num && $status) {
    $dbhm->preExec("UPDATE users_phones SET laststatus = ?, laststatusreceived = NOW() WHERE number = ?;", [
        $status,
        $u->formatPhone($num)
    ]);
}
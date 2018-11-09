<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("SELECT id FROM `users_emails` WHERE email LIKE 'FBUser%';");
$total = count($emails);

error_log("Found $total\n");
$count = 0;

foreach ($emails as $email) {
    $dbhm->preExec("DELETE FROM users_emails WHERE id = ?;", [
        $email['id']
    ]);

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}

<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("select * from users_emails where locate('-', email) =1 and locate('ilovefreegle', email) > 0;");

foreach ($emails as $email) {
    $dbhm->preExec("DELETE FROM users_emails WHERE id = ?;", [$email['id']]);
    $u = new User($dbhr, $dbhm, $email['userid']);
    $newemail = $u->inventEmail();
    $dbhm->preExec("UPDATE messages SET fromaddr = ? WHERE fromaddr LIKE '-%' AND fromuser = ?;", [
        $newemail,
        $email['userid']
    ]);
}

error_log("From Yahoo $yahoocount from membs $membcount");
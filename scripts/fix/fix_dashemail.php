<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("select * from users_emails where email like '-%@users.ilovefreegle.org';");

foreach ($emails as $email) {
    error_log("Consider {$email['email']}");
    $u = new User($dbhr, $dbhm, $email['userid']);
    $u->removeEmail($email['email']);
    $email = $u->inventEmail(TRUE);
    $u->addEmail($email, 0, FALSE);
}

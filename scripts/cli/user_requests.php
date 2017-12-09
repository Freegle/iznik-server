<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$users = $dbhr->preQuery("SELECT DISTINCT users_requests.userid FROM users_requests INNER JOIN users_emails ON users_emails.userid = users_requests.userid WHERE completed IS NOT NULL;");

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    error_log($u->getEmailPreferred());
}
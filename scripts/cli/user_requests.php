<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT DISTINCT users_requests.userid FROM users_requests INNER JOIN users_emails ON users_emails.userid = users_requests.userid WHERE completed IS NOT NULL;");

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    error_log($u->getEmailPreferred());
}
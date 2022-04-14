<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("select userid, email from users_emails where email like '%\%%@%';");

foreach ($users as $user) {
    $u = User::get($dbhr, $dbhm, $user['userid']);

    $emails = $u->getEmails();

    foreach ($emails as $email) {
        if (strpos($email['email'], USER_DOMAIN) !== FALSE) {
            error_log("Found our domain email {$email['email']}");
            $u->removeEmail($email['email']);
        }
    }

    $u->inventEmail();
}
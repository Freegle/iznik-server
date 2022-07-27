<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$emails = $dbhr->preQuery("SELECT * FROM users_emails WHERE users_emails.added >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND email like '%trashnothing.com'");

$uids = [];

foreach ($emails as $email) {
    $p = strpos($email['email'], '-');

    if ($p !== FALSE) {
        $name = substr($email['email'], 0, $p);

        if ($uids[$name] && $uids[$name] != $email['userid']) {
            error_log("Found duplicate $name for {$email['userid']} and {$uids[$name]}");
        } else {
            $uids[$name] = $email['userid'];
        }
    }
}
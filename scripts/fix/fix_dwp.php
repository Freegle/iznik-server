<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$emails = $dbhr->preQuery("SELECT * FROM users_emails where email like '%@dwp.gsi.gov.uk';");
$total = count($emails);

error_log("Found $total\n");
$count = 0;

foreach ($emails as $email) {
    $u = new User($dbhr, $dbhm, $email['userid']);
    $u->removeEmail($email['email']);
    $u->addEmail(str_replace('@dwp.gsi.gov.uk', '@dwp.gov.uk', $email['email']), $email['preferred']);
}

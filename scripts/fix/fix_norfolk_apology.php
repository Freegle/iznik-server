<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/user/User.php');

$users = $dbhr->preQuery("SELECT DISTINCT userid from memberships where groupid in (select id from groups where external is not null);");

$spool = new \Swift_FileSpool(IZNIK_BASE . "/spool");
$spooltrans = \Swift_SpoolTransport::newInstance($spool);
$smtptrans = \Swift_SmtpTransport::newInstance($host);
$transport = \Swift_FailoverTransport::newInstance([
    $smtptrans,
    $spooltrans
]);

$mailer = \Swift_Mailer::newInstance($transport);

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $email = $u->getEmailPreferred();
    error_log("...$email");

    $message = \Swift_Message::newInstance()
        ->setSubject("Sorry, we got a bit carried away there...")
        ->setFrom(['info@ilovefreegle.org' => "Norfolk Freegle" ])
        ->setTo([$email => $u->getName()])
        ->setBody("Hello,

We're doing some work on our website at the moment, which we'll be telling you more about soon.  We messed up, and an incorrect \"Welcome\" email snuck out. We're sorry about that - if you received it then please just ignore it.

More soon!");

    list ($transport, $mailer) = Mail::getMailer();

    $mailer->send($message);
}
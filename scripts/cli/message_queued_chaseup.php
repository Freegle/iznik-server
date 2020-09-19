<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$messages = $dbhr->preQuery("SELECT msgid, fromuser FROM messages_drafts INNER JOIN messages ON messages.id = messages_drafts.msgid WHERE timestamp < '2020-05-01';");
#$messages = $dbhr->preQuery("SELECT msgid, fromuser FROM messages_drafts INNER JOIN messages ON messages.id = messages_drafts.msgid WHERE msgid = 66383066;");

foreach ($messages as $message) {
    $m = new Message($dbhr, $dbhm, $message['msgid']);

    if (Utils::pres('fromuser', $message)) {
        $u = new User($dbhr, $dbhm, $message['fromuser']);
        $email = $u->getEmailPreferred();

        error_log($email . " - " . $m->getSubject());

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $html = $twig->render('covid_unsuspend.html', [
            'email' => $email
        ]);

        $message = \Swift_Message::newInstance()
            ->setSubject("We're back - can you let us know if your post is still active?")
            ->setFrom([ GEEKS_ADDR => 'Freegle' ])
            ->setTo($email)
            ->setBody("Please go to https://www.ilovefreegle.org/myposts and submit or withdraw your recent post.");

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        list ($transport, $mailer) = Mail::getMailer();
        $mailer->send($message);
    } else {
        error_log("Skip message with no fromuser {$message['msgid']}");
    }
}
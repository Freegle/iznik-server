<?php

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/misc/Mail.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/misc/Donations.php');


$loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new Twig_Environment($loader);

$users = $dbhr->preQuery("SELECT  userid FROM covid WHERE type = ? AND nolonger = 0", [
    'CanHelp'
]);

$count = 0;

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $ours = $u->getOurEmail();

    try {
        list ($transport, $mailer) = getMailer();
        $m = Swift_Message::newInstance()
            ->setSubject('CORRECTION - COVID-19 - Please join the NHS Volunteer scheme')
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setReplyTo('covid19@ilovefreegle.org')
            ->setTo($u->getEmailPreferred())
//                ->setTo('log@ehibbert.org.uk')
            ->setBody("Thanks for replying offering help.  The Government have now announced their NHS Volunteers scheme.
             Now that they are getting organised, if you still are able and willing to help, we think it makes
             sense to join in with that.\r\n\r\n

               https://www.goodsamapp.org/NHS
               \r\n\r\n
               Thanks\r\n\r\n
                  
                  Your Freegle Volunteers");

        $html = $twig->render('covid/followupoffer.html', [
            'email' => $u->getEmailPreferred(),
            'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", NULL)
        ]);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $m->attach($htmlPart);

        $mailer->send($m);
    } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
}
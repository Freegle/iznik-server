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

$users = $dbhr->preQuery("SELECT  userid FROM covid WHERE type = ? AND nolonger = 0;", [
    'NeedHelp'
]);

$count = 0;

foreach ($users as $user) {
        $u = new User($dbhr, $dbhm, $user['userid']);
        $ours = $u->getOurEmail();

        try {
            list ($transport, $mailer) = getMailer();
            $m = Swift_Message::newInstance()
                ->setSubject('COVID-19 - Government Registration Scheme')
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setReplyTo('covid19@ilovefreegle.org')
                ->setTo($u->getEmailPreferred())
//                    ->setTo('log@ehibbert.org.uk')
                ->setBody("Hi there,
             
                           The Government has now launched a registration scheme for people who may need help during the COVID-10 situation.
              Since that's now up and running, we would encourage you to register on there.  We hope you stay
              safe and get the help you need.
              
            \r\n\r\n
            https://www.gov.uk/coronavirus-extremely-vulnerable
                  
                  Thanks,
                  
                  Your Freegle Volunteers");

            $html = $twig->render('covid/followupneed.html', [
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

            exit(0);
        } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };

}
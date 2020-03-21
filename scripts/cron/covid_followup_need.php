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

$users = $dbhr->preQuery("SELECT  userid FROM covid WHERE type = ? AND dispatched IS NOT NULL;", [
    'NeedHelp'
]);

$count = 0;

foreach ($users as $user) {
    # See if we have new suggestions.
    $helpers = $dbhr->preQuery("SELECT * FROM covid_matches WHERE helpee = ? AND emailed IS NULL;", [
        $user['userid']
    ]);
    $count = count($helpers);
    error_log("{$user['userid']} found $count helpers");

    if ($count > 0) {
        $u = new User($dbhr, $dbhm, $user['userid']);
        $ours = $u->getOurEmail();

        try {
            list ($transport, $mailer) = getMailer();
            $m = Swift_Message::newInstance()
                ->setSubject('COVID-19 - some people have offered to help you')
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setReplyTo(NOREPLY_ADDR)
                ->setTo($u->getEmailPreferred())
                ->setBody("Hi there,
             
             We have some suggestions of people who may be able to help.  Please go to https://www.ilovefreegle.org/covid/followupneed
                  
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

            $dbhm->preExec("UPDATE covid SET lastmailed = NOW() WHERE userid = ?;", [
                $user['userid']
            ]);

            foreach ($helpers as $helper) {
                $dbhm->preExec("UPDATE covid_matches SET emailed = NOW() WHERE id = ?", [
                    $helper['id']
                ]);
            }
        } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
    }
}
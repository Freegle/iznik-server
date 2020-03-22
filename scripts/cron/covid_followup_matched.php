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

$users = $dbhr->preQuery("SELECT * FROM covid_matches WHERE emailed IS NOT NULL AND emailedhelper IS NULL;", [
    'NeedHelp'
]);

$count = 0;

foreach ($users as $user) {
    # See if we've already mailed them.
    $others = $dbhr->preQuery("SELECT COUNT(*) AS count FROM covid_matches WHERE helper = ? AND emailedhelper IS NOT NULL;", [
        $user['helper']
    ], FALSE, FALSE);

    if ($others[0]['count']) {
        error_log("...already mailed {$user['helper']}");
        $dbhm->preExec("UPDATE covid_matches SET emailedhelper = NOW() WHERE id = ?;", [
            $user['id']
        ]);
    } else {
        error_log("...not mailed {$user['helper']}");
        $u = new User($dbhr, $dbhm, $user['helper']);
        $ours = $u->getOurEmail();

        try {
            list ($transport, $mailer) = getMailer();
            $m = Swift_Message::newInstance()
                ->setSubject('COVID-19 - we\'ve passed on your kind offer')
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setReplyTo('covid19@ilovefreegle.org')
                ->setTo($u->getEmailPreferred())
//                ->setTo('log@ehibbert.org.uk')
                ->setBody("Hi there,
             
             Thanks for offering.  We've passed on your details to someone.  Update your details or say if you're
             no longer available at https://www.ilovefreegle.org/covid/followupoffer 
                  
                  Thanks,
                  
                  Your Freegle Volunteers");

            $html = $twig->render('covid/followupmatched.html', [
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

            $dbhm->preExec("UPDATE covid_matches SET emailedhelper = NOW() WHERE id = ?", [
                $user['id']
            ]);
        } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
    }
}
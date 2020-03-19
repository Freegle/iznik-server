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

$users = $dbhr->preQuery("SELECT  userid FROM covid WHERE type = ?;", [
    'CanHelp'
]);

$count = 0;

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $ours = $u->getOurEmail();

    try {
        list ($transport, $mailer) = getMailer();
        $m = Swift_Message::newInstance()
            ->setSubject('COVID-19 Update')
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setReplyTo(NOREPLY_ADDR)
            ->setTo($u->getEmailPreferred())
            ->setBody("              Thanks for replying offering help - that's very kind of you.  This is just a quick update.
                  We've had a lot more offers of help than people who need it, which isn't surprising at this
                  point.  That may change over the next few weeks.  If we have someone near you who needs
                  help, then we will get in touch with you.
                  Meanwhile
                  we have updated the page so you can add a bit more information about what kind of help
                  you can offer. Please update your info at https://www.ilovefreegle.org/covid
                  
                  Thanks,
                  
                  Your Freegle Volunteers");

        $html = $twig->render('covid/update.html', [
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
    } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
}
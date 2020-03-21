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

$users = $dbhr->preQuery("SELECT userid FROM covid WHERE postcodechase < 3");

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $latlng = $u->getLatLng(FALSE, TRUE);
    if (!$latlng[0] && !$latlng[1]) {
        error_log("No lat lng for {$user['userid']}");
        $ours = $u->getOurEmail();

        try {
            list ($transport, $mailer) = getMailer();
            $m = Swift_Message::newInstance()
                ->setSubject('COVID-19 - We need your postcode')
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setReplyTo('covid19@ilovefreegle.org')
                ->setTo($u->getEmailPreferred())
//            ->setTo('log@ehibbert.org.uk')
                ->setBody("Hi there,
                 
                 You filled out our form offering or asking for help with COVID-19.  We don't have a postcode for you.  Please can
              you tell us your postcode so that we match people nearby?
              
              Please go to https://www.ilovefreegle.org/covid
                      
                      Thanks,
                      
                      Your Freegle Volunteers");

            $html = $twig->render('covid/nopostcode.html', [
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

            $dbhm->preExec("UPDATE covid SET postcodechase = postcodechase + 1 WHERE userid = ?;", [
                $user['userid']
            ]);
        } catch (Exception $e) {
            error_log("Failed " . $e->getMessage());
        };
    }
}
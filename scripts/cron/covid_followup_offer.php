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

# Fix phone numbers.
$phones = $dbhr->preQuery("SELECT * FROM covid WHERE phone IS NOT NULL AND phone NOT LIKE '0%';");

foreach ($phones as $phone) {
    error_log($phone['phone']);
    $newphone = $phone['phone'];

    if (strpos($newphone, '+44') === 0) {
        # Dialling codes unlikely to be understood, but otherwise will be a good number.
        error_log("Dialing code");
        $newphone = str_replace('+44', '0', $newphone);
    } else {
        if (strlen($newphone) > 6 && is_numeric(substr($newphone, 0, 1))) {
            # Full phone number but without leading 0.
            $newphone = "0$newphone";
            error_log("Starts with digit $newphone");
        } else  {
            # Likely to be lacking area code - not much we can do.
        }
    }

    if (strcmp($newphone, $phone['phone']) != 0) {
        error_log("...{$phone['phone']} => $newphone");
        $dbhm->preExec("UPDATE covid SET phone = ? WHERE id = ?;", [
            $newphone,
            $phone['id']
        ]);
    } else {
//        error_log("...leave");
    }
}

$loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new Twig_Environment($loader);

$users = $dbhr->preQuery("SELECT  userid FROM covid WHERE type = ? AND followupoffersent IS NULL;", [
    'CanHelp'
]);

$count = 0;

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $ours = $u->getOurEmail();

    try {
        list ($transport, $mailer) = getMailer();
        $m = Swift_Message::newInstance()
            ->setSubject('COVID-19 - Next Step')
            ->setFrom([NOREPLY_ADDR => SITE_NAME])
            ->setReplyTo('covid19@ilovefreegle.org')
            ->setTo($u->getEmailPreferred())
            ->setBody("Hi there,
             
             We'd like to get a contact phone number.  Please go to https://www.ilovefreegle.org/covid/followupoffer
                  
                  Thanks,
                  
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

        $dbhm->preExec("UPDATE covid SET followupoffersent = NOW() WHERE userid = ?;", [
            $user['userid']
        ]);
    } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
}
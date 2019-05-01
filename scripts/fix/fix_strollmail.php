
<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$users = $dbhr->preQuery("SELECT * FROM stroll_close;");

error_log(count($users) . " users");

$total = count($users);
$count = 0;

foreach ($users as $user) {
    try {
        $u = new User($dbhr, $dbhm, $user['userid']);
        $loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/');
        $twig = new Twig_Environment($loader);

        $html = $twig->render('stroll.html', [
            'email' => $u->getEmailPreferred(),
            'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", NULL)
        ]);

        error_log("..." . $u->getEmailPreferred());

        try {
            $message = Swift_Message::newInstance()
                ->setSubject("Edward's sponsored walk for Freegle passes close to you!")
                ->setFrom([NOREPLY_ADDR => 'Freegle'])
                ->setReturnPath($u->getBounce())
                ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                ->setBody("Edward's sponsored walk passes near you.  Find out more at https://sponsoramile.ilovefreegle.org");

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            list ($transport, $mailer) = getMailer();
            $mailer->send($message);
        } catch (Exception $e) {}

    } catch (Exception $e) {
    }

    $count ++;

    if ($count % 1000 == 0) {
        error_log("...$count / $total");
    }
}

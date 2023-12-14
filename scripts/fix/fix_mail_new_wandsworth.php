<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
$twig = new \Twig_Environment($loader);

$start = date('Y-m-d H:i', strtotime("yesterday 5pm"));
$end = date('Y-m-d H:i', strtotime('today 5pm'));
error_log("Look between $start and $end");

$d = new Donations($dbhr, $dbhm);

# Find the users who have received things.
$users = $dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = 126719 AND added >= ?;", [
    '2023-07-11',
]);

error_log("Found " . count($users));

$tn = 0;
$fd = 0;

foreach ($users as $user)
{
    $u = new User($dbhr, $dbhm, $user['userid']);

    $email = $u->getEmailPreferred();

    if (!$u->getPrivate('bouncing') && !$u->getPrivate('deleted') && $email)
    {
        if (strpos($email, 'trashnothing') !== false)
        {
            $tn++;
        } else
        {
            $fd++;
            try
            {
                error_log("Mail $email");
                list ($transport, $mailer) = Mail::getMailer();
                $m = \Swift_Message::newInstance()
                    ->setSubject("Complete this quick survey for the chance to win a £50 voucher!")
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setReplyTo(NOREPLY_ADDR)
                    ->setTo($u->getEmailPreferred())
//                    ->setTo(['log@ehibbert.org.uk','councils@ilovefreegle.org','anna@ilovefreegle.org'])
                    ->setBody(
                        "For a chance to win a £50 voucher, please complete the survey at https://ilovefreegle.org/shortlink/WandsworthSurvey"
                    );

                Mail::addHeaders($dbhr, $dbhm, $m, Mail::ADMIN, $u->getId());

                $html = $twig->render('wandsworth_survey.html', [
                    'name' => $u->getName(),
                    'email' => $u->getEmailPreferred(),
                    'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", null)
                ]);

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $m->attach($htmlPart);

                $mailer->send($m);
            } catch (\Exception $e)
            {
                \Sentry\captureException($e);
                error_log("Failed " . $e->getMessage());
            };
        }
    }
}

error_log("Sent $fd to Freegle and skipped $tn TN");
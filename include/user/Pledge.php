<?php

namespace Freegle\Iznik;

require_once(IZNIK_BASE . '/mailtemplates/invite.php');
require_once(IZNIK_BASE . '/lib/wordle/functions.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

use Jenssegers\ImageHash\ImageHash;
use Twilio\Rest\Client;

class Pledge extends Entity
{
    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function signup() {
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE lastaccess >= '2024-12-20' AND JSON_EXTRACT(settings, '$.pledge2025') AND JSON_EXTRACT(settings, '$.pledge2025_sent_signup') IS NULL;");
        error_log("Found " . count($users) . " users to send signup mail to");

        foreach ($users as $user) {
            $u = new User($this->dbhr, $this->dbhm, $user['id']);
            $email = $u->getEmailPreferred();

            $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
            $twig = new \Twig_Environment($loader);

            $html = $twig->render('pledge2025/signup.html', [
                'email' => $email,
            ]);

            $message = \Swift_Message::newInstance()
                ->setSubject("Thanks for making the Freegle Pledge for 2025!")
                ->setFrom([NOREPLY_ADDR => SITE_NAME])
                ->setTo($email)
                ->setBody("Thanks for making the Freegle Pledge for 2025!");

            # Add HTML in base-64 as default quoted-printable encoding leads to problems on
            # Outlook.
            $htmlPart = \Swift_MimePart::newInstance();
            $htmlPart->setCharset('utf-8');
            $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
            $htmlPart->setContentType('text/html');
            $htmlPart->setBody($html);
            $message->attach($htmlPart);

            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::PLEDGE_SIGNUP, $this->getId());

            list ($transport, $mailer) = Mail::getMailer();
            $this->sendIt($mailer, $message);
            error_log("...$email signed up");
            $u->setSetting('pledge2025_sent_signup', 1);
        }
    }

    public function sendIt($mailer, $message)
    {
        $mailer->send($message);
    }
}

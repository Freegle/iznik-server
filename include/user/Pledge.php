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

    public function checkPosted($month = NULL) {
        $month = $month ? $month : date('m');
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE lastaccess >= '2024-12-20' AND JSON_EXTRACT(settings, '$.pledge2025') AND JSON_EXTRACT(settings, '$.pledge2025_freegled_$month') IS NULL;");
        error_log("Found " . count($users) . " users to check posted");

        foreach ($users as $user) {
            $u = new User($this->dbhr, $this->dbhm, $user['id']);
            $email = $u->getEmailPreferred();

            if (!$email) {
                continue;
            }

            list($start, $count) = $this->countPosted($user['id'], $month);

            $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
            $twig = new \Twig_Environment($loader);

            # Get the month name.
            $monthName = strtolower(date('F', strtotime($start)));
            $monthNameUC = ucfirst($monthName);

            if ($count) {
                error_log("User " . $u->getId() . " ($email) has freegled $count items, thank them");

                $html = $twig->render("pledge2025/success.html", [
                    'email' => $email,
                    'count' => $count,
                    'month' => $monthNameUC,
                ]);

                $message = \Swift_Message::newInstance()
                    ->setSubject("Thanks for freegling in $monthNameUC!")
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setTo($email)
                    #->setTo('log@ehibbert.org.uk')
                    ->setBody("Thanks for freegling in January!");

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::PLEDGE_SUCCESS, $this->getId());

                list ($transport, $mailer) = Mail::getMailer();
                $this->sendIt($mailer, $message);
                $u->setSetting("pledge2025_freegled_$month", 1);
            } else {
                if (intval(date('d')) >= 12 && !$u->getSetting("pledge2025_encouraged_$month", FALSE)) {
                    error_log("User " . $u->getId() . " ($email) has not freegled anything, remind.");
                    $html = $twig->render("pledge2025/reminder.html", [
                        'email' => $email,
                        'count' => $count,
                        'month' => $monthNameUC,
                    ]);

                    $message = \Swift_Message::newInstance()
                        ->setSubject("There's still time to fulfill your Freegle Pledge in $monthNameUC!")
                        ->setFrom([NOREPLY_ADDR => SITE_NAME])
                        #->setTo('log@ehibbert.org.uk')
                        ->setTo($email)
                        ->setBody("There's still time - freegle something now!");

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = \Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $message->attach($htmlPart);

                    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::PLEDGE_REMINDER, $this->getId());

                    list ($transport, $mailer) = Mail::getMailer();
                    $this->sendIt($mailer, $message);
                    $u->setSetting("pledge2025_encouraged_$month", 1);
                }
            }
        }
    }

    public function sendIt($mailer, $message)
    {
        $mailer->send($message);
    }

    public function countPosted($userid, $month): array {
        # Get start of month $month using current year.
        $start = date('Y-m-01', strtotime(date('Y') . '-' . $month . '-01'));

        # Add one month to $start.
        $end = date('Y-m-d', strtotime($start . ' +1 month'));

        $freegles = $this->dbhr->preQuery(
            "SELECT COUNT(*) AS count FROM messages 
                         INNER JOIN messages_groups ON messages.id = messages_groups.msgid 
                         WHERE messages_groups.arrival BETWEEN ? AND ? 
                         AND messages.fromuser = ? AND messages_groups.collection = ? AND messages_groups.autoreposts = 0;",
            [
                $start,
                $end,
                $userid,
                MessageCollection::APPROVED
            ]
        );

        $count = $freegles[0]['count'];

        return [$start, $count];
    }
}

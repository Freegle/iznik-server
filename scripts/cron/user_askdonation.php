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

$loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/donations');
$twig = new Twig_Environment($loader);

$start = date('Y-m-d H:i', strtotime("yesterday 5pm"));
$end = date('Y-m-d H:i', strtotime('today 5pm'));
error_log("Look between $start and $end");

$d = new Donations($dbhr, $dbhm);

# Find the users who have received things.
$users = $dbhr->preQuery("SELECT DISTINCT userid, COUNT(*) AS count FROM messages_outcomes INNER JOIN users ON users.id = userid AND outcome = ? WHERE messages_outcomes.timestamp >= ? AND messages_outcomes.timestamp < ? GROUP BY userid ORDER BY count DESC;", [
    Message::OUTCOME_TAKEN,
    $start,
    $end
]);

$count = 0;

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $lastask = $d->lastAsk($user['userid']);
    $ours = $u->getOurEmail();
    
    if (!$lastask || time() - strtotime($lastask) > 7 * 24 * 60 * 60) {
        # Find the most recent message they have taken.  Only look for relatively recent messages, in case someone
        # marks an item as TAKEN much later when they next come to use the system.
        $mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
        $messages = $dbhr->preQuery("SELECT DISTINCT msgid, messages.date, subject FROM messages_outcomes INNER JOIN messages ON messages.id = messages_outcomes.msgid INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id AND chat_messages.type = ? WHERE outcome = ? AND chat_messages.userid = ? AND messages_outcomes.userid = ? AND messages_outcomes.userid != messages.fromuser AND messages.arrival >= '$mysqltime' ORDER BY messages_outcomes.timestamp DESC LIMIT 1;", [
            ChatMessage::TYPE_INTERESTED,
            Message::OUTCOME_TAKEN,
            $user['userid'],
            $user['userid']
        ]);
        
        foreach ($messages as $message) {
            $count++;
            $subj = $ours ? "Re: {$message['subject']}" : "Thanks for freegling!";
            error_log("{$user['userid']} " . $u->getName() . " " . $u->getEmailPreferred() . " {$message['msgid']} {$message['date']} {$message['subject']} => $subj");

            try {
                list ($transport, $mailer) = getMailer();
                $m = Swift_Message::newInstance()
                    ->setSubject($subj)
                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                    ->setReplyTo(NOREPLY_ADDR)
                    ->setTo($u->getEmailPreferred())
                    ->setBody($ours ? "Did you just get this from Freegle?\r\n\r\n{$message['subject']}\r\n\r\n(If we're wrong, just delete this message.)\r\n\r\n If you've not already, why not send a thanks to the person who gave it?  Just to be nice.\r\n\r\nFreegle is free to use, but it's not free to run.  This month we're trying to raise " . DONATION_TARGET . " to keep us going.\r\n\r\nIf you can, please donate &pound;1 through PayPal:\r\n\r\nhttp://freegle.in/paypal\r\n\r\nWe realise not everyone is able to do this - and that's fine.  Either way, thanks for freegling!\r\n" :
                        "Thank you for using your local Freegle group.\r\n\r\nFreegle is free to use, but it's not free to run.  This month we're trying to raise " . DONATION_TARGET . " to keep us going.\r\n\r\nIf you can, please donate &pound;1 through PayPal:\r\n\r\nhttp://freegle.in/paypal\r\n\r\nWe realise not everyone is able to do this - and that's fine.  Either way, thanks for freegling!\r\n"
                    );

                Mail::addHeaders($m, Mail::ASK_DONATION);

                $html = $twig->render('collected.html', [
                    'name' => $u->getName(),
                    'email' => $u->getEmailPreferred(),
                    'subject' => $message['subject'],
                    'target' => DONATION_TARGET,
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
            } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
        }

        $d->recordAsk($user['userid']);
    }
}
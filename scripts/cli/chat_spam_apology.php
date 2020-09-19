<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php chat_spam_apology.php -i <user id>\n";
} else {
    $uid = Utils::presdef('i', $opts, NULL);
    $u = User::get($dbhr, $dbhm, $uid);

    $mysqltime = date("Y-m-d H:i:s", strtotime("midnight 10 days ago"));
    $chats = $dbhr->preQuery("SELECT chat_rooms.id, user1, user2 FROM `chat_rooms` WHERE user1 = ? OR user2 = ? AND latestmessage >= '$mysqltime' AND flaggedspam = 1;", [
        $uid,
        $uid
    ]);

    foreach ($chats as $chat) {
        error_log("Chat with non-spammer {$chat['id']}");

        $innocent = new User($dbhr, $dbhm, ($chat['user1'] == $uid) ? $chat['user2'] : $chat['user1']);
        $spammer = new User($dbhr, $dbhm, $uid);

        # Find who to send the reply to.
        #
        # If we don't find anyone else, use Support.
        $replyto = SUPPORT_ADDR;
        $replyname = SITE_NAME;

        # Try to find a refmsg in this chat; that tells us the group.
        $c = new ChatRoom($dbhr, $dbhm, $chat['id']);
        $atts = $c->getPublic();
        $msgs = $atts['refmsgids'];

        $subject = NULL;

        if (count($msgs) > 0) {
            # The messages are in most recent first order.  Assume they're talking about the last one.
            $m = new Message($dbhr, $dbhm, $msgs[0]);
            $subject = $m->getSubject();
            $groups = $m->getGroups(TRUE, TRUE);

            foreach ($groups as $group) {
                $g = Group::get($dbhr, $dbhm, $group);
                $replyto = $g->getModsEmail();
                $replyname = $g->getName() . " Volunteers";
            }
        } else {
            # If we can't find the message, go with a membership.
            $membs = $innocent->getMemberships();

            if (count($membs) > 0) {
                # Pick some group they're on; we don't have much of a preference
                $g = Group::get($dbhr, $dbhm, $membs[0]['id']);
                $replyto = $g->getModsEmail();
                $replyname = $g->getName() . " Volunteers";
            }
        }

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $html = $twig->render('chat_spamapology.html', [
            'name' => $spammer->getName(),
            'email' => $innocent->getEmailPreferred(),
            'subject' => $subject,
            'unsubscribe' => $innocent->getUnsubLink(USER_SITE, $innocent->getId())
        ]);

        $text = "We're sorry!  We may have recently sent you a warning about " . $spammer->getName() . ".  This was a mistake, and there's nothing wrong with them at all.";

        $message = \Swift_Message::newInstance()
            ->setSubject("Correction: a message from " . SITE_NAME . " about " . $spammer->getName())
            ->setFrom([$replyto => $replyname])
            ->setTo($innocent->getEmailPreferred())
            ->setBody($text);

        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
        # Outlook.
        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        Mail::addHeaders($message, Mail::SPAM_WARNING, $innocent->getId());

        list ($transport, $mailer) = Mail::getMailer();
        $mailer->send($message);

        $dbhm->preExec("UPDATE chat_rooms SET flaggedspam = 0 WHERE id = ?;", [
            $chat['id']
        ]);
    }
}

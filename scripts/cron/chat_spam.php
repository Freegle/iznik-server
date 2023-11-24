<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

$lockh = Utils::lockScript(basename(__FILE__));

# Look for chat conversations with spammers and warn the members.
$mysqltime = date("Y-m-d H:i:s", strtotime("midnight 7 days ago"));
$chats = $dbhr->preQuery("SELECT chat_rooms.id, userid, user1, user2 FROM `chat_rooms` INNER JOIN spam_users ON user1 = userid WHERE latestmessage >= '$mysqltime' AND flaggedspam = 0 AND collection = ? UNION SELECT chat_rooms.id, userid, user1, user2 FROM `chat_rooms` INNER JOIN spam_users ON user2 = userid WHERE latestmessage >= '$mysqltime' AND flaggedspam = 0 AND collection = ?;", [
    Spam::TYPE_SPAMMER,
    Spam::TYPE_SPAMMER
]);

foreach ($chats as $chat) {
    error_log("Chat with spammer {$chat['id']}");

    # Check that there are some messages they'll actually have seen, otherwise we're warning them about nothing.
    $messages = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE chatid = ? AND reviewrequired = 0 AND reviewrejected = 0 AND (processingsuccessful = 1 OR processingrequired = 0);", [
        $chat['id']
    ]);

    if ($messages[0]['count'] > 0) {
        $innocent = new User($dbhr, $dbhm, ($chat['user1'] == $chat['userid']) ? $chat['user2'] : $chat['user1']);

        if (!Mail::ourDomain($innocent->getEmailPreferred()) && strpos($innocent->getEmailPreferred(), GROUP_DOMAIN) === FALSE) {
            $spammer = new User($dbhr, $dbhm, $chat['userid']);

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

            $html = $twig->render('chat_spamwarning.html', [
                'name' => $spammer->getName(),
                'email' => $innocent->getEmailPreferred(),
                'subject' => $subject,
                'unsubscribe' => $innocent->getUnsubLink(USER_SITE, $innocent->getId())
            ]);

            $text = "Be careful!  You've been talking to " . $spammer->getName() . ".  Our checks suggest that this person might be a scammer/spammer.\r\n\r\n" .
                "This person has been reported as a spammer or scammer. Please do not talk to them and under no circumstances send them any money. Do not arrange anything by courier.";

            $message = \Swift_Message::newInstance()
                ->setSubject("A warning from " . SITE_NAME . " about " . $spammer->getName())
                ->setFrom([ $replyto => $replyname])
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

            Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::SPAM_WARNING, $innocent->getId());

            list ($transport, $mailer) = Mail::getMailer();
            $mailer->send($message);

            $dbhm->preExec("UPDATE chat_rooms SET flaggedspam = 1 WHERE id = ?;", [
                $chat['id']
            ]);
        }
    }

# We look for users who are not whitelisted, and where we have marked multiple chat messages from them
# as spam.  Exclude messages automarked as spam.
    $users = $dbhr->preQuery("SELECT DISTINCT chat_messages.userid, COUNT(*) AS count FROM chat_messages LEFT JOIN spam_users ON spam_users.userid = chat_messages.userid INNER JOIN users ON users.id = chat_messages.userid WHERE reviewrejected = 1 AND (collection IS NULL OR collection != 'Whitelisted') AND systemrole = 'User' GROUP BY chat_messages.userid HAVING count > 5  ORDER BY count DESC;");
    $count = 0;

    foreach ($users as $user) {
        $u = new User($dbhr, $dbhm, $user['userid']);

        # Don't want to mark subsequent messages from a mod as spam.
        if (!$u->isModerator()) {
            # Check whether we have ever marked something from them as not spam.  If we have, then they might be being
            # spoofed and unlucky.  If not, these are almost certainly spammers, so we will auto mark any chat messages
            # currently held for review as spam.  We don't add them to the spammer list because removing someone from that
            # if it was a mistake is a pain.
            $ok = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE userid = ? AND reviewrequired = 1 AND reviewedby IS NOT NULL AND reviewrejected = 0;", [
                $user['userid']
            ]);

            #error_log("...{$user['userid']} ok count {$ok[0]['count']}");
            if ($ok[0]['count'] == 0) {
                $reviews = $dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE userid = ? AND reviewrequired = 1 AND reviewedby IS NULL;", [
                    $user['userid']
                ]);

                if ($reviews[0]['count'] > 0) {
                    error_log("...{$user['userid']} spam count {$user['count']} marked as spam, auto-mark {$reviews[0]['count']} pending review");
                    $count += $reviews[0]['count'];
                    $dbhm->preExec("UPDATE chat_messages SET reviewrequired = 0, processingrequired = 0, processingsuccessful = 0, reviewrejected = 1, reviewedby = NULL WHERE userid = ? AND reviewrequired = 1 AND reviewedby IS NULL;", [
                        $user['userid']
                    ]);
                }
            }
        }
    }
}

error_log("Auto-marked $count");

Utils::unlockScript($lockh);
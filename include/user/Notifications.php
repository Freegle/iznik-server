<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/mailtemplates/notifications/notificationsoff.php');

class Notifications
{
    const TYPE_COMMENT_ON_YOUR_POST = 'CommentOnYourPost';
    const TYPE_COMMENT_ON_COMMENT = 'CommentOnCommented';
    const TYPE_LOVED_POST = 'LovedPost';
    const TYPE_LOVED_COMMENT = 'LovedComment';
    const TYPE_TRY_FEED = 'TryFeed';
    const TYPE_ABOUT_ME = 'AboutMe';
    const TYPE_EXHORT = 'Exhort';
    const TYPE_GIFTAID = 'GiftAid';
    const TYPE_OPEN_POSTS = 'OpenPosts';

    private $dbhr, $dbhm, $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($dbhr, $dbhm);
    }

    public function countUnseen($userid) {
        # Don't count old notifications - if we are not going to respond to these then after a while it looks better
        # to stop nagging people about them.
        $mysqltime = date("Y-m-d", strtotime("Midnight 90 days ago"));
        $counts = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_notifications WHERE touser = ? AND seen = 0 AND timestamp >= ?;", [
            $userid,
            $mysqltime
        ]);
        return($counts[0]['count']);
    }

    private function snip(&$msg) {
        if ($msg) {
            if (strlen($msg) > 57) {
                $msg = wordwrap($msg, 60);
                $p = strpos($msg, "\n");
                $msg = $p !== FALSE ? substr($msg, 0, $p) : $msg;
                $msg .= '...';
            }
        }
    }

    public function get($userid, &$ctx) {
        $ret = [];
        $idq = $ctx && Utils::pres('id', $ctx) ? (" AND id < " . intval($ctx['id'])) : '';
        $sql = "SELECT users_notifications.* FROM users_notifications WHERE touser = ? $idq ORDER BY users_notifications.id DESC LIMIT 10;";
        $notifs = $this->dbhr->preQuery($sql, [ $userid ]);

        // Get all the users in one go for speed.
        $userids = array_filter(array_column($notifs, 'fromuser'));
        $users = [];

        if (count($userids)) {
            $u = new User($this->dbhr, $this->dbhm);
            $users = $u->getPublicsById($userids, NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
        }

        // Similarly newsfeed.
        $newsids = array_filter(array_column($notifs, 'newsfeedid'));
        $news = [];

        if (count($newsids)) {
            $threads = $this->dbhr->preQuery("SELECT * FROM newsfeed WHERE id IN (" . implode(',', $newsids) . ");");

            foreach ($threads as $thread) {
                $news[$thread['id']] = $thread;
            }

            $replyids = array_filter(array_column($threads, 'replyto'));

            if (count($replyids)) {
                $replies = $this->dbhr->preQuery("SELECT * FROM newsfeed WHERE id IN (" . implode(',', $replyids) . ");");

                foreach ($replies as $thread) {
                    $news[$thread['id']] = $thread;
                }
            }
        }

        foreach ($notifs as &$notif) {
            $notif['timestamp'] = Utils::ISODate($notif['timestamp']);

            if (Utils::pres('fromuser', $notif)) {
                $notif['fromuser'] = $users[$notif['fromuser']];
            }

            if (Utils::pres('newsfeedid', $notif)) {
                $not = $news[$notif['newsfeedid']];
                unset($not['position']);

                if ($not['type'] != Newsfeed::TYPE_NOTICEBOARD) {
                    $this->snip($not['message']);
                }

                if (Utils::pres('deleted', $not)) {
                    # This item has been deleted - don't show the corresponding notification.
                    if (!$notif['seen']) {
                        # This notification hasn't been seen, and would therefore show in the count. Mark it
                        # as seen for next time.
                        $this->dbhm->background("UPDATE users_notifications SET seen = 1 WHERE id = {$notif['id']}");
                    }

                    $notif = NULL;
                } else {
                    if ($not['replyto']) {
                        $orig = Utils::presdef($not['replyto'], $news, NULL);

                        if ($orig) {
                            # Thread we're replying to might have been deleted.
                            $this->snip($orig['message']);
                            unset($orig['position']);
                        }

                        $not['replyto'] = $orig;
                    }

                    unset($not['position']);
                    $notif['newsfeed'] = $not;

                    if (Utils::pres('deleted', $not['replyto'])) {
                        # This notification is for a newsfeed item which is in a deleted thread.  Don't show it.

                        if (!$notif['seen']) {
                            # This notification hasn't been seen, and would therefore show in the count. Mark it
                            # as seen for next time.
                            $this->dbhm->background("UPDATE users_notifications SET seen = 1 WHERE id = {$notif['id']}");
                        }

                        $notif = NULL;
                    }
                }
            }

            if ($notif) {
                $ret[] = $notif;

                $ctx = [
                    'id' => $notif['id']
                ];
            }
        }

        return($ret);
    }

    public function add($from, $to, $type, $newsfeedid, $newsfeedthreadid = NULL, $url = NULL, $title = NULL, $text = NULL) {
        $id = NULL;

        if ($from != $to) {
            $n = new Newsfeed($this->dbhr, $this->dbhm);

            # For newsfeed items, ensure we don't notify if we've unfollowed.
            if (!$newsfeedthreadid || !$n->unfollowed($to, $newsfeedthreadid)){
                $sql = "INSERT INTO users_notifications (`fromuser`, `touser`, `type`, `newsfeedid`, `url`, `title`, `text`) VALUES (?, ?, ?, ?, ?, ?, ?);";
                $this->dbhm->preExec($sql, [ $from, $to, $type, $newsfeedid, $url, $title, $text ]);
                $id = $this->dbhm->lastInsertId();

                $p = new PushNotifications($this->dbhr, $this->dbhm);
                $p->notify($to, FALSE);
            }
        }

        return($id);
    }

    public function seen($userid, $id = NULL) {
        $idq = $id ? (" AND id = " . intval($id)) : '';
        $sql = "UPDATE users_notifications SET seen = 1 WHERE touser = ? $idq;";
        $rc = $this->dbhm->preExec($sql, [ $userid ] );

        #error_log("Seen notify $userid");
        $p = new PushNotifications($this->dbhr, $this->dbhm);
        $p->notify($userid, Session::modtools());

        return($rc);
    }

    public function off($uid) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        # The user might not still exist.
        if ($u->getId() == $uid) {
            $settings = json_decode($u->getPrivate('settings'), TRUE);

            if (Utils::presdef('notificationmails', $settings, TRUE)) {
                $settings['notificationmails'] = FALSE;
                $u->setPrivate('settings', json_encode($settings));

                $this->log->log([
                    'type' => Log::TYPE_USER,
                    'subtype' => Log::SUBTYPE_NOTIFICATIONOFF,
                    'user' => $uid
                ]);

                $email = $u->getEmailPreferred();

                if ($email) {
                    list ($transport, $mailer) = Mail::getMailer();
                    $html = notifications_off(USER_SITE, USERLOGO);

                    $message = \Swift_Message::newInstance()
                        ->setSubject("Email Change Confirmation")
                        ->setFrom([NOREPLY_ADDR => SITE_NAME])
                        ->setReturnPath($u->getBounce())
                        ->setTo([ $email => $u->getName() ])
                        ->setBody("Thanks - we've turned off the mails for notifications.");

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = \Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($html);
                    $message->attach($htmlPart);

                    Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NOTIFICATIONS_OFF, $u->getId());

                    $this->sendIt($mailer, $message);
                }
            }
        }
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function sendEmails($userid = NULL, $before = '24 hours ago', $since = '7 days ago', $unseen = TRUE, $mailed = TRUE) {
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $userq = $userid ? " AND `touser` = $userid " : '';

        $mysqltime = date("Y-m-d H:i:s", strtotime($before));
        $mysqltime2 = date("Y-m-d H:i:s", strtotime($since));
        $seenq = $unseen ? " AND seen = 0 ": '';
        $mailq = $mailed ? " AND mailed = 0 " : '';
        $sql = "SELECT DISTINCT(touser) FROM `users_notifications`
                        LEFT JOIN spam_users ON spam_users.userid = users_notifications.fromuser AND collection IN (?, ?)
                        WHERE timestamp <= '$mysqltime' AND timestamp >= '$mysqltime2' $seenq $mailq AND `type` NOT IN (?, ?, ?, ?) 
                                                                            AND spam_users.id IS NULL $userq;";
        $users = $this->dbhr->preQuery($sql, [
            Spam::TYPE_SPAMMER,
            Spam::TYPE_PENDING_ADD,
            Notifications::TYPE_TRY_FEED,
            Notifications::TYPE_ABOUT_ME,
            Notifications::TYPE_GIFTAID,
            Notifications::TYPE_OPEN_POSTS
        ]);

        $total = 0;

        foreach ($users as $user) {
            $u = new User($this->dbhr, $this->dbhm, $user['touser']);
            #error_log("Consider {$user['touser']} email " . $u->getEmailPreferred());

            if ($u->sendOurMails() && $u->getSetting('notificationmails', TRUE)) {
                $ctx = NULL;
                $notifs = $this->get($user['touser'], $ctx);

                $subj = $this->getNotifTitle($notifs, $unseen);

                # Collect the info we need for the twig template.
                $twignotifs = [];

                foreach ($notifs as &$notif) {
                    $this->dbhm->preExec("UPDATE users_notifications SET mailed = 1 WHERE id = ?;", [
                        $notif['id']
                    ]);

                    if ((!$unseen || !$notif['seen']) && $notif['type'] != Notifications::TYPE_TRY_FEED) {
                        #error_log("Message is {$notif['newsfeed']['message']} len " . strlen($notif['newsfeed']['message']));
                        $fromname = ($notif['fromuser'] ? "{$notif['fromuser']['displayname']}" : "Someone");
                        $notif['fromname'] = $fromname;
                        $twignotifs[] = $notif;
                    }

                    if (Utils::pres('newsfeed', $notif) && Utils::pres('replyto', $notif['newsfeed']) && Utils::pres('message', $notif['newsfeed']['replyto'])) {
                        $this->snip($notif['newsfeed']['replyto']['message']);
                    }
                }

                $url = $u->loginLink(USER_SITE, $user['touser'], '/chitchat', 'notifemail');
                $noemail = 'notificationmailsoff-' . $user['touser'] . "@" . USER_DOMAIN;
                $email = $u->getEmailPreferred();

                if ($email) {
                    try {
                        $html = $twig->render('notifications/email.html', [
                            'count' => count($twignotifs),
                            'notifications'=> $twignotifs,
                            'settings' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_NOTIFICATIONS_EMAIL),
                            'email' => $email,
                            'noemail' => $noemail
                        ]);

                        $message = \Swift_Message::newInstance()
                            ->setSubject($subj)
                            ->setFrom([NOREPLY_ADDR => 'Freegle'])
                            ->setReturnPath($u->getBounce())
                            ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                            ->setBody("\r\n\r\nPlease click here to read them: $url");

                        # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                        # Outlook.
                        $htmlPart = \Swift_MimePart::newInstance();
                        $htmlPart->setCharset('utf-8');
                        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                        $htmlPart->setContentType('text/html');
                        $htmlPart->setBody($html);
                        $message->attach($htmlPart);

                        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::NOTIFICATIONS, $u->getId());

                        list ($transport, $mailer) = Mail::getMailer();
                        $this->sendIt($mailer, $message);

                        $total += count($twignotifs);
                    } catch (\Exception $e) {
                        error_log("Message failed with " . $e->getMessage());
                    }
                }
            }
        }

        return($total);
    }

    public function getNotifTitle(&$notifs, $unseen = TRUE) {
        # We try to make the subject more enticing if we can.  End-user content from other users is the
        # most tantalising.
        $title = '';
        $count = 0;

        foreach ($notifs as &$notif) {
            if ((!$unseen || !$notif['seen']) && $notif['type'] != Notifications::TYPE_TRY_FEED) {
                #error_log("Message is {$notif['newsfeed']['message']} len " . strlen($notif['newsfeed']['message']));
                $fromname = ($notif['fromuser'] ? "{$notif['fromuser']['displayname']}" : "Someone");
                $notif['fromname'] = $fromname;
                $notif['timestamp'] = date("D, jS F g:ia", strtotime($notif['timestamp']));
                $twignotifs[] = $notif;
                
                $shortmsg = NULL;

                if (Utils::pres('newsfeed', $notif) && Utils::pres('message', $notif['newsfeed']) && Utils::pres('type', $notif['newsfeed']) !== Newsfeed::TYPE_NOTICEBOARD) {
                    $notifmsg = $notif['newsfeed']['message'];
                    $shortmsg = strlen($notifmsg > 30) ? (substr($notifmsg, 0, 30) . "...") : $notifmsg;
                }

                # We prioritise end-user content because that's more engaging.
                switch ($notif['type']) {
                    case Notifications::TYPE_COMMENT_ON_COMMENT:
                        $title = $fromname . " replied: $shortmsg";
                        $count++;
                        break;
                    case Notifications::TYPE_COMMENT_ON_YOUR_POST:
                        $title = $fromname . " commented: $shortmsg";
                        $count++;
                        break;
                    case Notifications::TYPE_LOVED_POST:
                        if (!$title) {
                            $title = $fromname . " loved your post " . ($shortmsg ? "'$shortmsg'" : '');
                        }
                        $count++;
                        break;
                     case Notifications::TYPE_LOVED_COMMENT:
                         if (!$title) {
                             $title = $fromname . " loved your comment " . ($shortmsg ? "'$shortmsg'" : '');
                         }
                         $count++;
                         break;
                    case Notifications::TYPE_ABOUT_ME:
                        if (!$title) {
                            $title = "Why not introduce yourself to other freeglers?  You'll get a better response.";
                        }
                        $count++;
                        break;
                    case Notifications::TYPE_EXHORT:
                        if (!$title) {
                            $title = $notif['title'];
                        }
                        $count++;
                        break;
                    default: {
                        error_log("Notification type {$notif['type']} not handled");
                    }
                }
            }
        }

        if ($count) {
            $title = ($count ==  1) ? $title : ("$title +" . ($count - 1) . " more...");
        }

        return($title);
    }

    public function haveSent($uid, $type, $since) {
        $mysqltime = date("Y-m-d H:i:s", strtotime($since));
        $notifs = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_notifications WHERE touser = ? AND type = ? AND timestamp >= ?;", [
            $uid,
            $type,
            $mysqltime
        ]);

        $count = $notifs[0]['count'];
        return($count > 0);
    }

    public function deleteOldUserType($uid, $type, $age = "Midnight 3 days ago") {
        $mysqltime = date("Y-m-d", strtotime($age));

        $this->dbhr->preExec("DELETE FROM users_notifications WHERE touser = ? AND type = ? AND timestamp <= ?;", [
            $uid,
            $type,
            $mysqltime
        ]);

        $existing = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM users_notifications WHERE touser = ? AND type = ?;", [
            $uid,
            $type,
        ]);

        return $existing[0]['count'];
    }
}

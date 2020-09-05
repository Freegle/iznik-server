<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');

class Engage
{
    private $dbhr, $dbhm;

    const USER_INACTIVE = 365 * 24 * 60 * 60 / 2;
    const LOOKBACK = 31;

    const FILTER_INACTIVE = 'Inactive';

    const ATTEMPT_MISSING = 'Missing';
    const ATTEMPT_INACTIVE = 'Inactive';

    const ENGAGEMENT_NEW = 'New';
    const ENGAGEMENT_OCCASIONAL = 'Occasional';
    const ENGAGEMENT_FREQUENT = 'Frequent';
    const ENGAGEMENT_OBSESSED = 'Obsessed';
    const ENGAGEMENT_INACTIVE = 'Inactive';
    const ENGAGEMENT_DORMANT = 'Dormant';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $loader = new Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig/engage');
        $this->twig = new Twig_Environment($loader);
    }
    # Split out for UT to override

    public function sendOne($mailer, $message) {
        Mail::addHeaders($message, Mail::MISSING);
        $mailer->send($message);
    }

    public function findUsersByFilter($id = NULL, $filter, $limit = NULL) {
        $userids = [];
        $limq = $limit ? " LIMIT $limit " : "";

        switch ($filter) {
            case Engage::FILTER_INACTIVE: {
                # Find people who we'll stop sending mails to soon.  This time is related to sendOurMails in User.
                $activeon = date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE + 7 * 24 * 60 * 60)));
                $uq = $id ? " AND users.id = $id " : "";
                $sql = "SELECT id FROM users WHERE DATE(lastaccess) = ? $uq $limq;";
                $users = $this->dbhr->preQuery($sql, [
                    $activeon
                ]);

                $userids = array_column($users, 'id');
                break;
            }
        }

        return $userids;
    }

    public function findUsersByEngagement($id = NULL, $engagement, $limit = NULL) {
        $uq = $id ? " users.id = $id AND " : "";
        $limq = $limit ? " LIMIT $limit " : "";

        $sql = "SELECT id FROM users WHERE $uq engagement = ? $limq;";
        $users = $this->dbhr->preQuery($sql, [
            $engagement
        ]);

        $userids = array_column($users, 'id');
        return $userids;
    }

    private function setEngagement($id, $engagement, &$count) {
        #error_log("Set engagement $id = $engagement");
        $count++;
        $this->dbhm->preExec("UPDATE users SET engagement = ? WHERE id = ?;", [
            $engagement,
            $id
        ], FALSE, FALSE);
    }

    private function postsSince($id, $time) {
        $messages = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser = ? AND messages.arrival >= ?;", [
            $id,
            date("Y-m-d", strtotime($time))
        ]);

        #error_log("Posts since {$messages[0]['count']}");
        return $messages[0]['count'];
    }

    private function lastPostOrReply($id) {
        $ret = NULL;

        $reply = $this->dbhr->preQuery("SELECT MAX(date) AS date FROM chat_messages WHERE userid = ?;", [
            $id
        ]);

        if (count($reply)) {
            $ret = $reply[0]['date'];
        }

        $messages = $this->dbhr->preQuery("SELECT MAX(messages.arrival) AS date FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE fromuser = ?;", [
            $id
        ]);

        if (count($messages)) {
            if (!$ret || (strtotime($messages[0]['date']) > strtotime($ret))) {
                $ret = $messages[0]['date'];
            }
        }

        return $ret;
    }

    private function engagementProgress($total, &$count) {
        $count++;

        if ($count % 1000 == 0) { error_log("...$count / $total"); }
    }

    public function updateEngagement($id = NULL) {
        $ret = 0;
        $uq = $id ? " users.id = $id AND " : "";

        $lookback = date("Y-m-d", strtotime("midnight " . Engage::LOOKBACK . " days ago"));

        # Set new users
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq added >= ? AND engagement IS NULL ;", [
            $lookback
        ]);

        error_log("NULL => New " . count($users));

        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            $this->setEngagement($user['id'], Engage::ENGAGEMENT_NEW, $ret);
        }

        # NULL => Inactive
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq engagement IS NULL ;");

        error_log("NULL => Inactive " . count($users));

        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            $this->setEngagement($user['id'], Engage::ENGAGEMENT_INACTIVE, $ret);
        }

        # New, Occasional => Inactive.
        $mysqltime = date("Y-m-d", strtotime("midnight 14 days ago"));
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq (lastaccess IS NULL OR lastaccess < ?) AND (engagement IS NULL OR engagement = ? OR engagement = ?);", [
            $mysqltime,
            Engage::ENGAGEMENT_NEW,
            Engage::ENGAGEMENT_OCCASIONAL
        ]);

        error_log("New, Occasional => Inactive " . count($users));

        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            $this->setEngagement($user['id'], Engage::ENGAGEMENT_INACTIVE, $ret);
        }

        # Inactive => Dormant.
        $activeon = date("Y-m-d", strtotime("@" . (time() - Engage::USER_INACTIVE)));
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq (lastaccess IS NULL OR lastaccess < ?) AND engagement != ?;", [
            $activeon,
            self::ENGAGEMENT_DORMANT
        ]);

        error_log("Inactive => Dormant " . count($users));

        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            $this->setEngagement($user['id'], Engage::ENGAGEMENT_DORMANT, $ret);
        }

        # New, Inactive, Dormant => Occasional.
        $activeon = date("Y-m-d", strtotime("midnight 14 days ago"));
        $users = $this->dbhr->preQuery("SELECT DISTINCT userid AS id FROM chat_messages 
    INNER JOIN users ON users.id = chat_messages.userid 
    WHERE $uq chat_messages.date >= ? AND (engagement IS NULL OR engagement = ? OR engagement = ?  OR engagement = ?)
    UNION
    SELECT DISTINCT fromuser AS id FROM messages 
    INNER JOIN users ON users.id = messages.fromuser
    WHERE $uq messages.arrival >= ? AND (engagement IS NULL OR engagement = ? OR engagement = ?  OR engagement = ?);", [
            $activeon,
            Engage::ENGAGEMENT_NEW,
            Engage::ENGAGEMENT_INACTIVE,
            Engage::ENGAGEMENT_DORMANT,
            $activeon,
            Engage::ENGAGEMENT_NEW,
            Engage::ENGAGEMENT_INACTIVE,
            Engage::ENGAGEMENT_DORMANT,
        ]);

        error_log("New, Inactive, Dormant => Occasional " . count($users));
        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            $active = $this->lastPostOrReply($user['id']);
            #error_log("Last active $active");
            if ($active && strtotime($active) > time() - 14 * 24 * 60 *60) {
                $this->setEngagement($user['id'], Engage::ENGAGEMENT_OCCASIONAL, $ret);
            }
        }

        # Occasional => Frequent.
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq engagement = ?;", [
            Engage::ENGAGEMENT_OCCASIONAL
        ]);

        error_log("Occasional => Frequent " . count($users));
        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            if ($this->postsSince($user['id'], "90 days ago") > 3) {
                $this->setEngagement($user['id'], Engage::ENGAGEMENT_FREQUENT, $ret);
            }
        }

        # Frequent => Obsessed.
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq engagement = ?;", [
            Engage::ENGAGEMENT_FREQUENT
        ]);

        error_log("Frequent => Obsessed " . count($users));
        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            if ($this->postsSince($user['id'], "31 days ago") >= 4) {
                $this->setEngagement($user['id'], Engage::ENGAGEMENT_OBSESSED, $ret);
            }
        }

        # Obsessed => Frequent.
        $users = $this->dbhr->preQuery("SELECT id FROM users WHERE $uq engagement = ?;", [
            Engage::ENGAGEMENT_OBSESSED
        ]);

        error_log("Obsessed => Frequent " . count($users));
        $count = 0;
        foreach ($users as $user) {
            $this->engagementProgress(count($users), $count);
            if ($this->postsSince($user['id'], "90 days ago") <= 3) {
                $this->setEngagement($user['id'], Engage::ENGAGEMENT_FREQUENT, $ret);
            }
        }
    }

    public function sendUsers($attempt, $uids, $subject, $textbody, $template) {
        $count = 0;

        foreach ($uids as $uid) {
            $u = new User($this->dbhr, $this->dbhm, $uid);
            error_log("Consider $uid");

            # Only send to users who have not turned off this kind of mail.
            if ($u->getPrivate('relevantallowed')) {
                error_log("...allowed by user");
                try {
                    // ...and who have a membership.
                    $membs = $u->getMemberships(FALSE, Group::GROUP_FREEGLE);

                    if (count($membs)) {
                        // ...and where that group allows engagement.
                        error_log("...has membership");
                        $allowed = FALSE;

                        foreach ($membs as $memb) {
                            if (!pres('engagement', $memb['settings']) || $memb['settings']['engagement']) {
                                $allowed = TRUE;
                                error_log("...allowed by a group");
                            }
                        }

                        if ($allowed) {
                            // ...and where we've not tried them in the last week.
                            $last = $this->dbhr->preQuery("SELECT MAX(timestamp) AS last FROM engage WHERE userid = ?;", [
                                $uid
                            ]);

                            if (!$last[0]['last'] || (time() - strtotime($last[0]['last']) > 7 * 24 * 60 * 60)) {
                                error_log("...not tried recently");
                                $eid = $this->recordEngage($uid, $attempt, $u->getPrivate('engagement'));

                                list ($transport, $mailer) = getMailer();
                                $m = Swift_Message::newInstance()
                                    ->setSubject($subject)
                                    ->setFrom([NOREPLY_ADDR => SITE_NAME])
                                    ->setReplyTo(NOREPLY_ADDR)
                                    ->setTo($u->getEmailPreferred())
//                        ->setTo('log@ehibbert.org.uk')
                                    ->setBody($textbody);

                                Mail::addHeaders($m, Mail::MISSING);

                                $html = $this->twig->render($template, [
                                    'name' => $u->getName(),
                                    'email' => $u->getEmailPreferred(),
                                    'subject' => $subject,
                                    'unsubscribe' => $u->loginLink(USER_SITE, $u->getId(), "/unsubscribe", NULL),
                                    'engageid' => $eid
                                ]);

                                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                # Outlook.
                                $htmlPart = Swift_MimePart::newInstance();
                                $htmlPart->setCharset('utf-8');
                                $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                $htmlPart->setContentType('text/html');
                                $htmlPart->setBody($html);
                                $m->attach($htmlPart);

                                $this->sendOne($mailer, $m);
                                $count++;
                            }
                        }
                    }
                } catch (Exception $e) { error_log("Failed " . $e->getMessage()); };
            }
        }

        return $count;
    }

    public function recordEngage($userid, $attempt, $engagement) {
        $this->dbhm->preExec("INSERT INTO engage (userid, type, engagement, timestamp) VALUES (?, ?, ?, NOW());", [
            $userid,
            $attempt,
            $engagement
        ]);

        return $this->dbhm->lastInsertId();
    }

    public function recordSuccess($id) {
        $this->dbhm->preExec("UPDATE engage SET succeeded = NOW() WHERE id = ?;", [
            $id
        ]);
    }

    public function process($id = NULL) {
        $count = 0;

        # Inactive users
        #
        # First the ones who will shortly become dormant.
        $uids = $this->findUsersByFilter($id, Engage::FILTER_INACTIVE);
        $count += $this->sendUsers(Engage::ATTEMPT_INACTIVE, $uids, "We'll stop sending you emails soon...", "It looks like you’ve not been active on Freegle for a while. So that we don’t clutter your inbox, and to reduce the load on our servers, we’ll stop sending you emails soon.

If you’d still like to get them, then just go to www.ilovefreegle.org and log in to keep your account active.

Maybe you’ve got something lying around that someone else could use, or perhaps there’s something someone else might have?", 'inactive.html');

        # Then the other inactive ones.
        $uids = $this->findUsersByEngagement($id, Engage::ENGAGEMENT_INACTIVE, 1000);
        $count += $this->sendUsers(Engage::ATTEMPT_MISSING, $uids, "We miss you!", "We don't think you've freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org", 'missing.html');

        return $count;
    }
}

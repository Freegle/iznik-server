<?php
namespace Freegle\Iznik;



class ChatMessage extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'chatid', 'userid', 'date', 'message', 'system', 'refmsgid', 'type', 'seenbyall', 'mailedtoall', 'reviewrequired', 'processingrequired', 'processingsuccessful', 'reviewedby', 'reviewrejected', 'spamscore', 'reportreason', 'refchatid', 'imageid', 'replyexpected', 'replyreceived', 'deleted');
    var $settableatts = array('name');

    const TYPE_DEFAULT = 'Default';
    const TYPE_MODMAIL = 'ModMail';
    const TYPE_SYSTEM = 'System';
    const TYPE_INTERESTED = 'Interested';
    const TYPE_PROMISED = 'Promised';
    const TYPE_RENEGED = 'Reneged';
    const TYPE_REPORTEDUSER = 'ReportedUser';
    const TYPE_COMPLETED = 'Completed';
    const TYPE_IMAGE = 'Image';
    const TYPE_ADDRESS = 'Address';
    const TYPE_NUDGE = 'Nudge';
    const TYPE_REMINDER = 'Reminder';

    const ACTION_APPROVE = 'Approve';
    const ACTION_APPROVE_ALL_FUTURE = 'ApproveAllFuture';
    const ACTION_REJECT = 'Reject';
    const ACTION_HOLD = 'Hold';
    const ACTION_RELEASE = 'Release';
    const ACTION_REDACT = 'Redact';
    const ACTION_DELETE = 'Delete';

    const TOO_MANY_RECENT = 40;

    const REVIEW_LAST = 'Last';
    const REVIEW_FORCE = 'Force';
    const REVIEW_FULLY = 'Fully';
    const REVIEW_TOO_MANY = 'TooMany';
    const REVIEW_USER = 'User';
    const REVIEW_UNKNOWN_MESSAGE = 'UnknownMessage';
    const REVIEW_SPAM = 'Spam';

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'chat_messages', 'chatmessage', $this->publicatts, $fetched);
        $this->log = new Log($dbhr, $dbhm);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function whitelistURLs($message) {
        if (preg_match_all(Utils::URL_PATTERN, $message, $matches)) {
            $myid = Session::whoAmId($this->dbhr, $this->dbhm);

            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $bad = FALSE;
                    $url2 = str_replace('http:', '', $url);
                    $url2 = str_replace('https:', '', $url2);
                    foreach (Utils::URL_BAD as $badone) {
                        if (strpos($url2, $badone) !== FALSE) {
                            $bad = TRUE;
                        }
                    }

                    #error_log("Whitelist $url bad $bad");
                    if (!$bad && strlen($url) > 0) {
                        $url = substr($url, strpos($url, '://') + 3);
                        $p = strpos($url, '/');
                        $domain = $p ? substr($url, 0, $p) : $url;
                        $this->dbhm->preExec("INSERT INTO spam_whitelist_links (userid, domain) VALUES (?, ?) ON DUPLICATE KEY UPDATE count = count + 1;", [
                            $myid,
                            $domain
                        ]);
                    }
                }
            }
        }
    }

    public function checkReview($message, $language = FALSE, $userid = NULL) {
        $s = new Spam($this->dbhr, $this->dbhm);
        $ret = $s->checkReview($message, $language) ? self::REVIEW_SPAM : NULL;

        if (!$ret && $userid) {
            # Check whether this member has sent a lot of chat messages in the last couple of days.  This is something
            # which scammers sometimes do.
            $mysqltime = date("Y-m-d", strtotime("48 hours ago"));
            $counts = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(chatid)) AS count FROM chat_messages 
                                        INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid 
                                        WHERE userid = ? AND date > '$mysqltime' AND chat_rooms.chattype = ?
                                        AND chat_messages.type IN (?, ?, ?, ?)", [
                $userid,
                ChatRoom::TYPE_USER2USER,
                ChatMessage::TYPE_DEFAULT,
                ChatMessage::TYPE_INTERESTED,
                ChatMessage::TYPE_IMAGE,
                ChatMessage::TYPE_NUDGE
            ]);

            if ($counts[0]['count'] > self::TOO_MANY_RECENT) {
                $ret = self::REVIEW_TOO_MANY;
            }
        }

        return($ret);
    }

    public function checkSpam($message) {
        $s = new Spam($this->dbhr, $this->dbhm);
        list ($spam, $reason, $text) = $s->checkSpam($message, [ Spam::ACTION_SPAM ]);
        return $spam ? $reason : NULL;
    }

    public function chatByEmail($chatmsgid, $msgid) {
        if ($chatmsgid && $msgid) {
            # We record the link between a chat message an originating email in case we need it when reviewing in chat.
            $this->dbhm->preExec("INSERT INTO chat_messages_byemail (chatmsgid, msgid) VALUES (?, ?);", [
                $chatmsgid, $msgid
            ]);
        }
    }

    public function checkDup($chatid, $userid, $message, $type = ChatMessage::TYPE_DEFAULT, $refmsgid = NULL, $platform = TRUE, $spamscore = NULL, $reportreason = NULL, $refchatid = NULL, $imageid = NULL, $facebookid = NULL) {
        $dup = NULL;

        # Check last message in the chat to see whether we have a duplicate.
        $lasts = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1;", [
            $chatid
        ]);

        foreach ($lasts as $last) {
            if ($userid == $last['userid'] &&
                $type == $last['type'] &&
                $last['message'] == $message &&
                $refmsgid == $last['refmsgid'] &&
                $refchatid == $last['refchatid'] &&
                $imageid == $last['imageid'] &&
                $facebookid == $last['facebookid']) {
                $dup = $last['id'];
            }
        }

        return($dup);
    }

    private function processFailed() {
        $this->dbhm->preExec("UPDATE chat_messages SET processingrequired = 0, processingsuccessful = 0 WHERE id = ?", [
            $this->id
        ]);
    }

    public function process($forcereview = FALSE, $suppressmodnotif = FALSE) {
        # Process a chat message which was created with processingrequired = 1.  By doing this stuff
        # in the background we can keep chat message creation fast.
        #
        # First, expand any URLs which are redirects.  This mitigates use by spammers of shortening services and
        # is required by Validity for email certification.
        $message = $this->chatmessage['message'];
        $expanded = $message;

        $u = User::get($this->dbhr, $this->dbhm, $this->chatmessage['userid']);

        if (!$u->isModerator()) {
            $s = new Shortlink($this->dbhr, $this->dbhm);
            $expanded = $s->expandAllUrls($expanded);

            if ($expanded != $message) {
                $this->dbhm->preExec("UPDATE chat_messages SET message = ? WHERE id = ?;", [
                    $expanded,
                    $this->id
                ]);

                $this->chatmessage['message'] = $expanded;
            }
        }

        $id = $this->id;
        $chatid = $this->chatmessage['chatid'];
        $userid = $this->chatmessage['userid'];
        $message = $this->chatmessage['message'];
        $type = $this->chatmessage['type'];
        $refmsgid = $this->chatmessage['refmsgid'];
        $platform = $this->chatmessage['platform'];

        if ($refmsgid) {
            # If $userid is banned on the group that $refmsgid is on, then we shouldn't create a message.
            $banned = $this->dbhr->preQuery("SELECT users_banned.* FROM messages_groups INNER JOIN users_banned ON messages_groups.msgid = ? AND messages_groups.groupid = users_banned.groupid AND users_banned.userid = ?", [
                $refmsgid,
                $userid
            ]);

            if (count($banned) > 0) {
                $this->processFailed();
                return FALSE;
            }
        }

        # We might have been asked to force this to go to review.
        $review = 0;
        $reviewreason = NULL;
        $spam = 0;
        $blocked = FALSE;

        $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
        $chattype = $r->getPrivate('chattype');

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        if ($chattype == ChatRoom::TYPE_USER2USER) {
            # Check whether the sender is banned on all the groups they have in common with the recipient.  If so
            # then they shouldn't be able to send a message.
            $otheru = $r->getPrivate('user1') == $userid ? $r->getPrivate('user2') : $r->getPrivate('user1');
            $s = new Spam($this->dbhr, $this->dbhm);
            $banned = $r->bannedInCommon($userid, $otheru) || $s->isSpammerUid($userid);

            if ($banned) {
                $this->processFailed();
                return FALSE;
            }

            $modstatus = $u->getPrivate('chatmodstatus');

            if ($s->isSpammerUid($userid, Spam::TYPE_SPAMMER) ||
                $s->isSpammerUid($userid, Spam::TYPE_PENDING_ADD)) {
                # If the user is a spammer (confirmed or pending) hold their messages so that they don't get through
                # before the spam report is processed.
                $reviewreason = self::REVIEW_SPAM;
                $review = 1;
            } else if ($forcereview && $modstatus !== User::CHAT_MODSTATUS_UNMODERATED) {
                $reviewreason = self::REVIEW_FORCE;
                $review = 1;
            } else {
                # Mods may need to refer to spam keywords in replies.  We should only check chat messages of types which
                # include user text.
                #
                # We also don't want to check for spam in chats between users and mods.
                if ($modstatus == User::CHAT_MODSTATUS_MODERATED || $modstatus == User::CHAT_MODSTATUS_FULLY) {
                    if ($chattype != ChatRoom::TYPE_USER2MOD &&
                        !$u->isModerator() &&
                        ($type ==  ChatMessage::TYPE_DEFAULT || $type ==  ChatMessage::TYPE_INTERESTED || $type ==  ChatMessage::TYPE_REPORTEDUSER || $type ==  ChatMessage::TYPE_ADDRESS)) {
                        if ($modstatus == User::CHAT_MODSTATUS_FULLY) {
                            $reviewreason = self::REVIEW_FULLY;
                            $review = $reviewreason ? 1 : 0;
                        } else {
                            $reviewreason = $this->checkReview($message, TRUE, $userid);
                            $review = $reviewreason ? 1 : 0;
                        }

                        $spam = $this->checkSpam($message) || $this->checkSpam($u->getName());

                        # If we decided it was spam then it doesn't need reviewing.
                        if ($spam) {
                            $review = 0;
                            $reviewreason = NULL;
                        }
                    }

                    if (!$review && $type ==  ChatMessage::TYPE_INTERESTED && $refmsgid) {
                        # Check if this user is suspicious, e.g. replying to many messages across a large area.
                        $msg = $this->dbhr->preQuery("SELECT lat, lng FROM messages WHERE id = ?;", [
                            $refmsgid
                        ]);

                        foreach ($msg as $m) {
                            $s = new Spam($this->dbhr, $this->dbhm);

                            # Don't check memberships otherwise they might show up repeatedly.
                            if ($s->checkUser($userid, NULL, $m['lat'], $m['lng'], FALSE)) {
                                $reviewreason = self::REVIEW_USER;
                                $review = TRUE;
                            }
                        }
                    }
                }
            }

            if (!$review) {
                # If the last message in this chat is held for review, then hold this one too.
                # This includes chats by any user.  For example, suppose we add a Mod Note to a user2user chat - we don't
                # want to send out that chat until the messages that triggered it have been reviewed.
                $last = $this->dbhr->preQuery("SELECT reviewrequired FROM chat_messages WHERE chatid = ? AND id != ? ORDER BY id DESC LIMIT 1;", [
                    $chatid,
                    $id
                ]);

                if (count($last) && $last[0]['reviewrequired']) {
                    $reviewreason = self::REVIEW_LAST;
                    $review = 1;
                }
            }

            if ($review && $type ==  ChatMessage::TYPE_INTERESTED) {
                $m = new Message($this->dbhr, $this->dbhm,  $refmsgid);

                if (!$refmsgid || $m->hasOutcome()) {
                    # This looks like spam, and it claims to be a reply - but not to a message we can identify,
                    # or to one already complete.  We get periodic floods of these in spam attacks.
                    $spam = 1;
                    $review = 0;
                    $reviewreason = self::REVIEW_UNKNOWN_MESSAGE;
                }
            }
        }

        # We have now done the processing, so update the message with the results and make it visible to
        # either the recipient or mods, depending on reviewrequired.
        $this->dbhm->preExec("UPDATE chat_messages SET reviewrequired = ?, reportreason = ?, reviewrejected = ?, processingrequired = 0, processingsuccessful = 1 WHERE id = ?;", [
            $review,
            $reviewreason,
            $spam ? 1 : 0,
            $this->id
        ]);

        if (!$platform) {
            # Reply by email.  We have obviously seen this message ourselves, but there might be earlier messages
            # in the chat from other users which we have not seen because they have not yet been notified to us.
            #
            # In this case we leave the message unseen.  That means we may notify and include this message itself,
            # but that will look OK in context.
            $earliers = $this->dbhr->preQuery("SELECT chat_messages.id, chat_messages.userid, lastmsgseen, lastmsgemailed FROM chat_messages 
LEFT JOIN chat_roster ON chat_roster.id = chat_messages.id AND chat_roster.userid = ? 
WHERE chat_messages.chatid = ? AND chat_messages.userid != ? AND seenbyall = 0 AND mailedtoall = 0 ORDER BY id DESC LIMIT 1;", [
                $userid,
                $chatid,
                $userid
            ]);

            $count = 0;

            foreach ($earliers as $earlier) {
                if ($earlier['lastmsgseen'] < $earlier['id'] && $earlier['lastmsgemailed'] < $earlier['id']) {
                    $count++;
                }
            }

            if (!$count) {
                $this->dbhm->preExec("UPDATE chat_roster SET lastmsgseen = ?, lastmsgemailed = ? WHERE chatid = ? AND userid = ? AND (lastmsgseen IS NULL OR lastmsgseen < ?);",
                                     [
                                         $id,
                                         $id,
                                         $chatid,
                                         $userid,
                                         $id
                                     ]);
            }
        } else {
            # We have ourselves seen this message, and because we sent it from the platform we have had a chance
            # to see any others.
            #
            # If we're configured to email our own messages, we want to leave it unseen for the chat digest.
            if (!$u->notifsOn(User::NOTIFS_EMAIL_MINE)) {
                $this->dbhm->preExec("UPDATE chat_roster SET lastmsgseen = ?, lastmsgemailed = ? WHERE chatid = ? AND userid = ? AND (lastmsgseen IS NULL OR lastmsgseen < ?);",
                                     [
                                         $id,
                                         $id,
                                         $chatid,
                                         $userid,
                                         $id
                                     ]);
            }
        }

        $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
        $r->updateMessageCounts();

        # Update the reply time now we've replied.
        $r->replyTime($userid, TRUE);

        if ($chattype == ChatRoom::TYPE_USER2USER || $chattype == ChatRoom::TYPE_USER2MOD) {
            # If anyone has closed this chat so that it no longer appears in their list, we want to open it again.
            # If they have blocked it, we don't want to notify them.
            #
            # This is rare, so rather than do an UPDATE which would always be a bit expensive even if we have
            # nothing to do, we do a SELECT to see if there are any.
            $closeds = $this->dbhr->preQuery("SELECT id, status FROM chat_roster WHERE chatid = ? AND status IN (?, ?);", [
                $chatid,
                ChatRoom::STATUS_CLOSED,
                ChatRoom::STATUS_BLOCKED
            ]);

            foreach ($closeds as $closed) {
                if ($closed['status'] == ChatRoom::STATUS_CLOSED) {
                    $this->dbhm->preExec("UPDATE chat_roster SET status = ? WHERE id = ?;", [
                        ChatRoom::STATUS_OFFLINE,
                        $closed['id']
                    ]);
                } else if ($closed['status'] == ChatRoom::STATUS_BLOCKED) {
                    $blocked = TRUE;
                }
            }
        }

        if ($chattype == ChatRoom::TYPE_USER2USER) {
            # If we have created a message, then any outstanding nudge to us has now been dealt with.
            $other = $r->getPrivate('user1') == $userid ? $r->getPrivate('user2') : $r->getPrivate('user1');
            $this->dbhm->background("UPDATE users_nudges SET responded = NOW() WHERE fromuser = $other AND touser = $userid AND responded IS NULL;");
        }

        if (!$spam && !$review && !$blocked) {
            $r->pokeMembers();

            # Notify mods if we have flagged this for review and we've not been asked to suppress it.
            $modstoo = $review && !$suppressmodnotif;
            $r->notifyMembers($userid, $modstoo);
        }

        return TRUE;
    }

    public function create($chatid, $userid, $message, $type = ChatMessage::TYPE_DEFAULT, $refmsgid = NULL, $platform = TRUE, $spamscore = NULL, $reportreason = NULL, $refchatid = NULL, $imageid = NULL, $facebookid = NULL, $forcereview = FALSE, $suppressmodnotif = FALSE, $process = TRUE) {
        // Create the message, requiring processing.
        $rc = $this->dbhm->preExec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, platform, reviewrequired, spamscore, reportreason, refchatid, imageid, facebookid, processingrequired) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1);", [
            $chatid,
            $userid,
            $message,
            $type,
            $refmsgid,
            $platform,
            $forcereview ? 1 : 0,
            $spamscore,
            $reportreason,
            $refchatid,
            $imageid,
            $facebookid
        ]);

        $id = $this->dbhm->lastInsertId();

        if ($id && $imageid) {
            # Update the chat image to link it to this chat message.  This also stops it being purged in
            # purge_chats.
            $this->dbhm->preExec("UPDATE chat_images SET chatmsgid = ? WHERE id = ?;", [
                $id,
                $imageid
            ]);
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'chat_messages', 'chatmessage', $this->publicatts);

            if ($process) {
                // Process this inline for now.  In future we might allow the backgrounding, but that has a risk of
                // bugs and we only really need that perf improvement for the faster Go API.
                $ret = $this->process($forcereview, $suppressmodnotif);
                if (!$ret) {
                    $this->processFailed();
                }
            }

            return([ $id, !$ret ]);
        } else {
            return([ NULL, FALSE ]);
        }
    }

    public function getPublic($refmsgsummary = FALSE, &$userlist = NULL) {
        $ret = $this->getAtts($this->publicatts);

        if (Utils::pres('refmsgid', $ret)) {
            # There is a message (in the sense of an item rather than a chat message) attached to this chat message.
            #
            # Get full message if promised, to pick up promise details.  perf could be improved here.
            $locationlist = [];
            $m = new Message($this->dbhr, $this->dbhm , $ret['refmsgid']);
            $ret['refmsg'] = $m->getPublic(FALSE,
                FALSE,
                FALSE,
                $userlist,
                $locationlist,
                $refmsgsummary);

            if ($refmsgsummary) {
                # Also need the promise info, which isn't in the summary.
                $ret['refmsg']['promisecount'] = $m->promiseCount();
            }

            unset($ret['refmsgid']);
            unset($ret['refmsg']['textbody']);
            unset($ret['refmsg']['htmlbody']);
            unset($ret['refmsg']['message']);
        }

        if (Utils::pres('imageid', $ret)) {
            # There is an image attached
            $a = new Attachment($this->dbhr, $this->dbhm, $ret['imageid'], Attachment::TYPE_CHAT_MESSAGE);
            $ret['image'] = $a->getPublic();
            unset($ret['imageid']);
        }

        if ($ret['type'] == ChatMessage::TYPE_ADDRESS) {
            $id = intval($ret['message']);
            $ret['message'] = NULL;
            $a = new Address($this->dbhr, $this->dbhm, $id);
            $ret['address'] = $a->getPublic();
        }

        # Strip any remaining quoted text in replies.
        $ret['message'] = trim(preg_replace('/\|.*$/m', "", $ret['message']));
        $ret['message'] = trim(preg_replace('/^\>.*$/m', "", $ret['message']));
        $ret['message'] = trim(preg_replace('/\#yiv.*$/m', "", $ret['message']));

        if ($ret['deleted']) {
            $ret['message'] = "(Message deleted)";
        }

        return($ret);
    }

    private function autoApproveAnyModmail($id, $chatid, $myid) {
        # If this was the last message requiring review in the chat, then we can approve any modmails
        # which occur after it.  This makes modmails get sent out in the right order, but autoapproved.
        $sql = "SELECT chat_messages.id FROM chat_messages 
                        WHERE chatid = ? AND
                              chat_messages.id > ?
                          AND chat_messages.reviewrequired = 1 
                          AND chat_messages.type = ?;";
        $msgs = $this->dbhr->preQuery($sql, [ $chatid, $id, ChatMessage::TYPE_MODMAIL ]);

        foreach ($msgs as $msg) {
            $this->dbhm->preExec("UPDATE chat_messages SET reviewrequired = 0, reviewedby = ? WHERE id = ?;", [
                $myid,
                $msg['id']
            ]);
        }
    }

    public function approve($id) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me && $me->isModerator()) {
            $myid = $me->getId();

            $sql = "SELECT DISTINCT chat_messages.*, chat_messages_held.userid AS heldbyuser FROM chat_messages 
    LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id 
    INNER JOIN chat_rooms ON reviewrequired = 1 AND chat_rooms.id = chat_messages.chatid 
    WHERE chat_messages.id = ?;";
            $msgs = $this->dbhr->preQuery($sql, [ $id ]);

            foreach ($msgs as $msg) {
                $heldby = Utils::presdef('heldbyuser', $msg, NULL);

                # Can't act on messages which are held by someone else.
                if (!$heldby || $heldby == $myid) {
                    $this->dbhm->preExec("UPDATE chat_messages SET reviewrequired = 0, reviewedby = ? WHERE id = ?;", [
                        $myid,
                        $id
                    ]);

                    # If this was the last message requiring review in the chat, then we can approve any modmails
                    # which occur after it.  This makes modmails get sent out in the right order, but autoapproved.
                    $this->autoApproveAnyModmail($id, $msg['chatid'], $myid);

                    # Whitelist any URLs - they can't be indicative of spam.
                    $this->whitelistURLs($msg['message']);

                    # This is like a new message now, so alert them.
                    $r = new ChatRoom($this->dbhr, $this->dbhm, $msg['chatid']);
                    $r->updateMessageCounts();
                    $u = User::get($this->dbhr, $this->dbhm, $msg['userid']);
                    $r->pokeMembers();
                    $r->notifyMembers($msg['userid'], TRUE);

                    $this->log->log([
                                        'type' => Log::TYPE_CHAT,
                                        'subtype' => Log::SUBTYPE_APPROVED,
                                        'byuser' => $myid,
                                        'user' => $msg['userid'],
                                    ]);
                }
            }
        }
    }

    public function reject($id) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me && $me->isModerator()) {
            $myid = $me->getId();

            $sql = "SELECT chat_messages.id, chat_messages.chatid, chat_messages.message FROM chat_messages 
        INNER JOIN chat_rooms ON reviewrequired = 1 AND chat_rooms.id = chat_messages.chatid
        WHERE chat_messages.id = ?;";
            $msgs = $this->dbhr->preQuery($sql, [ $id ]);

            foreach ($msgs as $msg)
            {
                $heldby = Utils::presdef('heldbyuser', $msg, null);

                # Can't act on messages which are held by someone else.
                if (!$heldby || $heldby == $myid)
                {
                    $this->dbhm->preExec(
                        "UPDATE chat_messages SET reviewrequired = 0, reviewedby = ?, reviewrejected = 1 WHERE id = ?;",
                        [
                            $myid,
                            $id
                        ]
                    );

                    # If this was the last message requiring review in the chat, then we can approve any modmails
                    # which occur after it.  This makes modmails get sent out in the right order, but autoapproved.
                    $this->autoApproveAnyModmail($id, $msg['chatid'], $myid);

                    $r = new ChatRoom($this->dbhr, $this->dbhm, $msg['chatid']);
                    $r->updateMessageCounts();

                    # Help with flood of spam by marking any identical messages currently awaiting as spam.
                    $start = date("Y-m-d", strtotime("24 hours ago"));
                    $others = $this->dbhr->preQuery(
                        "SELECT id, chatid FROM chat_messages WHERE date >= ? AND reviewrequired = 1 AND message LIKE ?;",
                        [
                            $start,
                            $msg['message']
                        ]
                    );

                    foreach ($others as $other)
                    {
                        $this->dbhm->preExec(
                            "UPDATE chat_messages SET reviewrequired = 0, reviewedby = ?, reviewrejected = 1 WHERE id = ?;",
                            [
                                $myid,
                                $other['id']
                            ]
                        );

                        $r = new ChatRoom($this->dbhr, $this->dbhm, $other['chatid']);
                        $r->updateMessageCounts();
                    }
                }
            }
        }
    }

    public function getReviewCount(User $me, $minage = NULL, $groupid = NULL) {
        # For chats, we should see the messages which require review, and where we are a mod on one of the groups
        # that the recipient of the message (i.e. the chat member who isn't the one who sent it) is on.
        #
        # For some of these groups we may be set not to show messages - so we need to honour that.
        $show = [];
        $dontshow = [];

        $groupids = $groupid ? [ $groupid ] : $me->getModeratorships();

        foreach ($groupids as $groupid) {
            if ($me->activeModForGroup($groupid)) {
                $show[] = $groupid;
            } else {
                $dontshow[] = $groupid;
            }
        }

        $showq = implode(',', $show);
        $dontshowq = implode(',', $dontshow);

        # We want the messages for review for any group where we are a mod and the recipient of the chat message is
        # a member.  Put a backstop time on it to avoid getting too many or
        # an inefficient query.
        $mysqltime = date ("Y-m-d", strtotime("Midnight 31 days ago"));
        $minageq = $minage ? (" AND chat_messages.date <= '" . date ("Y-m-d H:i:s", strtotime("$minage hours ago")) . "' ") : '';
        $showcount = 0;
        $dontshowcount = 0;

        if (count($show) > 0) {
            $sql = "SELECT COUNT(DISTINCT chat_messages.id) AS count FROM chat_messages LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END) AND memberships.groupid IN ($showq) AND chat_messages_held.userid IS NULL INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = 'Freegle'  WHERE chat_messages.date > '$mysqltime' $minageq;";
            #error_log("Show SQL $sql");
            $showcount = $this->dbhr->preQuery($sql)[0]['count'];
        }

        if (count($show) > 0 || count($dontshow) > 0) {
            if (count($show) > 0 && count($dontshow) > 0) {
                $q = "memberships.groupid IN ($dontshowq) OR (memberships.groupid IN ($showq) AND chat_messages_held.userid IS NOT NULL)";
            } else if (count($show) > 0) {
                $q = "memberships.groupid IN ($showq) AND chat_messages_held.userid IS NOT NULL";
            } else {
                $q = "memberships.groupid IN ($dontshowq)";
            }

            $sql = "SELECT COUNT(DISTINCT chat_messages.id) AS count FROM chat_messages 
    LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id 
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid 
    INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END) AND ($q) 
    INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = 'Freegle' WHERE chat_messages.date > '$mysqltime' $minageq;";
            #error_log("No Show SQL $sql");
            $dontshowcount = $this->dbhr->preQuery($sql)[0]['count'];
        }

        return([
            'showgroups' => $showq,
            'dontshowgroups' => $dontshowq,
            'chatreview' => $showcount,
            'chatreviewother' => $dontshowcount,
        ]);
    }

    public function getReviewCountByGroup(?User $me, $other = FALSE) {
        $showcounts = [];

        if ($me) {
            # See getMessagesForReview for logic comments.
            $widerreview = $me->widerReview();

            if ($widerreview) {
                # We want all messages for review on groups which are also enrolled in this scheme
                $wideq = " AND JSON_EXTRACT(groups.settings, '$.widerchatreview') = 1 ";
            }

            $allmods = $me->getModeratorships();
            $groupids = [];

            foreach ($allmods as $mod) {
                if ($me->activeModForGroup($mod)) {
                    $groupids[] = $mod;
                }
            }

            $groupq1 = "AND memberships.groupid IN (" . implode(',', $groupids) . ")";
            $groupq2 = "AND m2.groupid IN (" . implode(',', $groupids) . ") ";

            $holdq = $other ? "AND chat_messages_held.userid IS NOT NULL" : "AND chat_messages_held.userid IS NULL";

            if ($widerreview || count($groupids)) {
                # We want the messages for review for any group where we are a mod and the recipient of the chat message is
                # a member.  Put a backstop time on it to avoid getting too many or an inefficient query.
                $mysqltime = date ("Y-m-d", strtotime("Midnight 31 days ago"));

                $sql = "SELECT chat_messages.id, memberships.groupid FROM chat_messages 
    LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id 
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid 
    INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END) $groupq1 
    INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = ? WHERE chat_messages.date > '$mysqltime' $holdq
    UNION
    SELECT chat_messages.id, m2.groupid FROM chat_messages 
    LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id 
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid 
    LEFT JOIN memberships m1 ON m1.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END)                                      
    LEFT JOIN `groups` ON m1.groupid = groups.id AND groups.type = ?
    INNER JOIN memberships m2 ON m2.userid = chat_messages.userid $groupq2
    WHERE chat_messages.date > '$mysqltime' AND m1.id IS NULL $holdq";
                $params = [
                    Group::GROUP_FREEGLE,
                    Group::GROUP_FREEGLE
                ];

                if ($wideq && $other) {
                    $sql .= " UNION
                    SELECT chat_messages.id, memberships.groupid FROM chat_messages 
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid 
    LEFT JOIN chat_messages_held ON chat_messages.id = chat_messages_held.msgid
    INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END)  
    INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = ? WHERE chat_messages.date > '$mysqltime' $wideq AND chat_messages_held.id IS NULL 
    AND chat_messages.reportreason NOT IN (?)";
                    $params[] = Group::GROUP_FREEGLE;
                    $params[] = ChatMessage::REVIEW_USER;
                }

                $sql .= "    ORDER BY groupid;";

                $counts = $this->dbhr->preQuery($sql, $params);

                # The same message might appear in the query results multiple times if the recipient is on multiple
                # groups that we mod.  We only want to count it once.  The order here matches that in
                # ChatRoom::getMessagesForReview.
                $showcounts = [];
                $usedmsgs = [];
                $groupids = [];

                foreach ($counts as $count) {
                    $usedmsgs[$count['id']] = $count['groupid'];
                    $groupids[$count['groupid']] = $count['groupid'];
                }

                foreach ($groupids as $groupid) {
                    $count = 0;

                    foreach ($usedmsgs as $usedmsg => $msggrp) {
                        if ($msggrp == $groupid) {
                            $count++;
                        }
                    }

                    $showcounts[] = [
                        'groupid' => $groupid,
                        'count' => $count
                    ];
                }
            }
        }

        return($showcounts);
    }

    public function hold($id) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me && $me->isModerator()) {
            $myid = $me->getId();

            $this->dbhm->preExec("REPLACE INTO chat_messages_held (msgid, userid) VALUES (?, ?);", [
                $id,
                $myid
            ]);
        }
    }

    public function release($id) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me && $me->isModerator()) {
            $myid = $me->getId();

            $sql = "SELECT chat_messages.*, chat_messages_held.userid AS heldbyuser FROM chat_messages 
        INNER JOIN chat_rooms ON reviewrequired = 1 AND chat_rooms.id = chat_messages.chatid 
        LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages_held.msgid
        WHERE chat_messages.id = ?;";
            $msgs = $this->dbhr->preQuery($sql, [$id]);

            foreach ($msgs as $msg) {
                $heldby = Utils::presdef('heldbyuser', $msg, null);

                # Can't act on messages which are held by someone else.
                if (!$heldby || $heldby == $myid) {
                    $this->dbhm->preExec("DELETE FROM chat_messages_held WHERE msgid = ?;", [
                        $id
                    ]);
                }
            }
        }
    }

    public function redact($id) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($me && $me->isModerator()) {
            $myid = $me->getId();

            $sql = "SELECT chat_messages.*, chat_messages_held.userid AS heldbyuser FROM chat_messages 
        INNER JOIN chat_rooms ON reviewrequired = 1 AND chat_rooms.id = chat_messages.chatid
        LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages_held.msgid
        WHERE chat_messages.id = ?;";
            $msgs = $this->dbhr->preQuery($sql, [$id]);

            foreach ($msgs as $msg)
            {
                $heldby = Utils::presdef('heldbyuser', $msg, null);

                # Can't act on messages which are held by someone else.
                # We can act on messages we held, but not other people's.
                if (!$heldby || $heldby == $myid)
                {
                    # Remove any emails
                    $this->dbhm->preExec("UPDATE chat_messages SET message = ? WHERE id = ?;", [
                        preg_replace(Message::EMAIL_REGEXP, '(email removed)', $msg['message']),
                        $msg['id']
                    ]);
                }
            }
        }
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM chat_messages WHERE id = ?;", [$this->id]);
        return ($rc);
    }

    # Look for a postcode in a string, and check that it is close to the user.
    public function extractAddress($msg, $userid)  {
        $ret = null;

        if (preg_match(Utils::POSTCODE_PATTERN, $msg, $matches)) {
            # We have a possible postcode.
            $pc = strtoupper($matches[0]);
            $l = new Location($this->dbhr, $this->dbhm);

            $locs = $this->dbhr->preQuery("SELECT * FROM locations WHERE canon = ?", [
                $l->canon($pc)
            ]);

            if (count($locs)) {
                $loc = $locs[0];

                # Check it's not too far away.
                $u = User::get($this->dbhr, $this->dbhm, $userid);
                list ($lat, $lng, $loc2) = $u->getLatLng();

                $dist = \GreatCircle::getDistance($lat, $lng, $loc['lat'], $loc['lng']);

                if ($dist <= 20000) {
                    # Found it.  Check that we have the street name in there too to avoid the possibility of us
                    # just sending the postcode.
                    $streets = $this->dbhr->preQuery(
                        "SELECT DISTINCT thoroughfaredescriptor FROM paf_thoroughfaredescriptor INNER JOIN paf_addresses ON paf_addresses.thoroughfaredescriptorid = paf_thoroughfaredescriptor.id WHERE paf_addresses.postcodeid = ?",  [
                            $loc['id']
                        ]
                    );

                    $foundIt = false;

                    foreach ($streets as $street) {
                        if (Utils::levensteinSubstringContains($street['thoroughfaredescriptor'], $msg, 3)) {
                            $foundIt = true;
                            break;
                        }
                    }

                    if ($foundIt) {
                        $ret = $loc;
                    }
                }
            }
        }

        return $ret;
    }
}
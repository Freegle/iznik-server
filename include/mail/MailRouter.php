<?php
namespace Freegle\Iznik;

use spamc;

if (!class_exists('spamc')) {
    require_once(IZNIK_BASE . '/lib/spamc.php');
}

# This class routes an incoming message
class MailRouter
{
    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;
    /** @var Message */
    private $msg;
    private $spamc;

    CONST ASSASSIN_THRESHOLD = 8;

    /**
     * @param LoggedPDO $dbhn
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    private $spam;

    /**
     * @param mixed $spamc
     */
    public function setSpamc($spamc)
    {
        $this->spamc = $spamc;
    }

    const FAILURE = "Failure";
    const INCOMING_SPAM = "IncomingSpam";
    const APPROVED = "Approved";
    const PENDING = 'Pending';
    const TO_USER = "ToUser";
    const TO_SYSTEM ='ToSystem';
    const RECEIPT = 'ReadReceipt';
    const TRYST = 'Tryst';
    const DROPPED ='Dropped';
    const TO_VOLUNTEERS = "ToVolunteers";

    function __construct($dbhr, $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($this->dbhr, $this->dbhm);
        $this->spamc = new spamc;
        $this->spam = new Spam($this->dbhr, $this->dbhm);

        if ($id) {
            $this->msg = new Message($this->dbhr, $this->dbhm, $id);
        } else {
            $this->msg = new Message($this->dbhr, $this->dbhm);
        }
    }

    public function received($source, $from, $to, $msg, $groupid = NULL, $log = TRUE) {
        # We parse it and save it to the DB.  Then it will get picked up by background
        # processing.
        #
        # We have a groupid override because it's possible that we are syncing a message
        # from a group which has changed name and the To field might therefore not match
        # a current group name.
        $ret = NULL;
        $rc = $this->msg->parse($source, $from, $to, $msg, $groupid);

        if ($rc) {
            $ret = $this->msg->save($log);
        }
        
        return($ret);
    }

    # Public for UT
    public function markAsSpam($type, $reason) {
        return(
            $this->dbhm->preExec("UPDATE messages SET spamtype = ?, spamreason = ? WHERE id = ?;", [
                $type,
                $reason,
                $this->msg->getID()
            ]) &&
            $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ?;", [
                MessageCollection::PENDING,
                $this->msg->getID()
            ]));
    }

    # Public for UT
    public function markApproved() {
        # Set this message to be in the Approved collection.
        # TODO Handle message on multiple groups
        $rc = $this->dbhm->preExec("UPDATE messages_groups SET collection = 'Approved', approvedat = NOW() WHERE msgid = ?;", [
            $this->msg->getID()
        ]);

        # Now visible in search
        $this->msg->index();

        return($rc);
    }

    # Public for UT
    public function markPending($force) {
        # Set the message as pending.
        #
        # If we're forced we just do it.  The force is to allow us to move from Spam to Pending.
        #
        # If we're not forced, then the mainline case is that this is an incoming message.  We might get a
        # pending notification after approving it, and in that case we don't generally want to move it back to
        # pending.  However if we approved/rejected it a while ago, then it's likely that the action didn't stick (for
        # example if we approved by email to Yahoo and Yahoo ignored it).  In that case we should move it
        # back to Pending, otherwise it will stay stuck on Yahoo.
        $overq = '';

        if (!$force) {
            $groups = $this->dbhr->preQuery("SELECT collection, approvedat, rejectedat FROM messages_groups WHERE msgid = ? AND ((collection = 'Approved' AND (approvedat IS NULL OR approvedat < DATE_SUB(NOW(), INTERVAL 2 HOUR))) OR (collection = 'Rejected' AND (rejectedat IS NULL OR rejectedat < DATE_SUB(NOW(), INTERVAL 2 HOUR))));",  [ $this->msg->getID() ]);
            $overq = count($groups) == 0 ? " AND collection = 'Incoming' " : '';
            #error_log("MarkPending " . $this->msg->getID() . " from collection $overq");
        }

        $rc = $this->dbhm->preExec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? $overq;", [
            $this->msg->getID()
        ]);

        # Notify mods of new work
        $groups = $this->msg->getGroups();
        $n = new PushNotifications($this->dbhr, $this->dbhm);

        foreach ($groups as $groupid) {
            $n->notifyGroupMods($groupid);
            error_log("Pending notify $groupid");
        }

        return($rc);
    }

    public function route($msg = NULL, $notspam = FALSE) {
        $ret = NULL;
        $log = TRUE;
        $keepgroups = FALSE;

        # We route messages to one of the following destinations:
        # - to a handler for system messages
        #   - confirmation of Yahoo mod status
        #   - confirmation of Yahoo subscription requests
        # - to a group, either pending or approved
        # - to group moderators
        # - to a user
        # - to a spam queue
        if ($msg) {
            $this->msg = $msg;
        }

        if ($notspam) {
            # Record that this message has been flagged as not spam.
            if ($this->log) { error_log("Record message as not spam"); }
            $this->msg->setPrivate('spamtype', Spam::REASON_NOT_SPAM, TRUE);
        }

        # Check if we know that this is not spam.  This means if we receive a later copy of it,
        # then we will know that we don't need to spam check it, otherwise we might move it back into spam
        # to the annoyance of the moderators.
        $notspam = $this->msg->getPrivate('spamtype') ==  Spam::REASON_NOT_SPAM;
        if ($this->log) { error_log("Consider not spam $notspam from " . $this->msg->getPrivate('spamtype')); }

        $to = $this->msg->getEnvelopeto();
        $from = $this->msg->getEnvelopefrom();
        $fromheader = $this->msg->getHeader('from');

        # TN authenticates mails with a secret header which we can use to skip spam checks.
        $tnsecret = $this->msg->getHeader('x-trash-nothing-secret');
        $notspam = $tnsecret == TNSECRET ? TRUE : $notspam;

        if ($fromheader) {
            $fromheader = mailparse_rfc822_parse_addresses($fromheader);
        }

        if ($this->spam->isSpammer($from)) {
            # Mail from spammer. Drop it.
            if ($log) { error_log("Spammer, drop"); }
            $ret = MailRouter::DROPPED;
        } else if (preg_match('/digestoff-(.*)-(.*)@/', $to, $matches) == 1) {
            $ret = $this->turnDigestOff($matches, $ret);
        } else if (preg_match('/readreceipt-(.*)-(.*)-(.*)@/', $to, $matches) == 1) {
            $ret = $this->readReceipt($matches);
        } else if (preg_match('/handover-(.*)-(.*)@/', $to, $matches) == 1) {
            $ret = $this->trystResponse($matches);
        } else if (preg_match('/eventsoff-(.*)-(.*)@/', $to, $matches) == 1) {
            $ret = $this->turnEventsOff($matches, $ret);
        } else if (preg_match('/newslettersoff-(.*)@/', $to, $matches) == 1) {
            $ret = $this->turnNewslettersOff($matches[1], $ret);
        } else if (preg_match('/relevantoff-(.*)@/', $to, $matches) == 1) {
            $ret = $this->turnRelevantOff($matches[1], $ret);
        } else if (preg_match('/volunteeringoff-(.*)-(.*)@/', $to, $matches) == 1) {
            $ret = $this->turnVolunteeringOff($matches, $ret);
        } else if (preg_match('/notificationmailsoff-(.*)@/', $to, $matches) == 1) {
            $ret = $this->turnNotificationsOff($matches[1], $ret);
        } else if (strcmp($this->msg->getFromaddr(), 'support@twitter.com') === 0 &&
            preg_match('/(.*)-volunteers@' . GROUP_DOMAIN . '/', $to, $matches) &&
            strpos($this->msg->getMessage(), 'We received your appeal regarding your account.') !== FALSE
        ) {
            $ret = $this->twitterAppeal($log, $fromheader[0]['address'], $to);
        } else if (preg_match('/(.*)-volunteers@' . GROUP_DOMAIN . '/', $to, $matches) ||
            preg_match('/(.*)-auto@' . GROUP_DOMAIN . '/', $to, $matches)) {
            $ret = $this->toVolunteers($to, $matches[1], $notspam);
        } else if (preg_match('/(.*)-subscribe@' . GROUP_DOMAIN . '/', $to, $matches)) {
            $ret = $this->subscribe($matches[1]);
        } else if (preg_match('/(.*)-unsubscribe@' . GROUP_DOMAIN . '/', $to, $matches)) {
            $ret = $this->unsubscribe($matches[1]);
        } else {
            list($spamscore, $spamfound, $groups, $notspam, $ret) = $this->checkSpam(
                $log,
                $notspam,
                $ret
            );

            if ($spamfound && strpos($to, '@' . USER_DOMAIN) !== FALSE) {
                # Horrible spaghetti logic.  We found spam in a message which will end up going to chat, if it
                # has the right kind of address.  We don't want to junk it - we want to send it to review.  We will
                # check the spamfound flag below when creating the chat message.
                error_log("Spam, but destined for chat, continue");
                $ret = NULL;
            }

            if (!$ret) {
                # Not obviously spam.
                if ($log) { error_log("Not obviously spam, groups " . var_export($groups, TRUE)); }

                if (count($groups) > 0) {
                    $ret = $this->toGroup($log, $notspam, $groups, $fromheader[0]['address'], $to);
                } else {
                    # It's not to one of our groups - but it could be a reply to one of our users, in several ways:
                    # - to the reply address we put in our What's New mails
                    # - directly to their USER_DOMAIN address, which happens after their message has been posted
                    #   on a Yahoo group and we get a reply through that route
                    # - in response to an email chat notification, which happens as a result of subsequent
                    #   communications after the previous two
                    $u = User::get($this->dbhr, $this->dbhm);
                    $to = $this->msg->getEnvelopeto();
                    $to = $to ? $to : $this->msg->getHeader('to');
                    if ($log) { error_log("Look for reply to $to from " . $this->msg->getEnvelopeFrom()); }

                    if (strlen($this->msg->getEnvelopeto()) && $this->msg->getEnvelopeto() == $this->msg->getEnvelopefrom()) {
                        # Sending to yourself isn't a valid path, and is used by spammers.
                        if ($log) { error_log("Sending to self " . $this->msg->getEnvelopeto() . " vs " . $this->msg->getEnvelopefrom() . " - dropped "); }
                        $ret = MailRouter::DROPPED;
                    } else if (preg_match('/replyto-(.*)-(.*)' . USER_DOMAIN . '/', $to, $matches)) {
                        $ret = $this->replyToSingleMessage($matches, $log, $ret, $spamfound);
                    } else if (preg_match('/notify-(.*)-(.*)@/', $to, $matches)) {
                        $ret = $this->replyToChatNotification($matches, $log, $ret, $spamfound);
                    } else if (!$this->msg->isAutoreply()) {
                        $ret = $this->directMailToUser($u, $to, $log, $spamscore, $spamfound);
                    } else {
                        if ($log) { error_log("Auto-reply - drop"); }
                        $ret = MailRouter::DROPPED;
                    }
                }
            }
        }

        if ($ret != MailRouter::FAILURE && !$keepgroups) {
            # Ensure no message is stuck in incoming.
            $this->dbhm->preExec("DELETE FROM messages_groups WHERE msgid = ? AND collection = ?;", [
                $this->msg->getID(),
                MessageCollection::INCOMING
            ]);
        }

        $this->dbhm->preExec("UPDATE messages SET lastroute = ? WHERE id = ?;", [
            $ret,
            $this->msg->getID()
        ]);

        # Dropped messages will get tidied up by cron; we leave them around in case we need to
        # look at them for PD.
        error_log("Routed #" . $this->msg->getID(). " " . $this->msg->getMessageID() . " " . $this->msg->getEnvelopefrom() . " -> " . $this->msg->getEnvelopeto() . " " . $this->msg->getSubject() . " " . $ret);

        return($ret);
    }

    private function addPhotosToChat($rid) {
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $count = 0;

        $atts = $this->msg->getAttachments();
        foreach ($atts as $att) {
            list ($aid, $banned) = $m->create($rid, $this->msg->getFromuser(), NULL, ChatMessage::TYPE_IMAGE, NULL, FALSE);

            if ($aid) {
                $data = $att->getData();
                $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_CHAT_MESSAGE);
                try {
                    $aid2 = $a->create($aid, $data);

                    $hash = $a->getHash();

                    if ($hash == '61e4d4a2e4bb8a5d' || $hash == '61e4d4a2e4bb8a59') {
                        # Images to suppress, e.g. our logo.
                        $a->delete();
                    } else {
                        $m->setPrivate('imageid', $aid2);

                        # Check whether this hash has recently been used for lots of messages.  If so then flag
                        # the message for review.  We currently only do this for email (which comes through here)
                        # as spam is largely an email problem.
                        $used = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_images 
                         INNER JOIN chat_messages ON chat_images.id = chat_messages.imageid 
                         WHERE hash = ? AND TIMESTAMPDIFF(HOUR, chat_messages.date, NOW()) <= ?;", [
                            $hash,
                            Spam::IMAGE_THRESHOLD_TIME
                        ]);

                        if ($used[0]['count'] > Spam::IMAGE_THRESHOLD) {
                            $m->setPrivate('reviewrequired', 1);
                            $m->setPrivate('reportreason', Spam::REASON_IMAGE_SENT_MANY_TIMES);
                        }

                        $count++;
                    }
                } catch (\Exception $e) { error_log("Create failed " . $e->getMessage()); }
            }
        }

        return($count);
    }

    public function routeAll() {
        $msgs = $this->dbhr->preQuery("SELECT msgid FROM messages_groups WHERE collection = 'Incoming' AND deleted = 0;");
        foreach ($msgs as $m) {
            try {
                // @codeCoverageIgnoreStart This seems to be needed due to a presumed bug in phpUnit.  This line
                // doesn't show as covered even though the next one does, which is clearly not possible.
                $msg = new Message($this->dbhr, $this->dbhm, $m['msgid']);
                // @codeCoverageIgnoreEnd

                if (!$msg->getDeleted()) {
                    $this->route($msg);
                }
            } catch (\Exception $e) {
                # Ignore this and continue routing the rest.
                error_log("Route #" . $this->msg->getID() . " failed " . $e->getMessage() . " stack " . $e->getTraceAsString());
                if ($this->dbhm->inTransaction()) {
                    $this->dbhm->rollBack();
                }
            }
        }
    }

    public function mail($to, $from, $subject, $body) {
        # None of these mails need tracking, so we don't call AddHeaders.
        list ($transport, $mailer) = Mail::getMailer();

        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom($from)
            ->setTo($to)
            ->setBody($body);
        $mailer->send($message);
    }

    private function turnDigestOff($matches, $ret)
    {
        # Request to turn email off.
        $uid = intval($matches[1]);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
        $groupid = intval($matches[2]);

        if ($uid && $groupid)
        {
            $d = new Digest($this->dbhr, $this->dbhm);
            $d->off($uid, $groupid);

            $ret = MailRouter::TO_SYSTEM;
        }
        return $ret;
    }

    private function readReceipt($matches)
    {
        # Read receipt
        $chatid = intval($matches[1]);
        $userid = intval($matches[2]);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $userid;");
        $msgid = intval($matches[3]);

        # The receipt has seen this message, and the message has been seen by all people in the chat (because
        # we only generate these for user 2 user.
        $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
        if ($r->canSee($userid, false))
        {
            $r->updateRoster($userid, $msgid);
            $r->seenByAll($msgid);
        }

        $ret = MailRouter::RECEIPT;
        return $ret;
    }

    private function trystResponse($matches)
    {
        # Calendar response
        $trystid = intval($matches[1]);
        $userid = intval($matches[2]);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $userid;");

        # Scan for a VCALENDAR attachment.
        $t = new Tryst($this->dbhr, $this->dbhm, $trystid);
        $rsp = Tryst::OTHER;

        foreach ($this->msg->getParsedAttachments() as $att)
        {
            $ct = $att->getContentType();

            if (strcmp('text/calendar', strtolower($ct)) === 0)
            {
                # We don't do a proper parse
                $vcal = strtolower($att->getContent());
                if (strpos($vcal, 'status:confirmed') !== false || strpos($vcal, 'status:tentative') !== false)
                {
                    $rsp = Tryst::ACCEPTED;
                } else
                {
                    if (strpos($vcal, 'status:cancelled') !== false)
                    {
                        $rsp = Tryst::DECLINED;
                    }
                }
            }
        }

        if ($rsp == Tryst::OTHER)
        {
            # Maybe they didn't put the VCALENDAR in.
            if (stripos($this->msg->getSubject(), 'accepted') !== false)
            {
                $rsp = Tryst::ACCEPTED;
            } else
            {
                if (stripos($this->msg->getSubject(), 'declined') !== false)
                {
                    $rsp = Tryst::DECLINED;
                }
            }
        }

        $t->response($userid, $rsp);

        $ret = MailRouter::TRYST;
        return $ret;
    }

    private function turnEventsOff($matches, $ret)
    {
        # Request to turn events email off.
        $uid = intval($matches[1]);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
        $groupid = intval($matches[2]);

        if ($uid && $groupid)
        {
            $d = new EventDigest($this->dbhr, $this->dbhm);
            $d->off($uid, $groupid);

            $ret = MailRouter::TO_SYSTEM;
        }
        return $ret;
    }

    private function turnNewslettersOff($matches, $ret)
    {
        # Request to turn newsletters off.
        $uid = intval($matches);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

        if ($uid)
        {
            $d = new Newsletter($this->dbhr, $this->dbhm);
            $d->off($uid);

            $ret = MailRouter::TO_SYSTEM;
        }
        return $ret;
    }

    private function turnRelevantOff($matches, $ret)
    {
        # Request to turn "interested in" off.
        $uid = intval($matches);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

        if ($uid)
        {
            $d = new Relevant($this->dbhr, $this->dbhm);
            $d->off($uid);

            $ret = MailRouter::TO_SYSTEM;
        }
        return $ret;
    }

    private function turnVolunteeringOff($matches, $ret)
    {
        # Request to turn volunteering email off.
        $uid = intval($matches[1]);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
        $groupid = intval($matches[2]);

        if ($uid && $groupid)
        {
            $d = new VolunteeringDigest($this->dbhr, $this->dbhm);
            $d->off($uid, $groupid);

            $ret = MailRouter::TO_SYSTEM;
        }
        return $ret;
    }

    private function turnNotificationsOff($matches, $ret)
    {
        # Request to turn notification email off.
        $uid = intval($matches);
        $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

        if ($uid)
        {
            $d = new Notifications($this->dbhr, $this->dbhm);
            $d->off($uid);

            $ret = MailRouter::TO_SYSTEM;
        }
        return $ret;
    }

    private function twitterAppeal(bool $log, $address, $to)
    {
        # We need to confirm this to allow appeals of blocked accounts.
        if ($log)
        {
            error_log("Confirm twitter appeal");
        }
        $this->mail($address, $to, "Re: " . $this->msg->getSubject(), "I confirm this");
        $ret = MailRouter::TO_SYSTEM;
        return $ret;
    }

    private function toVolunteers($to, $matches, $notspam)
    {
        # Mail to our owner address.  First check if it's spam according to SpamAssassin.
        if ($this->log) {
            error_log("To volunteers");
        }

        $this->spamc->command = 'CHECK';

        $ret = MailRouter::INCOMING_SPAM;

        if ($notspam || $this->spamc->filter($this->msg->getMessage()))
        {
            $spamscore = $this->spamc->result['SCORE'];

            if ($notspam || $spamscore < MailRouter::ASSASSIN_THRESHOLD)
            {
                # Now do our own checks.
                if ($this->log)
                {
                    error_log("Passed SpamAssassin $spamscore");
                }
                list ($rc, $reason) = $notspam ? [ FALSE, NULL] : $this->spam->checkMessage($this->msg);

                if (!$rc)
                {
                    # Don't pass on automated mails from ADMINs - there might be loads.
                    if ($notspam ||
                        (preg_match('/(.*)-volunteers@' . GROUP_DOMAIN . '/', $to) || !$this->msg->isBounce() && !$this->msg->isAutoreply()))
                    {
                        $ret = MailRouter::FAILURE;

                        # It's not.  Find the group
                        $g = new Group($this->dbhr, $this->dbhm);
                        $sn = $matches;

                        $gid = $g->findByShortName($sn);
                        if ($this->log)
                        {
                            error_log("Found $gid from $sn");
                        }

                        if ($gid)
                        {
                            # It's one of our groups.  Find the user this is from.
                            $envfrom = $this->msg->getFromaddr();
                            $u = new User($this->dbhr, $this->dbhm);
                            $uid = $u->findByEmail($envfrom);
                            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

                            if ($this->log)
                            {
                                error_log("Found $uid from $envfrom");
                            }

                            # We should always find them as Message::parse should create them
                            if ($uid)
                            {
                                if ($this->log)
                                {
                                    error_log("From user $uid to group $gid");
                                }
                                $s = new Spam($this->dbhr, $this->dbhm);

                                # Filter out mail to volunteers from known spammers.
                                $ret = MailRouter::INCOMING_SPAM;
                                $spammers = $s->getSpammerByUserid($uid);

                                if (!$spammers)
                                {
                                    $ret = MailRouter::DROPPED;

                                    # Don't want to pass on OOF etc.
                                    if ($notspam || !$this->msg->isAutoreply())
                                    {
                                        # Create/get a chat between the sender and the group mods.
                                        $r = new ChatRoom($this->dbhr, $this->dbhm);
                                        $chatid = $r->createUser2Mod($uid, $gid);
                                        if ($this->log)
                                        {
                                            error_log("Chatid is $chatid");
                                        }

                                        # Now add this message into the chat.  Don't strip quoted as it might be useful -
                                        # one example is twitter email confirmations, where the URL is quoted (weirdly).
                                        $textbody = $this->msg->getTextBody();

                                        # ...but we don't want the whole digest, if they sent that.
                                        if (preg_match('/(.*)^\s*On.*?-auto@' . GROUP_DOMAIN . '> wrote\:(\s*)/ms', $textbody, $matches)) {
                                            $textbody = $matches[1];
                                            $textbody .= "\r\n\r\n(Replied to digest)";
                                        }

                                        if (preg_match('/(.*)^\s*-----Original Message-----(\s*)/ms', $textbody, $matches)) {
                                            $textbody = $matches[1];
                                            $textbody .= "\r\n\r\n(Replied to digest)";
                                        }

                                        if (strlen($textbody)) {
                                            $m = new ChatMessage($this->dbhr, $this->dbhm);

                                            // Force to review so that we don't mail it before we've recorded that the
                                            // sender has seen it.
                                            list ($mid, $banned) = $m->create(
                                                $chatid,
                                                $uid,
                                                $textbody,
                                                ChatMessage::TYPE_DEFAULT,
                                                null,
                                                false,
                                                null,
                                                null,
                                                null,
                                                null,
                                                null,
                                                true
                                            );

                                            $r->updateRoster($uid, $mid);

                                            // Allow mailing to happen.
                                            $m->setPrivate('reviewrequired', 0);

                                            if ($this->log)
                                            {
                                                error_log("Created message $mid");
                                            }

                                            $m->chatByEmail($mid, $this->msg->getID());
                                        }

                                        # Add any photos.
                                        $this->addPhotosToChat($chatid);

                                        $ret = MailRouter::TO_VOLUNTEERS;
                                    }
                                }
                            }
                        }
                    } else {
                        if ($this->log) {
                            error_log("Automated reply from ADMIN - drop");
                        }
                        $ret = MailRouter::DROPPED;
                    }
                } else {
                    if ($this->log) {
                        error_log("Spam: " . var_export($reason, TRUE));
                    }
                }
            }
        }
        return $ret;
    }

    private function subscribe($name)
    {
        $ret = MailRouter::FAILURE;

        # Find the group
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName($name);
        $g = new Group($this->dbhr, $this->dbhm, $gid);

        if ($gid)
        {
            # It's one of our groups.  Find the user this is from.
            $envfrom = $this->msg->getEnvelopeFrom();
            $u = new User($this->dbhr, $this->dbhm);
            $uid = $u->findByEmail($envfrom);

            if (!$uid)
            {
                # We don't know them yet.
                $uid = $u->create(
                    null,
                    null,
                    $this->msg->getFromname(),
                    "Email subscription from $envfrom to " . $g->getPrivate('nameshort')
                );
                $u->addEmail($envfrom, 0);
                $pw = $u->inventPassword();
                $u->addLogin(User::LOGIN_NATIVE, $uid, $pw);
                $u->welcome($envfrom, $pw);
            }

            $u = new User($this->dbhr, $this->dbhm, $uid);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

            # We should always find them as Message::parse should create them
            if ($u->getId())
            {
                $u->addMembership($gid, User::ROLE_MEMBER, null, MembershipCollection::APPROVED, null, $envfrom);

                # Remove any email logs for this message - no point wasting space on keeping those.
                $this->log->deleteLogsForMessage($this->msg->getID());
                $ret = MailRouter::TO_SYSTEM;
            }
        }
        return $ret;
    }

    private function unsubscribe($name)
    {
        $ret = MailRouter::FAILURE;

        # Find the group
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName($name);

        if ($gid)
        {
            # It's one of our groups.  Find the user this is from.
            $envfrom = $this->msg->getEnvelopeFrom();
            $u = new User($this->dbhr, $this->dbhm);
            $uid = $u->findByEmail($envfrom);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

            if ($uid)
            {
                $u = new User($this->dbhr, $this->dbhm, $uid);
                $ret = MailRouter::DROPPED;

                if (!$u->isModOrOwner($gid))
                {
                    $u->removeMembership($gid, false, false, $envfrom);

                    # Remove any email logs for this message - no point wasting space on keeping those.
                    $this->log->deleteLogsForMessage($this->msg->getID());
                    $ret = MailRouter::TO_SYSTEM;
                }
            }
        }
        return $ret;
    }

    private function checkSpam($log, bool $notspam, $ret): array
    {
        # We use SpamAssassin to weed out obvious spam.  We only do a content check if the message subject line is
        # not in the standard format.  Most generic spam isn't in that format, and some of our messages
        # would otherwise get flagged - so this improves overall reliability.
        $contentcheck = !$notspam && !preg_match('/.*?\:(.*)\(.*\)/', $this->msg->getSubject());
        $spamscore = null;
        $spamfound = false;

        $groups = $this->msg->getGroups(false, false);
        #error_log("Got groups " . var_export($groups, TRUE));

        # Check if the group wants us to check for spam.
        foreach ($groups as $group)
        {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $defs = $g->getDefaults();
            $spammers = $g->getSetting('spammers', $defs['spammers']);
            $check = array_key_exists(
                'messagereview',
                $spammers
            ) ? $spammers['messagereview'] : $defs['spammers']['messagereview'];
            $notspam = $check ? $notspam : true;
            #error_log("Consider spam review $notspam from $check, " . var_export($spammers, TRUE));
        }

        if (!$notspam)
        {
            # First check if this message is spam based on our own checks.
            $rc = $this->spam->checkMessage($this->msg);
            if ($rc)
            {
                if (count($groups) > 0)
                {
                    foreach ($groups as $group)
                    {
                        $this->log->log([
                                            'type' => Log::TYPE_MESSAGE,
                                            'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                            'msgid' => $this->msg->getID(),
                                            'text' => "{$rc[2]}",
                                            'groupid' => $group['groupid']
                                        ]);
                    }
                } else
                {
                    $this->log->log([
                                        'type' => Log::TYPE_MESSAGE,
                                        'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                        'msgid' => $this->msg->getID(),
                                        'text' => "{$rc[2]}"
                                    ]);
                }

                if ($log) { error_log("Classified as spam {$rc[2]}"); }
                $ret = MailRouter::FAILURE;

                if ($this->markAsSpam($rc[1], $rc[2]))
                {
                    $groups = $this->msg->getGroups(false, false);

                    if (count($groups) > 0)
                    {
                        foreach ($groups as $group)
                        {
                            $uid = $this->msg->getFromuser();
                            $u = User::get($this->dbhr, $this->dbhm, $uid);

                            if ($u->isBanned($group['groupid']))
                            {
                                // If they are banned we just want to drop it.
                                if ($log) { error_log("Banned - drop"); }
                                $ret = MailRouter::DROPPED;
                            }
                        }
                    }

                    if ($ret != MailRouter::DROPPED)
                    {
                        $ret = MailRouter::INCOMING_SPAM;
                        $spamfound = true;
                    }
                }
            } else {
                if ($contentcheck)
                {
                    # Now check if we think this is spam according to SpamAssassin.
                    #
                    # Need to cope with SpamAssassin being unavailable.
                    $this->spamc->command = 'CHECK';
                    $spamret = true;
                    $spamscore = 0;

                    try
                    {
                        $spamret = $this->spamc->filter($this->msg->getMessage());
                        $spamscore = $this->spamc->result['SCORE'];
                    } catch (\Exception $e) {}

                    if ($spamret)
                    {
                        if ($spamscore >= MailRouter::ASSASSIN_THRESHOLD && ($this->msg->getEnvelopefrom(
                                ) != 'from@test.com'))
                        {
                            # This might be spam.  We'll mark it as such, then it will get reviewed.
                            #
                            # Hacky if test to stop our UT messages getting flagged as spam unless we want them to be.
                            $groups = $this->msg->getGroups(false, false);

                            if (count($groups) > 0)
                            {
                                foreach ($groups as $group)
                                {
                                    $this->log->log([
                                                        'type' => Log::TYPE_MESSAGE,
                                                        'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                                        'msgid' => $this->msg->getID(),
                                                        'text' => "SpamAssassin score $spamscore",
                                                        'groupid' => $group['groupid']
                                                    ]);
                                }
                            } else
                            {
                                $this->log->log([
                                                    'type' => Log::TYPE_MESSAGE,
                                                    'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                                    'msgid' => $this->msg->getID(),
                                                    'text' => "SpamAssassin score $spamscore"
                                                ]);
                            }

                            if ($this->markAsSpam(
                                Spam::REASON_SPAMASSASSIN,
                                "SpamAssassin flagged this as possible spam; score $spamscore (high is bad)"
                            ))
                            {
                                $ret = MailRouter::INCOMING_SPAM;
                                $spamfound = true;
                            } else
                            {
                                error_log("Failed to mark as spam");
                                $this->msg->recordFailure('Failed to mark spam');
                                $ret = MailRouter::FAILURE;
                            }
                        }
                    } else
                    {
                        # We have failed to check that this is spam.  Record the failure but carry on.
                        error_log("Failed to check spam " . $this->spamc->err);
                        $this->msg->recordFailure('Spam Assassin check failed ' . $this->spamc->err);
                    }
                }
            }
        }

        return [ $spamscore, $spamfound, $groups, $notspam, $ret ];
    }

    private function toGroup(bool $log, $notspam, $groups, $address, $to)
    {
        # We're expecting to do something with this.
        $envto = $this->msg->getEnvelopeto();
        if ($log) { error_log("To a group; to user $envto source " . $this->msg->getSource()); }
        $ret = MailRouter::FAILURE;
        $source = $this->msg->getSource();

        if ($notspam && $source == Message::PLATFORM)
        {
            # It should go into pending on here.
            if ($log) { error_log("Mark as pending"); }

            if ($this->markPending($notspam))
            {
                $ret = MailRouter::PENDING;
            }
        } else
        {
            if ($this->msg->getSource() == Message::EMAIL)
            {
                $uid = $this->msg->getFromuser();
                if ($log)
                {
                    error_log("Email source, user $uid");
                }

                if ($uid)
                {
                    $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
                    $u = User::get($this->dbhr, $this->dbhm, $uid);

                    $tmps = [
                        $this->msg->getID() => [
                            'id' => $this->msg->getID()
                        ]
                    ];

                    $relateds = [];
                    $this->msg->getPublicRelated($relateds, $tmps);

                    if (($this->msg->getType() == Message::TYPE_TAKEN || $this->msg->getType(
                            ) == Message::TYPE_RECEIVED) &&
                        count($relateds[$this->msg->getID()]))
                    {
                        # This is a TAKEN/RECEIVED which has been paired to an original message.  No point
                        # showing it to the mods, as all they should do is approve it.
                        if ($log) { error_log("TAKEN/RECEIVED paired, no need to show"); }
                        $ret = MailRouter::TO_SYSTEM;
                    } else
                    {
                        # Drop unless the email comes from a group member.
                        $ret = MailRouter::DROPPED;

                        # Check the message for worry words.
                        foreach ($groups as $group) {
                            $w = new WorryWords($this->dbhr, $this->dbhm, $group['groupid']);
                            $worry = $w->checkMessage(
                                $this->msg->getID(),
                                $this->msg->getFromuser(),
                                $this->msg->getSubject(),
                                $this->msg->getTextbody()
                            );

                            $appmemb = $u->isApprovedMember($group['groupid']);
                            $ourPS = $u->getMembershipAtt($group['groupid'], 'ourPostingStatus');

                            if ($ourPS == Group::POSTING_PROHIBITED)
                            {
                                if ($log) { error_log("Prohibited, drop"); }
                                $ret = MailRouter::DROPPED;
                            } else if (!$notspam && $appmemb && $worry)
                            {
                                if ($log) { error_log("Worrying => spam"); }
                                if ($this->markPending($notspam))
                                {
                                    $ret = MailRouter::PENDING;
                                    $this->markAsSpam(Spam::REASON_WORRY_WORD, 'Referred to worry word');
                                }
                            } else {
                                if ($log) { error_log("Approved member " . $u->getEmailPreferred() . " on {$group['groupid']}? $appmemb"); }

                                if ($appmemb)
                                {
                                    # Otherwise whether we post to pending or approved depends on the group setting,
                                    # and if that is set not to moderate, the user setting.  Similar code for
                                    # this setting in message API call.
                                    #
                                    # For posts by email we moderate all posts by moderators, to avoid accidents -
                                    # this has been requested by volunteers.
                                    $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);

                                    if ($log) { error_log("Check big switch " . $g->getPrivate('overridemoderation'));
                                    }
                                    if ($g->getPrivate('overridemoderation') == Group::OVERRIDE_MODERATION_ALL)
                                    {
                                        # The Big Switch is in operation.
                                        $ps = Group::POSTING_MODERATED;
                                    } else
                                    {
                                        $ps = ($u->isModOrOwner($group['groupid']) || $g->getSetting(
                                                'moderated',
                                                0
                                            )) ? Group::POSTING_MODERATED : $ourPS;
                                        $ps = $ps ? $ps : Group::POSTING_MODERATED;
                                        if ($log)
                                        {
                                            error_log("Member of {$group['groupid']}, Our PS is $ps");
                                        }
                                    }

                                    if ($ps == Group::POSTING_MODERATED)
                                    {
                                        if ($log) { error_log("Mark as pending"); }

                                        if ($this->markPending($notspam))
                                        {
                                            $ret = MailRouter::PENDING;
                                        }
                                    } else
                                    {
                                        if ($log) { error_log("Mark as approved"); }
                                        $ret = MailRouter::FAILURE;

                                        if ($this->markApproved())
                                        {
                                            $ret = MailRouter::APPROVED;
                                        }
                                    }

                                    # Record the posting of this message.
                                    $sql = "INSERT INTO messages_postings (msgid, groupid, repost, autorepost) VALUES(?,?,?,?);";
                                    $this->dbhm->preExec($sql, [
                                        $this->msg->getId(),
                                        $g->getId(),
                                        0,
                                        0
                                    ]);
                                } else {
                                    # Not a member.  Reply to let them know.  This is particularly useful to
                                    # Trash Nothing.
                                    #
                                    # This isn't a pretty mail, but it's not a very common case at all.
                                    $this->mail(
                                        $address,
                                        $to,
                                        "Message Rejected",
                                        "You posted by email to $to, but you're not a member of that group."
                                    );
                                    $ret = MailRouter::DROPPED;
                                }
                            }
                        }

                        if ($ret == MailRouter::DROPPED) {
                            if ($log) { error_log("Not a member - drop it"); }
                        }
                    }
                }
            }
        }

        return $ret;
    }

    private function replyToSingleMessage($matches, bool $log, $ret, $spamfound)
    {
        if (!$this->msg->isBounce() && !$this->msg->isAutoreply())
        {
            $msgid = intval($matches[1]);
            $fromid = intval($matches[2]);

            $m = new Message($this->dbhr, $this->dbhm, $msgid);
            $groups = $m->getGroups(false, true);
            $closed = false;
            foreach ($groups as $gid)
            {
                $g = Group::get($this->dbhr, $this->dbhm, $gid);

                if ($g->getSetting('closed', false))
                {
                    $closed = true;
                }
            }

            if ($closed)
            {
                if ($log)
                {
                    error_log("Reply to message on closed group");
                }
                $this->mail(
                    $this->msg->getFromaddr(),
                    NOREPLY_ADDR,
                    "This community is currently closed",
                    "This Freegle community is currently closed.\r\n\r\nThis is an automated message - please do not reply."
                );
                $ret = MailRouter::TO_SYSTEM;
            } else
            {
                $u = User::get($this->dbhr, $this->dbhm, $fromid);
                $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $fromid;");

                if ($m->getID() && $u->getId() && $m->getFromuser())
                {
                    # The email address that we replied from might not currently be attached to the
                    # other user, for example if someone has email forwarding set up.  So make sure we
                    # have it.
                    $u->addEmail($this->msg->getEnvelopefrom(), 0, false);

                    # The sender of this reply will always be on our platform, because otherwise we
                    # wouldn't have generated a What's New mail to them.  So we want to set up a chat
                    # between them and the sender of the message (who might or might not be on our
                    # platform).
                    if ($log)
                    {
                        error_log(
                            "Create chat between " . $this->msg->getFromuser() . " (" . $this->msg->getFromaddr(
                            ) . ") and $fromid for $msgid"
                        );
                    }
                    $r = new ChatRoom($this->dbhr, $this->dbhm);

                    if ($fromid != $m->getFromuser()) {
                        list ($chatid, $blocked) = $r->createConversation($fromid, $m->getFromuser());

                        # Now add this into the conversation as a message.  This will notify them.
                        $textbody = $this->msg->stripQuoted();

                        if (strlen($textbody))
                        {
                            # Sometimes people will just email the photos, with no message.  We don't want to
                            # create a blank chat message in that case, and such a message would get held
                            # for review anyway.
                            $cm = new ChatMessage($this->dbhr, $this->dbhm);
                            list ($mid, $banned) = $cm->create(
                                $chatid,
                                $fromid,
                                $textbody,
                                ChatMessage::TYPE_INTERESTED,
                                $msgid,
                                false,
                                null,
                                null,
                                null,
                                null,
                                null,
                                $spamfound
                            );

                            if ($mid)
                            {
                                $cm->chatByEmail($mid, $this->msg->getID());
                            }
                        }

                        # Add any photos.
                        $this->addPhotosToChat($chatid);

                        if ($m->hasOutcome())
                        {
                            # We don't want to email the recipient - no point pestering them with more
                            # emails for items which are completed.  They can see them on the
                            # site if they want.
                            if ($log)
                            {
                                error_log("Don't mail as promised to someone else $mid");
                            }
                            $r->mailedLastForUser($m->getFromuser());
                        }

                        $ret = MailRouter::TO_USER;
                    } else {
                        if ($log) { error_log("Email reply to self"); }
                        $ret = MailRouter::DROPPED;
                    }
                }
            }
        }
        return $ret;
    }

    private function replyToChatNotification($matches, bool $log, $ret, $spamfound)
    {
        # It's a reply to an email notification.
        $chatid = intval($matches[1]);
        $userid = intval($matches[2]);

        $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
        $u = User::get($this->dbhr, $this->dbhm, $userid);

        # We want to filter out autoreplies.  But occasionally a genuine message can contain auto
        # reply text.  Most autoreplies will happen rapidly, so don't count it as an autoreply if
        # it is a bit later.  This avoids us dropped genuine messages.
        $latestmessage = $r->getPrivate('latestmessage');
        $recentmessage = $latestmessage && (time() - strtotime($latestmessage) < 5 * 60 * 60);

        if ($this->msg->isReceipt())
        {
            # This is a read receipt which has been sent to the wrong place by a silly email client.
            # Just drop these.
            if ($log) { error_log("Misdirected read receipt drop"); }
            $ret = MailRouter::DROPPED;
        } else
        {
            if (!$this->msg->isBounce() && (!$recentmessage || !$this->msg->isAutoreply()))
            {
                # Bounces shouldn't get through - might reveal info.
                #
                # Auto-replies shouldn't get through.  They're used by spammers, and generally the
                # content isn't very relevant in our case, e.g. if you're not in the office.
                $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $userid;");
                if ($r->getId())
                {
                    # It's a valid chat.
                    if ($r->getPrivate('user1') == $userid || $r->getPrivate('user2') == $userid || $u->isModerator())
                    {
                        # ...and the user we're replying to is part of it or a mod.
                        #
                        # The email address that we replied from might not currently be attached to the
                        # other user, for example if someone has email forwarding set up.  So make sure we
                        # have it.
                        $u->addEmail($this->msg->getEnvelopefrom(), 0, false);

                        # Now add this into the conversation as a message.  This will notify them.
                        $textbody = $this->msg->stripQuoted();

                        if (strlen($textbody))
                        {
                            # Sometimes people will just email the photos, with no message.  We don't want to
                            # create a blank chat message in that case, and such a message would get held
                            # for review anyway.
                            $cm = new ChatMessage($this->dbhr, $this->dbhm);
                            list ($mid, $banned) = $cm->create(
                                $chatid,
                                $userid,
                                $textbody,
                                ChatMessage::TYPE_DEFAULT,
                                null,
                                false,
                                null,
                                null,
                                null,
                                null,
                                null,
                                $spamfound
                            );

                            if ($mid)
                            {
                                $cm->chatByEmail($mid, $this->msg->getID());
                            }
                        }

                        # Add any photos.
                        $this->addPhotosToChat($chatid);

                        # It might be nice to suppress email notifications if the message has already
                        # been promised or is complete, but we don't really know which message this
                        # reply is for.

                        $ret = MailRouter::TO_USER;
                    }
                }
            } else
            {
                if ($log)
                {
                    error_log("Bounce " . $this->msg->isBounce() . " auto " . $this->msg->isAutoreply());
                }
            }
        }
        return $ret;
    }

    private function directMailToUser($u, $to, bool $log, $spamscore, $spamfound)
    {
        # See if it's a direct reply.  Auto-replies (that we can identify) we just drop.
        $uid = $u->findByEmail($to);
        if ($log)
        {
            error_log("Find direct reply from $to = user # $uid");
        }

        if ($uid && $this->msg->getFromuser() && strtolower($to) != strtolower(MODERATOR_EMAIL))
        {
            # This is to one of our users.  We try to pair it as best we can with one of the posts.
            #
            # We don't want to process replies to ModTools user.  This can happen if MT is a member
            # rather than a mod on a group.
            $this->dbhm->background(
                "UPDATE users SET lastaccess = NOW() WHERE id = " . $this->msg->getFromuser() . ";"
            );
            $original = $this->msg->findFromReply($uid);
            if ($log)
            {
                error_log("Paired with $original");
            }

            $ret = MailRouter::TO_USER;

            $textbody = $this->msg->stripQuoted();

            # If we found a message to pair it with, then we will pass that as a referenced
            # message.  If not then add in the subject line as that might shed some light on it.
            $textbody = $original ? $textbody : ($this->msg->getSubject() . "\r\n\r\n$textbody");

            # Get/create the chat room between the two users.
            if ($log)
            {
                error_log(
                    "Create chat between " . $this->msg->getFromuser() . " (" . $this->msg->getFromaddr(
                    ) . ") and $uid ($to)"
                );
            }
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            list ($rid, $blocked) = $r->createConversation($this->msg->getFromuser(), $uid);
            if ($log)
            {
                error_log("Got chat id $rid");
            }

            if ($rid)
            {
                # Add in a spam score for the message.
                if (!$spamscore)
                {
                    $this->spamc->command = 'CHECK';
                    if ($this->spamc->filter($this->msg->getMessage()))
                    {
                        $spamscore = $this->spamc->result['SCORE'];
                        if ($log)
                        {
                            error_log("Spam score $spamscore");
                        }
                    }
                }

                # And now add our text into the chat room as a message.  This will notify them.
                $m = new ChatMessage($this->dbhr, $this->dbhm);
                list ($mid, $banned) = $m->create(
                    $rid,
                    $this->msg->getFromuser(),
                    $textbody,
                    $this->msg->getModmail() ? ChatMessage::TYPE_MODMAIL : ChatMessage::TYPE_INTERESTED,
                    $original,
                    false,
                    $spamscore,
                    null,
                    null,
                    null,
                    null,
                    $spamfound
                );
                if ($log)
                {
                    error_log("Created chat message $mid");
                }

                $m->chatByEmail($mid, $this->msg->getID());

                # Add any photos.
                $this->addPhotosToChat($rid);

                if ($original)
                {
                    $m = new Message($this->dbhr, $this->dbhm, $original);

                    if ($m->hasOutcome())
                    {
                        # We don't want to email the recipient - no point pestering them with more
                        # emails for items which are completed.  They can see them on the
                        # site if they want.
                        if ($log)
                        {
                            error_log("Don't mail as promised to someone else $mid");
                        }
                        $r->mailedLastForUser($m->getFromuser());
                    }
                }
            }
        } else {
            if ($log) { error_log("Not to group and not reply - drop"); }
            $ret = MailRouter::DROPPED;
        }

        return $ret;
    }
}
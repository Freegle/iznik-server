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
    const AWAIT_COVID = 'AwaitCovid';

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
            $this->dbhm->preExec("UPDATE messages_groups SET collection = 'Spam' WHERE msgid = ?;", [
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
        $notspam = $this->msg->getPrivate('spamtype') === Spam::REASON_NOT_SPAM;
        if ($this->log) { error_log("Consider not spam $notspam from " . $this->msg->getPrivate('spamtype')); }

        $to = $this->msg->getEnvelopeto();
        $from = $this->msg->getEnvelopefrom();
        $fromheader = $this->msg->getHeader('from');

        if ($fromheader) {
            $fromheader = mailparse_rfc822_parse_addresses($fromheader);
        }

        if ($this->spam->isSpammer($from)) {
            # Mail from spammer. Drop it.
            $ret = MailRouter::DROPPED;
        } else if (strpos($this->msg->getEnvelopefrom(), '@comms.yahoo.net') !== FALSE) {
            # Announcement - drop it
            $ret = MailRouter::DROPPED;
        } else if (preg_match('/digestoff-(.*)-(.*)@/', $to, $matches) == 1) {
            # Request to turn email off.
            $uid = intval($matches[1]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
            $groupid = intval($matches[2]);

            if ($uid && $groupid) {
                $d = new Digest($this->dbhr, $this->dbhm);
                $d->off($uid, $groupid);

                $ret = MailRouter::TO_SYSTEM;
            }
        } else if (preg_match('/readreceipt-(.*)-(.*)-(.*)@/', $to, $matches) == 1) {
            # Read receipt
            $chatid = intval($matches[1]);
            $userid = intval($matches[2]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $userid;");
            $msgid = intval($matches[3]);

            # The receipt has seen this message, and the message has been seen by all people in the chat (because
            # we only generate these for user 2 user.
            $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
            if ($r->canSee($userid, FALSE)) {
                $r->updateRoster($userid, $msgid);
                $r->seenByAll($msgid);
            }

            $ret = MailRouter::RECEIPT;
        } else if (preg_match('/handover-(.*)-(.*)@/', $to, $matches) == 1) {
            # Calendar response
            $trystid = intval($matches[1]);
            $userid = intval($matches[2]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $userid;");

            # Scan for a VCALENDAR attachment.
            $t = new Tryst($this->dbhr, $this->dbhm, $trystid);
            $rsp = Tryst::OTHER;

            foreach ($this->msg->getParsedAttachments() as $att) {
                $ct = $att->getContentType();

                if (strcmp('text/calendar', strtolower($ct)) === 0) {
                    # We don't do a proper parse
                    $vcal = strtolower($att->getContent());
                    if (strpos($vcal, 'status:confirmed') !== FALSE || strpos($vcal, 'status:tentative') !== FALSE) {
                        $rsp = Tryst::ACCEPTED;
                    } else if (strpos($vcal, 'status:cancelled') !== FALSE) {
                        $rsp = Tryst::DECLINED;
                    }
                }
            }

            if ($rsp == Tryst::OTHER) {
                # Maybe they didn't put the VCALENDAR in.
                if (stripos($this->msg->getSubject(), 'accepted') !== FALSE) {
                    $rsp = Tryst::ACCEPTED;
                } else if  (stripos($this->msg->getSubject(), 'declined') !== FALSE) {
                    $rsp = Tryst::DECLINED;
                }
            }

            $t->response($userid, $rsp);

            $ret = MailRouter::TRYST;
        } else if (preg_match('/eventsoff-(.*)-(.*)@/', $to, $matches) == 1) {
            # Request to turn events email off.
            $uid = intval($matches[1]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
            $groupid = intval($matches[2]);

            if ($uid && $groupid) {
                $d = new EventDigest($this->dbhr, $this->dbhm);
                $d->off($uid, $groupid);

                $ret = MailRouter::TO_SYSTEM;
            }
        } else if (preg_match('/newslettersoff-(.*)@/', $to, $matches) == 1) {
            # Request to turn newsletters off.
            $uid = intval($matches[1]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

            if ($uid) {
                $d = new Newsletter($this->dbhr, $this->dbhm);
                $d->off($uid);

                $ret = MailRouter::TO_SYSTEM;
            }
        } else if (preg_match('/relevantoff-(.*)@/', $to, $matches) == 1) {
            # Request to turn "interested in" off.
            $uid = intval($matches[1]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

            if ($uid) {
                $d = new Relevant($this->dbhr, $this->dbhm);
                $d->off($uid);

                $ret = MailRouter::TO_SYSTEM;
            }
        } else if (preg_match('/volunteeringoff-(.*)-(.*)@/', $to, $matches) == 1) {
            # Request to turn volunteering email off.
            $uid = intval($matches[1]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
            $groupid = intval($matches[2]);

            if ($uid && $groupid) {
                $d = new VolunteeringDigest($this->dbhr, $this->dbhm);
                $d->off($uid, $groupid);

                $ret = MailRouter::TO_SYSTEM;
            }
        } else if (preg_match('/notificationmailsoff-(.*)@/', $to, $matches) == 1) {
            # Request to turn notification email off.
            $uid = intval($matches[1]);
            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

            if ($uid) {
                $d = new Notifications($this->dbhr, $this->dbhm);
                $d->off($uid);

                $ret = MailRouter::TO_SYSTEM;
            }
        } else if (strcmp($this->msg->getFromaddr(), 'support@twitter.com') === 0 &&
            preg_match('/(.*)-volunteers@' . GROUP_DOMAIN . '/', $to, $matches) &&
            strpos($this->msg->getMessage(), 'We received your appeal regarding your account.') !== FALSE
        ) {
            # We need to confirm this to allow appeals of blocked accounts.
            if ($log) {
                error_log("Confirm twitter appeal");
            }
            $this->mail($fromheader[0]['address'], $to, "Re: " . $this->msg->getSubject(), "I confirm this");
            $ret = MailRouter::TO_SYSTEM;
        } else if (preg_match('/(.*)-volunteers@' . GROUP_DOMAIN . '/', $to, $matches) ||
            preg_match('/(.*)-auto@' . GROUP_DOMAIN . '/', $to, $matches)) {
            # Mail to our owner address.  First check if it's spam according to SpamAssassin.
            if ($this->log) { error_log("To volunteers"); }

            $this->spamc->command = 'CHECK';

            $ret = MailRouter::INCOMING_SPAM;

            if ($this->spamc->filter($this->msg->getMessage())) {
                $spamscore = $this->spamc->result['SCORE'];

                if ($spamscore < MailRouter::ASSASSIN_THRESHOLD) {
                    # Now do our own checks.
                    if ($this->log) { error_log("Passed SpamAssassin $spamscore"); }
                    list ($rc, $reason) = $this->spam->checkMessage($this->msg);
                    #error_log("Spam reason " . var_export($reason, TRUE));

                    if (!$rc) {
                        $ret = MailRouter::DROPPED;

                        # Don't pass on automated mails from ADMINs - there might be loads.
                        if (preg_match('/(.*)-volunteers@' . GROUP_DOMAIN . '/', $to) ||
                            !$this->msg->isBounce() && !$this->msg->isAutoreply()) {
                            $ret = MailRouter::FAILURE;

                            # It's not.  Find the group
                            $g = new Group($this->dbhr, $this->dbhm);
                            $sn = $matches[1];

                            $gid = $g->findByShortName($sn);
                            if ($this->log) { error_log("Found $gid from $sn"); }

                            if ($gid) {
                                # It's one of our groups.  Find the user this is from.
                                $envfrom = $this->msg->getFromaddr();
                                $u = new User($this->dbhr, $this->dbhm);
                                $uid = $u->findByEmail($envfrom);
                                $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

                                if ($this->log) { error_log("Found $uid from $envfrom"); }

                                # We should always find them as Message::parse should create them
                                if ($uid) {
                                    if ($this->log) { error_log("From user $uid to group $gid"); }
                                    $u = User::get($this->dbhr, $this->dbhm, $uid);
                                    $s = new Spam($this->dbhr, $this->dbhm);

                                    # Filter out mail to volunteers from known spammers.
                                    $ret = MailRouter::INCOMING_SPAM;
                                    $spammers = $s->getSpammerByUserid($uid);

                                    if (!$spammers) {
                                        $ret = MailRouter::DROPPED;

                                        # Don't want to pass on OOF etc.
                                        if (!$this->msg->isAutoreply()) {
                                            # Create/get a chat between the sender and the group mods.
                                            $r = new ChatRoom($this->dbhr, $this->dbhm);
                                            $chatid = $r->createUser2Mod($uid, $gid);
                                            if ($this->log) {
                                                error_log("Chatid is $chatid");
                                            }

                                            # Now add this message into the chat.  Don't strip quoted as it might be useful -
                                            # one example is twitter email confirmations, where the URL is quoted (weirdly).
                                            $textbody = $this->msg->getTextbody();

                                            if (strlen($textbody)) {
                                                $m = new ChatMessage($this->dbhr, $this->dbhm);
                                                list ($mid, $banned) = $m->create($chatid, $uid, $textbody, ChatMessage::TYPE_DEFAULT, NULL, FALSE);
                                                if ($this->log) {
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
                        }
                    }
                }
            }
        } else if (preg_match('/(.*)-subscribe@' . GROUP_DOMAIN . '/', $to, $matches)) {
            $ret = MailRouter::FAILURE;

            # Find the group
            $g = new Group($this->dbhr, $this->dbhm);
            $gid = $g->findByShortName($matches[1]);
            $g = new Group($this->dbhr, $this->dbhm, $gid);

            if ($gid) {
                # It's one of our groups.  Find the user this is from.
                $envfrom = $this->msg->getEnvelopeFrom();
                $u = new User($this->dbhr, $this->dbhm);
                $uid = $u->findByEmail($envfrom);

                if (!$uid) {
                    # We don't know them yet.
                    $uid = $u->create(NULL, NULL, $this->msg->getFromname(), "Email subscription from $envfrom to " . $g->getPrivate('nameshort'));
                    $u->addEmail($envfrom, 0);
                    $pw = $u->inventPassword();
                    $u->addLogin(User::LOGIN_NATIVE, $uid, $pw);
                    $u->welcome($envfrom, $pw);
                }

                $u = new User($this->dbhr, $this->dbhm, $uid);
                $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

                # We should always find them as Message::parse should create them
                if ($u->getId()) {
                    $u->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED, NULL, $envfrom);

                    # Remove any email logs for this message - no point wasting space on keeping those.
                    $this->log->deleteLogsForMessage($this->msg->getID());
                    $ret = MailRouter::TO_SYSTEM;
                }
            }
        } else if (preg_match('/(.*)-unsubscribe@' . GROUP_DOMAIN . '/', $to, $matches)) {
            $ret = MailRouter::FAILURE;

            # Find the group
            $g = new Group($this->dbhr, $this->dbhm);
            $gid = $g->findByShortName($matches[1]);

            if ($gid) {
                # It's one of our groups.  Find the user this is from.
                $envfrom = $this->msg->getEnvelopeFrom();
                $u = new User($this->dbhr, $this->dbhm);
                $uid = $u->findByEmail($envfrom);
                $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");

                if ($uid) {
                    $u = new User($this->dbhr, $this->dbhm, $uid);
                    $ret = MailRouter::DROPPED;

                    if (!$u->isModOrOwner($gid)) {
                        $u->removeMembership($gid, FALSE, FALSE, $envfrom);

                        # Remove any email logs for this message - no point wasting space on keeping those.
                        $this->log->deleteLogsForMessage($this->msg->getID());
                        $ret = MailRouter::TO_SYSTEM;
                    }
                }
            }
        } else {
            # We use SpamAssassin to weed out obvious spam.  We only do a content check if the message subject line is
            # not in the standard format.  Most generic spam isn't in that format, and some of our messages
            # would otherwise get flagged - so this improves overall reliability.
            $contentcheck = !$notspam && !preg_match('/.*?\:(.*)\(.*\)/', $this->msg->getSubject());
            $spamscore = NULL;
            $spamfound = FALSE;

            $groups = $this->msg->getGroups(FALSE, FALSE);
            #error_log("Got groups " . var_export($groups, TRUE));

            # Check if the group wants us to check for spam.
            # TODO Multiple groups?
            foreach ($groups as $group) {
                $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
                $defs = $g->getDefaults();
                $spammers = $g->getSetting('spammers', $defs['spammers']);
                $check = array_key_exists('messagereview', $spammers) ? $spammers['messagereview'] : $defs['spammers']['messagereview'];
                $notspam = $check ? $notspam : TRUE;
                #error_log("Consider spam review $notspam from $check, " . var_export($spammers, TRUE));
            }

            if (!$notspam) {
                # First check if this message is spam based on our own checks.
                $rc = $this->spam->checkMessage($this->msg);
                if ($rc) {
                    if (count($groups) > 0) {
                        foreach ($groups as $group) {
                            $this->log->log([
                                'type' => Log::TYPE_MESSAGE,
                                'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                'msgid' => $this->msg->getID(),
                                'text' => "{$rc[2]}",
                                'groupid' => $group['groupid']
                            ]);
                        }
                    } else {
                        $this->log->log([
                            'type' => Log::TYPE_MESSAGE,
                            'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                            'msgid' => $this->msg->getID(),
                            'text' => "{$rc[2]}"
                        ]);
                    }

                    error_log("Classified as spam {$rc[2]}");
                    $ret = MailRouter::FAILURE;

                    if ($this->markAsSpam($rc[1], $rc[2])) {
                        $ret = MailRouter::INCOMING_SPAM;
                        $spamfound = TRUE;
                    }
                } else if ($contentcheck) {
                    # Now check if we think this is spam according to SpamAssassin.
                    #
                    # Need to cope with SpamAssassin being unavailable.
                    $this->spamc->command = 'CHECK';
                    $spamret = TRUE;
                    $spamscore = 0;

                    try {
                        $spamret = $this->spamc->filter($this->msg->getMessage());
                        $spamscore = $this->spamc->result['SCORE'];
                    } catch (\Exception $e) {}

                    if ($spamret) {
                        if ($spamscore >= MailRouter::ASSASSIN_THRESHOLD && ($this->msg->getEnvelopefrom() != 'from@test.com')) {
                            # This might be spam.  We'll mark it as such, then it will get reviewed.
                            #
                            # Hacky if test to stop our UT messages getting flagged as spam unless we want them to be.
                            $groups = $this->msg->getGroups(FALSE, FALSE);

                            if (count($groups) > 0) {
                                foreach ($groups as $group) {
                                    $this->log->log([
                                        'type' => Log::TYPE_MESSAGE,
                                        'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                        'msgid' => $this->msg->getID(),
                                        'text' => "SpamAssassin score $spamscore",
                                        'groupid' => $group['groupid']
                                    ]);
                                }
                            } else {
                                $this->log->log([
                                    'type' => Log::TYPE_MESSAGE,
                                    'subtype' => Log::SUBTYPE_CLASSIFIED_SPAM,
                                    'msgid' => $this->msg->getID(),
                                    'text' => "SpamAssassin score $spamscore"
                                ]);
                            }

                            if ($this->markAsSpam(Spam::REASON_SPAMASSASSIN, "SpamAssassin flagged this as possible spam; score $spamscore (high is bad)")) {
                                $ret = MailRouter::INCOMING_SPAM;
                                $spamfound = true;
                            } else {
                                error_log("Failed to mark as spam");
                                $this->msg->recordFailure('Failed to mark spam');
                                $ret = MailRouter::FAILURE;
                            }
                        }
                    } else {
                        # We have failed to check that this is spam.  Record the failure but carry on.
                        error_log("Failed to check spam " . $this->spamc->err);
                        $this->msg->recordFailure('Spam Assassin check failed ' . $this->spamc->err);
                    }
                }
            }

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
                    # We're expecting to do something with this.
                    $envto = $this->msg->getEnvelopeto();
                    if ($log) { error_log("To a group; to user $envto source " . $this->msg->getSource()); }
                    $ret = MailRouter::FAILURE;
                    $source = $this->msg->getSource();

                    if ($notspam && $source == Message::PLATFORM) {
                        # It should go into pending on here.
                        if ($log) {
                            error_log("Mark as pending");
                        }

                        if ($this->markPending($notspam)) {
                            $ret = MailRouter::PENDING;
                        }
                    } else if ($this->msg->getSource() == Message::EMAIL) {
                        $uid = $this->msg->getFromuser();
                        if ($log) { error_log("Email source, user $uid"); }

                        if ($uid) {
                            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $uid;");
                            $u = User::get($this->dbhr, $this->dbhm, $uid);

                            $tmps = [
                                $this->msg->getID() => [
                                    'id' => $this->msg->getID()
                                ]
                            ];

                            $relateds = [];
                            $this->msg->getPublicRelated($relateds, $tmps);

                            if (($this->msg->getType() == Message::TYPE_TAKEN || $this->msg->getType() == Message::TYPE_RECEIVED) &&
                                count($relateds[$this->msg->getID()])) {
                                # This is a TAKEN/RECEIVED which has been paired to an original message.  No point
                                # showing it to the mods, as all they should do is approve it.
                                if ($log) { error_log("TAKEN/RECEIVED paired, no need to show"); }
                                $ret = MailRouter::TO_SYSTEM;
                            } else {
                                # Drop unless the email comes from a group member.
                                $ret = MailRouter::DROPPED;

                                # Check the message for worry words.
                                $w = new WorryWords($this->dbhr, $this->dbhm);
                                $worry = $w->checkMessage($this->msg->getID(), $this->msg->getFromuser(), $this->msg->getSubject(), $this->msg->getTextbody());

                                foreach ($groups as $group) {
                                    $appmemb = $u->isApprovedMember($group['groupid']);

                                    if (!$u->hasCovidConfirmed()) {
                                        if ($log) { error_log("COVID Checklist required for $uid"); }
                                        $u->covidConfirm($this->msg->getID());
                                        $ret = MailRouter::AWAIT_COVID;
                                        $keepgroups = TRUE;
                                    } else if ($appmemb && $worry) {
                                        if ($log) { error_log("Worrying => pending"); }
                                        if ($this->markPending($notspam)) {
                                            $ret = MailRouter::PENDING;
                                        }
                                    } else {
                                        if ($log) { error_log("Approved member " . $u->getEmailPreferred() . " on {$group['groupid']}? $appmemb"); }
                                        if ($appmemb) {
                                            # Worrying messages always go to Pending.
                                            #
                                            # Otherwise whether we post to pending or approved depends on the group setting,
                                            # and if that is set not to moderate, the user setting.  Similar code for
                                            # this setting in message API call.
                                            #
                                            # For posts by email we moderate all posts by moderators, to avoid accidents -
                                            # this has been requested by volunteers.
                                            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);

                                            $ourPS = $u->getMembershipAtt($group['groupid'], 'ourPostingStatus');

                                            if ($ourPS == Group::POSTING_PROHIBITED) {
                                                if ($log) { error_log("Prohibited, drop"); }
                                                $ret = MailRouter::DROPPED;
                                            } else {
                                                if ($log) { error_log("Check big switch " . $g->getPrivate('overridemoderation')); }
                                                if ($g->getPrivate('overridemoderation') == Group::OVERRIDE_MODERATION_ALL) {
                                                    # The Big Switch is in operation.
                                                    $ps = Group::POSTING_MODERATED;
                                                } else {
                                                    $ps = ($u->isModOrOwner($group['groupid']) || $g->getSetting('moderated', 0)) ? Group::POSTING_MODERATED : $ourPS;
                                                    $ps = $ps ? $ps : Group::POSTING_MODERATED;
                                                    if ($log) { error_log("Member of {$group['groupid']}, Our PS is $ps"); }
                                                }

                                                if ($ps == Group::POSTING_MODERATED) {
                                                    if ($log) {
                                                        error_log("Mark as pending");
                                                    }
                                                    if ($this->markPending($notspam)) {
                                                        $ret = MailRouter::PENDING;
                                                    }
                                                } else {
                                                    if ($log) { error_log("Mark as approved"); }
                                                    $ret = MailRouter::FAILURE;

                                                    if ($this->markApproved()) {
                                                        $ret = MailRouter::APPROVED;
                                                    }
                                                }
                                            }
                                        } else {
                                            # Not a member.  Reply to let them know.  This is particularly useful to
                                            # Trash Nothing.
                                            #
                                            # This isn't a pretty mail, but it's not a very common case at all.
                                            $this->mail($fromheader[0]['address'], $to, "Message Rejected", "You posted by email to $to, but you're not a member of that group.");
                                            $ret = MailRouter::DROPPED;
                                        }
                                    }
                                }
                            }
                        }
                    }
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
                    if ($log) { error_log("Look for reply $to"); }
                    $uid = NULL;
                    $ret = MailRouter::DROPPED;

                    if (strlen($this->msg->getEnvelopeto()) && $this->msg->getEnvelopeto() == $this->msg->getEnvelopefrom()) {
                        # Sending to yourself isn't a valid path, and is used by spammers.
                        if ($log) { error_log("Sending to self " . $this->msg->getEnvelopeto() . " vs " . $this->msg->getEnvelopefrom() . " - dropped "); }
                    } else if (preg_match('/replyto-(.*)-(.*)' . USER_DOMAIN . '/', $to, $matches)) {
                        if (!$this->msg->isBounce() && !$this->msg->isAutoreply()) {
                            $msgid = intval($matches[1]);
                            $fromid = intval($matches[2]);

                            $m = new Message($this->dbhr, $this->dbhm, $msgid);
                            $groups = $m->getGroups(FALSE, TRUE);
                            $closed = FALSE;
                            foreach ($groups as $gid) {
                                $g = Group::get($this->dbhr, $this->dbhm, $gid);

                                if ($g->getSetting('closed', FALSE)) {
                                    $closed = TRUE;
                                }
                            }

                            if ($closed) {
                                if ($log) { error_log("Reply to message on closed group"); }
                                $this->mail($this->msg->getFromaddr(), NOREPLY_ADDR,  "This community is currently closed", "This Freegle community is currently closed due to COVID-19.  Your local volunteers have made this difficult decision to try to keep you safe.  Please respect it, and we hope you'll come back when the situation changes.\r\n\r\nThis is an automated message - please do not reply.");
                                $ret = MailRouter::TO_SYSTEM;
                            } else {
                                $u = User::get($this->dbhr, $this->dbhm, $fromid);
                                $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $fromid;");

                                if (!$u->hasCovidConfirmed()) {
                                    $u->covidConfirm($this->msg->getID());
                                    $ret = MailRouter::AWAIT_COVID;
                                    $keepgroups = TRUE;
                                } else if ($m->getID() && $u->getId() && $m->getFromuser()) {
                                    # The email address that we replied from might not currently be attached to the
                                    # other user, for example if someone has email forwarding set up.  So make sure we
                                    # have it.
                                    $u->addEmail($this->msg->getEnvelopefrom(), 0, FALSE);

                                    # The sender of this reply will always be on our platform, because otherwise we
                                    # wouldn't have generated a What's New mail to them.  So we want to set up a chat
                                    # between them and the sender of the message (who might or might not be on our
                                    # platform).
                                    $r = new ChatRoom($this->dbhr, $this->dbhm);
                                    $chatid = $r->createConversation($fromid, $m->getFromuser());

                                    # Now add this into the conversation as a message.  This will notify them.
                                    $textbody = $this->msg->stripQuoted();

                                    if (strlen($textbody)) {
                                        # Sometimes people will just email the photos, with no message.  We don't want to
                                        # create a blank chat message in that case, and such a message would get held
                                        # for review anyway.
                                        $cm = new ChatMessage($this->dbhr, $this->dbhm);
                                        list ($mid, $banned) = $cm->create($chatid,
                                                                           $fromid,
                                                                           $textbody,
                                                                           ChatMessage::TYPE_INTERESTED,
                                                                           $msgid,
                                                                           FALSE,
                                                                           NULL,
                                                                           NULL,
                                                                           NULL,
                                                                           NULL,
                                                                           NULL,
                                                                           $spamfound);

                                        if ($mid) {
                                            $cm->chatByEmail($mid, $this->msg->getID());
                                        }
                                    }

                                    # Add any photos.
                                    $this->addPhotosToChat($chatid);

                                    if ($m->hasOutcome() || $m->promisedButNotTo($this->msg->getFromuser())) {
                                        # We don't want to email the recipient - no point pestering them with more
                                        # emails for items which are completed or promised.  They can see them on the
                                        # site if they want.
                                        if ($log) { error_log("Don't mail as promised to someone else $mid"); }
                                        $r->mailedLastForUser($m->getFromuser());
                                    }

                                    $ret = MailRouter::TO_USER;
                                }
                            }
                        }
                    } else if (preg_match('/notify-(.*)-(.*)@/', $to, $matches)) {
                        # It's a reply to an email notification.
                        if (stripos($this->msg->getSubject(), 'Read report') === 0 ||
                            stripos($this->msg->getSubject(), 'Checked') === 0) {
                            # This is a read receipt which has been sent to the wrong place by a silly email client.
                            # Just drop these.
                            if ($log) { error_log("Misdirected read receipt drop"); }
                            $ret = MailRouter::DROPPED;
                        } else if (!$this->msg->isBounce() && !$this->msg->isAutoreply()) {
                            # Bounces shouldn't get through - might reveal info.
                            #
                            # Auto-replies shouldn't get through.  They're used by spammers, and generally the
                            # content isn't very relevant in our case, e.g. if you're not in the office.
                            $chatid = intval($matches[1]);
                            $userid = intval($matches[2]);
                            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = $userid;");
                            $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
                            $u = User::get($this->dbhr, $this->dbhm, $userid);

                            if (!$u->hasCovidConfirmed()) {
                                $u->covidConfirm($this->msg->getID());
                                $ret = MailRouter::AWAIT_COVID;
                                $keepgroups = TRUE;
                            } else if ($r->getId()) {
                                # It's a valid chat.
                                if ($r->getPrivate('user1') == $userid || $r->getPrivate('user2') == $userid || $u->isModerator()) {
                                    # ...and the user we're replying to is part of it or a mod.
                                    #
                                    # The email address that we replied from might not currently be attached to the
                                    # other user, for example if someone has email forwarding set up.  So make sure we
                                    # have it.
                                    $u->addEmail($this->msg->getEnvelopefrom(), 0, FALSE);

                                    # Now add this into the conversation as a message.  This will notify them.
                                    $textbody = $this->msg->stripQuoted();

                                    if (strlen($textbody)) {
                                        # Sometimes people will just email the photos, with no message.  We don't want to
                                        # create a blank chat message in that case, and such a message would get held
                                        # for review anyway.
                                        $cm = new ChatMessage($this->dbhr, $this->dbhm);
                                        list ($mid, $banned) = $cm->create($chatid,
                                            $userid,
                                            $textbody,
                                            ChatMessage::TYPE_DEFAULT,
                                            $this->msg->getID(),
                                            FALSE,
                                            NULL,
                                            NULL,
                                            NULL,
                                            NULL,
                                            NULL,
                                            $spamfound);

                                        $cm->chatByEmail($mid, $this->msg->getID());
                                    }

                                    # Add any photos.
                                    $this->addPhotosToChat($chatid);

                                    # It might be nice to suppress email notifications if the message has already
                                    # been promised or is complete, but we don't really know which message this
                                    # reply is for.

                                    $ret = MailRouter::TO_USER;
                                }
                            }
                        }
                    } else if (preg_match('/notify@yahoogroups.co.*/', $from)) {
                        # This is a Yahoo message which shouldn't get passed on to a non-Yahoo user.
                        if ($log) { error_log("Yahoo Notify - drop"); }
                        $ret = MailRouter::DROPPED;
                    } else if (!$this->msg->isAutoreply()) {
                        # See if it's a direct reply.  Auto-replies (that we can identify) we just drop.
                        $uid = $u->findByEmail($to);
                        if ($log) { error_log("Find reply $to = $uid"); }
                        $fromu = User::get($this->dbhr, $this->dbhm, $this->msg->getFromuser());

                        if ($this->msg->getFromuser() && !$fromu->hasCovidConfirmed()) {
                            $fromu->covidConfirm($this->msg->getID());
                            $ret = MailRouter::AWAIT_COVID;
                            $keepgroups = true;
                        } else if ($uid && $this->msg->getFromuser() && strtolower($to) != strtolower(MODERATOR_EMAIL)) {
                            # This is to one of our users.  We try to pair it as best we can with one of the posts.
                            #
                            # We don't want to process replies to ModTools user.  This can happen if MT is a member
                            # rather than a mod on a group.
                            $this->dbhm->background("UPDATE users SET lastaccess = NOW() WHERE id = " . $this->msg->getFromuser() . ";");
                            $original = $this->msg->findFromReply($uid);
                            if ($log) { error_log("Paired with $original"); }

                            $ret = MailRouter::TO_USER;

                            $textbody = $this->msg->stripQuoted();

                            # If we found a message to pair it with, then we will pass that as a referenced
                            # message.  If not then add in the subject line as that might shed some light on it.
                            $textbody = $original ? $textbody : ($this->msg->getSubject() . "\r\n\r\n$textbody");

                            # Get/create the chat room between the two users.
                            if ($log) { error_log("Create chat between " . $this->msg->getFromuser() . " (" . $this->msg->getFromaddr() . ") and " . $uid); }
                            $r = new ChatRoom($this->dbhr, $this->dbhm);
                            $rid = $r->createConversation($this->msg->getFromuser(), $uid);
                            if ($log) { error_log("Got chat id $rid"); }

                            if ($rid) {
                                # Add in a spam score for the message.
                                if (!$spamscore) {
                                    $this->spamc->command = 'CHECK';
                                    if ($this->spamc->filter($this->msg->getMessage())) {
                                        $spamscore = $this->spamc->result['SCORE'];
                                        if ($log) { error_log("Spam score $spamscore"); }
                                    }
                                }

                                # And now add our text into the chat room as a message.  This will notify them.
                                $m = new ChatMessage($this->dbhr, $this->dbhm);
                                list ($mid, $banned) = $m->create($rid,
                                    $this->msg->getFromuser(),
                                    $textbody,
                                    $this->msg->getModmail() ? ChatMessage::TYPE_MODMAIL : ChatMessage::TYPE_INTERESTED,
                                    $original,
                                    FALSE,
                                    $spamscore,
                                    NULL,
                                    NULL,
                                    NULL,
                                    NULL,
                                    $spamfound);
                                if ($log) { error_log("Created chat message $mid"); }

                                $m->chatByEmail($mid, $this->msg->getID());

                                # Add any photos.
                                $this->addPhotosToChat($rid);

                                if ($original) {
                                    $m = new Message($this->dbhr, $this->dbhm, $original);

                                    if ($m->hasOutcome() || $m->promisedButNotTo($this->msg->getFromuser())) {
                                        # We don't want to email the recipient - no point pestering them with more
                                        # emails for items which are completed or promised.  They can see them on the
                                        # site if they want.
                                        if ($log) { error_log("Don't mail as promised to someone else $mid"); }
                                        $r->mailedLastForUser($m->getFromuser());
                                    }
                                }
                            }
                        }
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
            $data = $att->getData();
            $ct = $att->getContentType();
            $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_CHAT_MESSAGE);
            try {
                $aid2 = $a->create($aid, $ct, $data);

                $hash = $a->getHash();

                if ($hash == '61e4d4a2e4bb8a5d' || $hash == '61e4d4a2e4bb8a59') {
                    # Images to suppress, e.g. our logo.
                    $a->delete();
                } else {
                    $m->setPrivate('imageid', $aid2);
                    $count++;
                }
            } catch (\Exception $e) { error_log("Create failed " . $e->getMessage()); }
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
}
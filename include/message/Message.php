<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/misc/plugin.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/message/Item.php');
require_once(IZNIK_BASE . '/include/message/WorryWords.php');
require_once(IZNIK_BASE . '/include/user/Search.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');
require_once(IZNIK_BASE . '/include/misc/Image.php');
require_once(IZNIK_BASE . '/include/misc/Location.php');
require_once(IZNIK_BASE . '/include/misc/Search.php');
require_once(IZNIK_BASE . '/include/user/PushNotifications.php');
require_once(IZNIK_BASE . '/mailtemplates/autorepost.php');
require_once(IZNIK_BASE . '/mailtemplates/chaseup.php');

# We include this directly because the composer version isn't quite right for us - see
# https://github.com/php-mime-mail-parser/php-mime-mail-parser/issues/163
require_once(IZNIK_BASE . '/lib/php-mime-mail-parser/php-mime-mail-parser/src/Parser.php');

use GeoIp2\Database\Reader;
use Oefenweb\DamerauLevenshtein\DamerauLevenshtein;

class Message
{
    const TYPE_OFFER = 'Offer';
    const TYPE_TAKEN = 'Taken';
    const TYPE_WANTED = 'Wanted';
    const TYPE_RECEIVED = 'Received';
    const TYPE_ADMIN = 'Admin';
    const TYPE_OTHER = 'Other';

    const EXPIRE_TIME = 90;

    const OUTCOME_TAKEN = 'Taken';
    const OUTCOME_RECEIVED = 'Received';
    const OUTCOME_WITHDRAWN = 'Withdrawn';
    const OUTCOME_REPOST = 'Repost';
    const OUTCOME_EXPIRED = 'Expired';

    const LIKE_LOVE = 'Love';
    const LIKE_LAUGH = 'Laugh';

    const EMAIL_REGEXP = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b/i';

    private $replycount = 0;

    // Bounce checks.
    private $bounce_subjects = [
        "Unable to deliver your message",
        "Mail delivery failed",
        "Delivery Status Notification",
        "Undelivered Mail Returned to Sender",
        "Local delivery error",
        "Returned mail",
        "delivery failure",
        "Delivery has failed",
        "Please redirect your e-mail",
        "Email delivery failure",
        "Undeliverable",
        "Auto-response",
        "Inactive account",
        "Change of email",
        "Unable to process your message",
        "Unable to process the message",
        "Has decided to leave the company",
        "No longer a valid",
        "does not exist",
        "new email address",
        "Malformed recipient",
        "spamarrest.com",
        "(Automatic Response)",
        "Automatic reply",
        "email address closure",
        "invalid address",
        "User unknown",
        'Retiring this e-mail address',
        "Could not send message",
        "Unknown user"
    ];
    
    private $bounce_bodies = [
        "I'm afraid I wasn't able to deliver your message to the following addresses.",
        "Delivery to the following recipients failed.",
        "was not delivered to",
        "550 No such user",
        "update your records",
        "has now left",
        "please note his new address",
        "this account is no longer in use",
        "Sorry, we were unable to deliver your message",
        "this email address is no longer in use",
        "we are unable to process the message"
    ];
    
    // Autoreply checks.
    private $autoreply_subjects = [
        "Auto Response",
        "Autoresponder",
        "If your enquiry is urgent",
        "Thankyou for your enquiry",
        "Thanks for your email",
        "Thanks for contacting",
        "Thank you for your enquiry",
        "Many thanks for your",
        "Automatic reply",
        "Mail Receipt",
        "Read receipt",
        "Automated reply",
        "Auto-Reply",
        "Out of Office",
        "maternity leave",
        "paternity leave",
        "return to the office",
        "due to return",
        "annual leave",
        "on holiday",
        "vacation reply"
    ];

    private $autoreply_bodies = [
        "I aim to respond within",
        "Our team aims to respond",
        "reply as soon as possible",
        'with clients right now',
        "Automated response",
        "Please note his new address",
        "THIS IS AN AUTO-RESPONSE MESSAGE",
        "out of the office",
        "Thank you so much for your email enquiry",
        "I am away",
        "I am currently away",
        "Thanks for your email enquiry",
        "don't check this very often",
        "below to complete the verification process",
        "We respond to emails as quickly as we can",
        "this email address is no longer in use",
        "away from the office",
        "I won't be able to check any emails until after",
        "I'm on leave at the moment",
        "We'll get back to you as soon as possible",
        'currently on leave'
    ];

    private $autoreply_text_start = [
        'Display message',
        'Display this message',
        'Display trusted message'
    ];
    
    static public function checkType($type) {
        switch($type) {
            case Message::TYPE_OFFER:
            case Message::TYPE_TAKEN:
            case Message::TYPE_WANTED:
            case Message::TYPE_RECEIVED:
            case Message::TYPE_ADMIN:
            case Message::TYPE_OTHER:
                $ret = $type;
                break;
            default:
                $ret = NULL;
        }
        
        return($ret);
    }
    
    static public function checkTypes($types) {
        $ret = NULL;

        if ($types) {
            $ret = [];

            foreach ($types as $type) {
                $thistype = Message::checkType($type);

                if ($thistype) {
                    $ret[] = "'$thistype'";
                }
            }
        }

        return($ret);
    }

    /**
     * @return null
     */
    public function getGroupid()
    {
        return $this->groupid;
    }

    /**
     * @return mixed
     */
    public function getYahooapprove()
    {
        return $this->yahooapprove;
    }

    public function setGroups($groups) {
        $this->groups = $groups;
    }

    public function setOutcomes($outcomes) {
        $this->outcomes = $outcomes;
    }

    public function setAttachments($attachments) {
        $this->attachments = $attachments;
    }

    public function setYahooPendingId($groupid, $id) {
        # Don't set for deleted messages, otherwise there's a timing window where we can end up with a deleted
        # message with an id that blocks inserts of subequent messages.
        $sql = "UPDATE messages_groups SET yahoopendingid = ? WHERE msgid = {$this->id} AND groupid = ? AND deleted = 0;";
        $rc = $this->dbhm->preExec($sql, [ $id, $groupid ]);

        if ($rc) {
            $this->yahoopendingid = $id;
        }
    }

    public function setYahooApprovedId($groupid, $id) {
        # Don't set for deleted messages, otherwise there's a timing window where we can end up with a deleted
        # message with an id that blocks inserts of subequent messages.
        $sql = "UPDATE messages_groups SET yahooapprovedid = ? WHERE msgid = {$this->id} AND groupid = ? AND deleted = 0;";
        $rc = $this->dbhm->preExec($sql, [ $id, $groupid ]);

        if ($rc) {
            $this->yahooapprovedid = $id;
        }
    }

    public function setPrivate($att, $val, $always = FALSE) {
        if ($this->$att != $val || $always) {
            $rc = $this->dbhm->preExec("UPDATE messages SET $att = ? WHERE id = {$this->id};", [$val]);
            if ($rc) {
                $this->$att = $val;
            }
        }
    }

    public function getPrivate($att) {
        return($this->$att);
    }

    public function setFOP($fop) {
        $this->dbhm->preExec("INSERT INTO messages_deadlines (msgid, fop) VALUES (?,?) ON DUPLICATE KEY UPDATE fop = ?;", [
            $this->id,
            $fop ? 1 : 0,
            $fop ? 1 : 0
        ]);
    }

    public function deleteItems() {
        $this->dbhm->preExec("DELETE FROM messages_items WHERE msgid = ?;", [ $this->id ]);
    }
    
    public function edit($subject, $textbody, $htmlbody, $type, $item, $location, $attachments, $checkreview = TRUE) {
        $ret = TRUE;

        # Get old values for edit history.  We put NULL if there is no edit.
        $oldtext = ($textbody || $htmlbody) ? $this->getPrivate('textbody') : NULL;
        $oldsubject = ($type || $item || $location) ? $this->getPrivate('subject') : NULL;
        $oldtype = $type ? $this->getPrivate('type') : NULL;
        $oldlocation = $location ? $this->getPrivate('locationid') : NULL;
        $olditems = NULL;

        if ($item) {
            $olditems = [];

            foreach ($this->getItems() as $olditem) {
                $olditems[] = intval($olditem['id']);
            }

            $olditems = json_encode($olditems);
        }

        $oldatts = $this->dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1) ORDER BY id;", [
            $this->id
        ]);

        $oldattachments = NULL;

        if (count($oldatts)) {
            $oldattachments = [];

            foreach ($oldatts as $oldatt) {
                $oldattachments[] = intval($oldatt['id']);
            }

            $oldattachments = json_encode($oldattachments);
        }

        if ($htmlbody && !$textbody) {
            # In the interests of accessibility, let's create a text version of the HTML
            $html = new \Html2Text\Html2Text($htmlbody);
            $textbody = $html->getText();

            # Make sure we have a text value, otherwise we might return a missing body.
            $textbody = strlen($textbody) == 0 ? ' ' : $textbody;
        }

        $me = whoAmI($this->dbhr, $this->dbhm);
        $text = ($subject ? "New subject $subject " : '');
        $text .= ($type ? "New type $type " : '');
        $text .= ($item ? "New item $item " : '');
        $text .= ($location ? "New location $location" : '');
        $text .= "Text body changed to len " . strlen($textbody);
        $text .= " HTML body changed to len " . strlen($htmlbody);

        if ($type) {
            $this->setPrivate('type', $type);
            $this->dbhm->preExec("UPDATE messages_groups SET msgtype = ? WHERE msgid = ?;", [
                $type,
                $this->id
            ]);
        }

        if ($item) {
            # Remove any old item and add this one.
            $i = new Item($this->dbhr, $this->dbhm);
            $iid = $i->create($item);

            $ret = FALSE;

            if ($iid) {
                $ret = TRUE;
                $this->deleteItems();
                $this->addItem($iid);
            }
        }

        if ($location) {
            $l = new Location($this->dbhr, $this->dbhm);
            $lid = $l->findByName($location);

            $ret = FALSE;
            if ($lid) {
                $ret = TRUE;
                $this->setPrivate('locationid', $lid);
            }
        }

        if ($ret && ($type || $item || $location)) {
            # Construct a new subject from the edited values.
            $groupids = $this->getGroups();
            $this->constructSubject($groupids[0]);
            $this->setPrivate('subject', $this->subject);
            $this->setPrivate('suggestedsubject', $this->subject);
        } else if ($subject && strlen($subject) > 10) {
            # If the subject has been edited, then that edit is more important than any suggestion we might have
            # come up with.  Don't allow stupidly short edits.
            $this->setPrivate('subject', $subject);
            $this->setPrivate('suggestedsubject', $subject);
        }

        if ($textbody) {
            $this->setPrivate('textbody', $textbody);
        }

        if ($htmlbody) {
            $this->setPrivate('htmlbody', $htmlbody);
        }

        if ($attachments !== NULL) {
            $this->replaceAttachments($attachments);
        }

        $reviewrequired = FALSE;

        if ($me && $me->getId() === $this->getFromuser() && $checkreview) {
            # Edited by the person who posted it.
            $groups = $this->getGroups(FALSE, FALSE);

            foreach ($groups as $group) {
                # Consider the posting status on this group.  The group might have a setting for moderation; failing
                # that we use the posting status on the group.
                #error_log("Consider group {$group['collection']} and status " . $me->getMembershipAtt($group['groupid'], 'ourPostingStatus'));
                $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
                $postcoll = $g->getSetting('moderated', 0) ? MessageCollection::PENDING : $me->postToCollection($group['groupid']);

                if ($group['collection'] === MessageCollection::APPROVED &&
                    $postcoll === MessageCollection::PENDING) {
                    # This message is approved, but the member is moderated.  That means the message must previously
                    # have been approved.  So this edit also needs approval.  We can't move the message back to Pending
                    # because it might already be getting replies from people.
                    $reviewrequired = TRUE;

                    # Notify the mods of the soon-to-exist pending work.
                    $n = new PushNotifications($this->dbhr, $this->dbhm);
                    $n->notifyGroupMods($group['groupid']);
                }
            }
        }

        if ($ret) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_EDIT,
                'msgid' => $this->id,
                'byuser' => $me ? $me->getId() : NULL,
                'text' => $text
            ]);

            $sql = "UPDATE messages SET editedby = ?, editedat = NOW() WHERE id = ?;";
            $this->dbhm->preExec($sql, [
                $me ? $me->getId() : NULL,
                $this->id
            ]);

            # If we edit a message and then approve it by email, Yahoo breaks the message.  So prevent that happening by
            # removing the email approval info.
            $sql = "UPDATE messages_groups SET yahooapprove = NULL, yahooreject = NULL WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                $this->id
            ]);

            # Record the edit history.
            $newitems = $item ? json_encode([ intval($iid) ]) : NULL;
            $newlocation = $location ? $this->getPrivate('locationid') : NULL;
            $newsubject = $this->getPrivate('subject');
            $newattachments = $attachments && count($attachments) ? json_encode($attachments) : NULL;

            $data = [
                $this->id,
                ($oldtext && $oldtext != $textbody) ? $oldtext : NULL,
                ($oldtext && $oldtext != $textbody) ? $textbody : NULL,
                ($oldsubject && $oldsubject != $newsubject) ? $oldsubject : NULL,
                ($oldsubject && $oldsubject != $newsubject) ? $newsubject : NULL,
                $oldtype != $type ? $oldtype : NULL,
                $oldtype != $type ? $type : NULL,
                $olditems != $newitems ? $olditems : NULL,
                $olditems != $newitems ? $newitems : NULL,
                $oldattachments != $newattachments ? $oldattachments : NULL,
                $oldattachments != $newattachments ? $newattachments : NULL,
                $oldlocation != $newlocation ? $oldlocation : NULL,
                $oldlocation != $newlocation ? $newlocation : NULL,
                $me ? $me->getId() : NULL,
                $reviewrequired
            ];

            $changes = 0;
            foreach ($data as $d) {
                if ($d !== NULL) {
                    $changes++;
                }
            }

            if ($changes > 2) {
                $this->dbhm->preExec("INSERT INTO messages_edits (msgid, oldtext, newtext, oldsubject, newsubject, 
              oldtype, newtype, olditems, newitems, oldimages, newimages, oldlocation, newlocation, byuser, reviewrequired) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);", $data);
            }
        }

        return($ret);
    }

    public function revertEdit($editid) {
        # We revert all outstanding or a specific one
        $idq = $editid ? " AND id = $editid " : " AND reviewrequired = 1 ";

        $edits = $this->dbhr->preQuery("SELECT * FROM messages_edits WHERE msgid = ? $idq ORDER BY id DESC;", [
            $this->id
        ]);

        foreach ($edits as $edit) {
            # We just edit it back to what it was.
            $item = NULL;

            if (pres('olditems', $edit)) {
                $itemid = json_decode($edit['olditems'], TRUE)[0];
                $i = new Item($this->dbhr, $this->dbhm, $itemid);
                $item = $i->getPrivate('name');
            }

            $this->edit(
                presdef('oldsubject', $edit, NULL),
                presdef('oldtext', $edit, NULL),
                NULL,
                presdef('oldtype', $edit, NULL),
                $item,
                presdef('oldlocation', $edit, NULL),
                pres('oldattachments', $edit) ? json_decode($edit['oldattachments'], TRUE) : NULL,
                FALSE
            );

            $this->dbhm->preExec("UPDATE messages_edits SET reviewrequired = 0, revertedat = NOW() WHERE id = ?;", [
                $edit['id']
            ]);
        }
    }

    public function approveEdit($editid) {
        # We approve either all outstanding or a specific one.
        $idq = $editid ? " AND id = $editid " : " AND reviewrequired = 1 ";
        $sql = "UPDATE messages_edits SET reviewrequired = 0, approvedat = NOW() WHERE msgid = ? $idq;";
        error_log("Approve $sql {$this->id}");
        $this->dbhm->preExec($sql, [
            $this->id
        ]);
    }

    /**
     * @return mixed
     */
    public function getYahooreject()
    {
        return $this->yahooreject;
    }

    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $id, $source, $sourceheader, $message, $textbody, $htmlbody, $subject, $suggestedsubject, $fromname, $fromaddr,
        $replyto, $envelopefrom, $envelopeto, $messageid, $tnpostid, $fromip, $date,
        $fromhost, $type, $attachments, $yahoopendingid, $yahooapprovedid, $yahooreject, $yahooapprove, $attach_dir, $attach_files,
        $parser, $arrival, $spamreason, $spamtype, $fromuser, $fromcountry, $deleted, $heldby, $lat = NULL, $lng = NULL, $locationid = NULL,
        $s, $editedby, $editedat, $modmail, $senttoyahoo, $FOP, $publishconsent, $isdraft, $itemid, $itemname;

    # These are used in the summary case only where a minimal message is constructed from MessageCollaction.

    private $groups = [];
    private $outcomes = [];

    /**
     * @return mixed
     */
    public function getModmail()
    {
        return $this->modmail;
    }

    /**
     * @return mixed
     */
    public function getDeleted()
    {
        return $this->deleted;
    }

    # The groupid is only used for parsing and saving incoming messages; after that a message can be on multiple
    # groups as is handled via the messages_groups table.
    private $groupid = NULL;

    private $inlineimgs = [];

    /**
     * @return mixed
     */
    public function getSpamreason()
    {
        return $this->spamreason;
    }

    # Each message has some public attributes, which are visible to API users.
    #
    # Which attributes can be seen depends on the currently logged in user's role on the group.
    #
    # Other attributes are only visible within the server code.
    public $nonMemberAtts = [
        'id', 'subject', 'suggestedsubject', 'type', 'arrival', 'date', 'deleted', 'heldby', 'textbody', 'htmlbody', 'senttoyahoo', 'FOP', 'fromaddr', 'isdraft'
    ];

    public $memberAtts = [
        'fromname', 'fromuser', 'modmail'
    ];

    public $moderatorAtts = [
        'source', 'sourceheader', 'envelopefrom', 'envelopeto', 'messageid', 'tnpostid',
        'fromip', 'fromcountry', 'message', 'spamreason', 'spamtype', 'replyto', 'editedby', 'editedat', 'locationid'
    ];

    public $ownerAtts = [
        # Add in a dup for UT coverage of loop below.
        'source'
    ];

    public $internalAtts = [
        'publishconsent', 'itemid', 'itemname', 'lat', 'lng'
    ];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $atts = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->log = new Log($this->dbhr, $this->dbhm);
        $this->notif = new PushNotifications($this->dbhr, $this->dbhm);

        if ($id) {
            if (!$atts) {
                # We need to fetch.  Similar logic in MessageCollection::fillIn.
                #
                # When constructing we do some LEFT JOINs with other tables where we expect to only have one row at most.
                # This saves queries later, which is a round trip to the DB server.
                #
                # Don't try to cache message info - too many of them.
                $msgs = $dbhr->preQuery("SELECT messages.*, messages_deadlines.FOP, users.publishconsent, CASE WHEN messages_drafts.msgid IS NOT NULL THEN 1 ELSE 0 END AS isdraft, messages_items.itemid AS itemid, items.name AS itemname FROM messages LEFT JOIN messages_deadlines ON messages_deadlines.msgid = messages.id LEFT JOIN users ON users.id = messages.fromuser LEFT JOIN messages_drafts ON messages_drafts.msgid = messages.id LEFT JOIN messages_items ON messages_items.msgid = messages.id LEFT JOIN items ON items.id = messages_items.itemid WHERE messages.id = ?;", [$id], FALSE, FALSE);
                foreach ($msgs as $msg) {
                    $this->id = $id;

                    # FOP defaults on for our messages.
                    if ($msg['source'] == Message::PLATFORM && $msg['type'] == Message::TYPE_OFFER && $msg['FOP'] === NULL) {
                        $msg['FOP'] = 1;
                    }

                    foreach (array_merge($this->nonMemberAtts, $this->memberAtts, $this->moderatorAtts, $this->ownerAtts, $this->internalAtts) as $attr) {
                        if (pres($attr, $msg)) {
                            $this->$attr = $msg[$attr];
                        }
                    }
                }

                # We parse each time because sometimes we will ask for headers.  Note that if we're not in the initial parse/save of
                # the message we might be parsing from a modified version of the source.
                $this->parser = new PhpMimeMailParser\Parser();
                $this->parser->setText($this->message);
            } else {
                foreach ($atts as $att => $val) {
                    $this->$att = $val;
                }
            }
        }

        $start = strtotime("30 days ago");
        $this->s = new Search($dbhr, $dbhm, 'messages_index', 'msgid', 'arrival', 'words', 'groupid', $start, 'words_cache');
    }

    /**
     * @param Search $search
     */
    public function setSearch($search)
    {
        $this->s = $search;
    }

    public function mailer($user, $modmail, $toname, $to, $bcc, $fromname, $from, $subject, $text) {
        # These mails don't need tracking, so we don't call addHeaders.
        try {
            #error_log(session_id() . " mail " . microtime(true));

            list ($transport, $mailer) = getMailer();
            
            $message = Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom([$from => $fromname])
                ->setTo([$to => $toname])
                ->setBody($text);

            # We add some headers so that if we receive this back, we can identify it as a mod mail.
            $headers = $message->getHeaders();

            if ($user) {
                $headers->addTextHeader('X-Iznik-From-User', $user->getId());
            }

            $headers->addTextHeader('X-Iznik-ModMail', $modmail);

            if ($bcc) {
                $message->setBcc(explode(',', $bcc));
            }

            $mailer->send($message);

            # Stop the transport, otherwise the message doesn't get sent until the UT script finishes.
            $transport->stop();

            #error_log(session_id() . " mailed " . microtime(true));
        } catch (Exception $e) {
            # Not much we can do - shouldn't really happen given the failover transport.
            // @codeCoverageIgnoreStart
            error_log("Send failed with " . $e->getMessage());
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Get the roles for multiple messages.
     *
     * @param bool Allow system role overrides
     * @param null $me
     * @param int $myid
     * @param array $msgs
     * @return mixed
     */
    public function getRolesForMessages($me = NULL, $msgs, $overrides = TRUE) {
        $me = $me ? $me : whoAmI($this->dbhr, $this->dbhm);
        $ret = [];
        $groups = NULL;

        foreach ($msgs as $msg) {
            # Our role for a message is the highest role we have on any group that this message is on.  That means that
            # we have limited access to information on other groups of which we are not a moderator, but that is legitimate
            # if the message is on our group.
            #
            # We might also be a partner, which allows us to appear like a member rather than a non-member.
            $role = pres('partner', $_SESSION) ? User::ROLE_MEMBER : User::ROLE_NONMEMBER;
            $groupid = NULL;

            if ($me) {
                if ($me->getId() == $msg['fromuser']) {
                    # It's our message.  We have full rights.
                    $role = User::ROLE_MODERATOR;
                } else {
                    if (!$groups) {
                        $msgids = array_filter(array_column($msgs, 'id'));
                        $sql = "SELECT role, messages_groups.groupid, messages_groups.collection, messages_groups.msgid FROM memberships
                              INNER JOIN messages_groups ON messages_groups.groupid = memberships.groupid
                                  AND userid = ? AND messages_groups.msgid IN (" . implode(',', $msgids) . ");";
                        $groups = $this->dbhr->preQuery($sql, [
                            $me->getId()
                        ]);
                    }

                    #error_log("$sql {$this->id}, " . $me->getId() . " " . var_export($groups, TRUE));

                    foreach ($groups as $group) {
                        if ($msg['id'] === $group['msgid']) {
                            switch ($group['role']) {
                                case User::ROLE_OWNER:
                                    # Owner is highest.
                                    $role = $group['role'];
                                    break;
                                case User::ROLE_MODERATOR:
                                    # Upgrade from member or non-member to mod.
                                    $role = ($role == User::ROLE_MEMBER || $role == User::ROLE_NONMEMBER) ? User::ROLE_MODERATOR : $role;
                                    break;
                                case User::ROLE_MEMBER:
                                    # Just a member
                                    $role = User::ROLE_MEMBER;
                                    break;
                            }

                            $groupid = $group['groupid'];
                        }
                    }

                    if ($overrides) {
                        switch ($me->getPrivate('systemrole')) {
                            case User::SYSTEMROLE_SUPPORT:
                                $role = User::ROLE_MODERATOR;
                                break;
                            case User::SYSTEMROLE_ADMIN:
                                $role = User::ROLE_OWNER;
                                break;
                        }
                    }
                }
            }

            if ($role == User::ROLE_NONMEMBER && presdef('isdraft', $msg, FALSE)) {
                # We can potentially upgrade our role if this is one of our drafts.
                $drafts = $this->dbhr->preQuery("SELECT * FROM messages_drafts WHERE msgid = ? AND session = ? OR (userid = ? AND userid IS NOT NULL);", [
                    $msg['id'],
                    session_id(),
                    $me ? $me->getId() : NULL
                ]);

                foreach ($drafts as $draft) {
                    $role = User::ROLE_MODERATOR;
                }
            }

            $ret[$msg['id']] = [ $role, $groupid ];
        }

        return($ret);
    }

    /**
     * Get the role for this message.
     *
     * @param bool Allow system role overrides
     * @param null $me
     * @return mixed
     */
    public function getRoleForMessage($overrides = TRUE, $me = NULL) {
        # Use the multi-message method.
        return($this->getRolesForMessages($me, $this->getThisAsArray(), $overrides)[$this->id]);
    }

    public function canSees($msgs) {
        # Can we see these messages?  This is called after getPublic because most of the time we can, and doing
        # that saves queries.
        $cansees = [];
        $drafts = NULL;

        foreach ($msgs as $atts) {
            # We can see messages if:
            # - we're a mod or an owner, or
            # - it's a message on a Freegle group, specifically including
            #    - Pending to allow Facebook share preview, and
            #    - TrashNothing message (the TN TOS allows this).
            $role = $atts['myrole'];

            $cansee = $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER;

            if (!$cansee) {
                foreach ($atts['groups'] as $group) {
                    $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
                    #error_log("Consider show " . $this->getID());
                    #error_log("...plat or TN " . ($this->getSourceheader() == Message::PLATFORM || strpos($this->getFromaddr(), '@user.trashnothing.com') !== FALSE));
                    #error_log("...consent || member " . ($atts['publishconsent'] || $role == User::ROLE_MEMBER));
                    #error_log("...coll == APPROVED " . ($group['collection'] == MessageCollection::APPROVED));
                    #error_log("...type == FREEGLE " . ($g->getPrivate('type') == Group::GROUP_FREEGLE));
                    #error_log("...onhere " . $g->getPrivate('onhere'));

                    if ($g->getPrivate('type') == Group::GROUP_FREEGLE) {
                        $cansee = TRUE;
                    }
                }
            }

            #error_log("Cansee now $cansee");

            if (!$cansee) {
                # We can see our drafts.
                if ($drafts === NULL) {
                    $drafts = [];

                    $me = whoAmI($this->dbhr, $this->dbhm);
                    if ($me) {
                        $msgids = array_filter(array_column($msgs, 'id'));
                        $drafts = $this->dbhr->preQuery("SELECT * FROM messages_drafts WHERE msgid IN (" . implode(',', $msgids) . ") AND session = ? OR (userid = ? AND userid IS NOT NULL);", [
                            session_id(),
                            $me->getId()
                        ]);
                    }
                }

                foreach ($drafts as $draft) {
                    if ($draft['msgid'] == $atts['id']) {
                        $cansee = TRUE;
                    }
                }
            }

            $cansees[$atts['id']] = $cansee;
        }

        return($cansees);
    }

    public function canSee($atts) {
        $cansees = $this->canSees([ $atts ]);
        return($cansees[$atts['id']]);
    }

    public function stripGumf() {
        # We have the same function in views/user/message.js; keep thenm in sync.
        $text = $this->getTextbody();

        if ($text) {
            // console.log("Strip photo", text);
            // Strip photo links - we should have those as attachments.
            $text = preg_replace('/You can see a photo[\s\S]*?jpg/', '', $text);
            $text = preg_replace('/Check out the pictures[\s\S]*?https:\/\/trashnothing[\s\S]*?pics\/[a-zA-Z0-9]*/', '', $text);
            $text = preg_replace('/You can see photos here[\s\S]*jpg/m', '', $text);
            $text = preg_replace('/https:\/\/direct.*jpg/m', '', $text);
            $text = preg_replace('/Photos\:[\s\S]*?jpg/', '', $text);

            // FOPs
            $text = preg_replace('/Fair Offer Policy applies \(see https:\/\/[\s\S]*\)/', '', $text);
            $text = preg_replace('/Fair Offer Policy:[\s\S]*?reply./', '', $text);

            // App footer
            $text = preg_replace('/Freegle app.*[0-9]$/m', '', $text);

            // Footers
            $text = preg_replace('/--[\s\S]*Get Freegling[\s\S]*book/m', '', $text);
            $text = preg_replace('/--[\s\S]*Get Freegling[\s\S]*org[\s\S]*?<\/a>/m', '', $text);
            $text = preg_replace('/This message was sent via Freegle Direct[\s\S]*/m', '', $text);
            $text = preg_replace('/\[Non-text portions of this message have been removed\]/m', '', $text);
            $text = preg_replace('/^--$[\s\S]*/m', '', $text);
            $text = preg_replace('/==========/m', '', $text);

            // Redundant line breaks.
            $text = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\n\n", $text);

            // Duff text added by Yahoo Mail app.
            $text = str_replace('blockquote, div.yahoo_quoted { margin-left: 0 !important; border-left:1px #715FFA solid !important; padding-left:1ex !important; background-color:white !important; }', '', $text);

            $text = trim($text);
        }
        
        return($text ? $text : '');
    }

    private function getUser($uid, $messagehistory, &$userlist, $info, $groupids = NULL, $obj = FALSE) {
        # Get the user details, relative to the groups this message appears on.
        $key = "$uid-$messagehistory-" . ($groupids ? implode(',', $groupids) : '');
        if ($userlist && array_key_exists($key, $userlist)) {
            $u = $userlist[$key][0];
            $atts = $userlist[$key][1];
        } else {
            $u = User::get($this->dbhr, $this->dbhm, $uid);
            $ctx = NULL;
            $atts = $u->getPublic($groupids, $messagehistory, FALSE, $ctx, MODTOOLS, MODTOOLS, MODTOOLS, FALSE, FALSE);

            if ($info) {
                $atts['info'] = $u->getInfo();
            }

            # Save for next time.
            $userlist[$key] = [ $u, $atts];
        }

        return($obj ? $u : $atts);
    }

    private function getLocation($locationid, &$locationlist) {
        if (!$locationlist || !array_key_exists($locationid, $locationlist)) {
            $locationlist[$locationid] = new Location($this->dbhr, $this->dbhm, $locationid);
        }

        return($locationlist[$locationid]);
    }

    public function promiseCount() {
        $sql = "SELECT COUNT(*) AS count FROM messages_promises WHERE msgid = ?;";
        $promises = $this->dbhr->preQuery($sql, [$this->id]);
        return($promises[0]['count']);
    }

    private function getPublicAtts($me, $myid, $msgs, $roles, $seeall, $summary) {
        # Get the attributes which are visible based on our role.
        $rets = [];

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];
            $ret = [];
            $ret['myrole'] = $role;

            foreach ($this->nonMemberAtts as $att) {
                $ret[$att] = presdef($att, $msg, NULL);
            }

            if ($role == User::ROLE_MEMBER || $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || $seeall) {
                foreach ($this->memberAtts as $att) {
                    $ret[$att] = presdef($att, $msg, NULL);
                }
            }

            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || $seeall) {
                foreach ($this->moderatorAtts as $att) {
                    $ret[$att] = presdef($att, $msg, NULL);
                }
            }

            if ($role == User::ROLE_OWNER || $seeall) {
                foreach ($this->ownerAtts as $att) {
                    $ret[$att] = presdef($att, $msg, NULL);
                }
            }

            # URL people can follow to get to the message on our site.
            $ret['url'] = 'https://' . USER_SITE . '/message/' . $msg['id'];

            $ret['mine'] = $myid && $msg['fromuser'] == $myid;

            # If we are a mod with sufficient rights on this message, we can edit it.
            #
            # In the non-summary case we may be able to for our own messages, lower down.
            $ret['canedit'] = $myid && $me->isModerator() && ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER);

            # Remove any group subject tag.
            $ret['subject'] = preg_replace('/^\[.*?\]\s*/', '', $ret['subject']);
            $ret['subject'] = preg_replace('/\[.*Attachment.*\]\s*/', '', $ret['subject']);

            # Decode any HTML which is wrongly in there.
            $ret['subject'] = html_entity_decode($ret['subject']);

            # Add derived attributes.
            $ret['arrival'] = ISODate($ret['arrival']);
            $ret['date'] = ISODate($ret['date']);
            $ret['daysago'] = floor((time() - strtotime($ret['date'])) / 86400);
            $ret['snippet'] = pres('textbody', $ret) ? substr($ret['textbody'], 0, 60) : null;

            if (pres('fromcountry', $ret)) {
                $ret['fromcountry'] = code_to_country($ret['fromcountry']);
            }

            # TODO Is this still relevant?
            $ret['publishconsent'] = pres('publishconsent', $msg) ? TRUE : FALSE;

            if (!$summary) {
                if ($role == User::ROLE_NONMEMBER) {
                    # For non-members we want to strip out any potential phone numbers or email addresses.
                    $ret['textbody'] = preg_replace('/[0-9]{4,}/', '***', $ret['textbody']);
                    $ret['textbody'] = preg_replace(Message::EMAIL_REGEXP, '***@***.com', $ret['textbody']);

                    # We can't do this in HTML, so just zap it.
                    $ret['htmlbody'] = NULL;
                }

                # We have a flag for FOP - but legacy posting methods might put it in the body.
                $ret['FOP'] = (pres('textbody', $ret) && (strpos($ret['textbody'], 'Fair Offer Policy') !== FALSE) || $ret['FOP']) ? 1 : 0;
            }

            $rets[$msg['id']] = $ret;
        }

        return($rets);
    }

    private function getThisAsArray() {
        # This is a rather hacky function.  We have methods which work on an array of message attributes, and we
        # also have an instance of Message which represents a single one, where the attributes are properties of the
        # object.  This method allows us to convert an object into an array for use with those methods.
        #
        # In retrospect we would implement Message rather differently.
        $ret = [];

        foreach (array_merge($this->nonMemberAtts, $this->memberAtts, $this->moderatorAtts, $this->ownerAtts, $this->internalAtts) as $attr) {
            if (property_exists($this, $attr)) {
                $ret[$attr] = $this->$attr;
            }
        }

        $ret['attachments'] = $this->attachments;
        return([$ret]);
    }

    public function getPublicGroups($me, $myid, &$userlist, &$rets, $msgs, $roles, $summary, $seeall) {
        $msgids = array_filter(array_column($msgs, 'id'));
        $groups = NULL;
        $approvedcache = [];

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if (!$summary) {
                # In the summary case we fetched the groups in MessageCollection.  Otherwise we won't have fetched the groups yet.
                if ($groups === NULL) {
                    $groups = [];

                    if ($msgids) {
                        $sql = "SELECT *, TIMESTAMPDIFF(HOUR, arrival, NOW()) AS hoursago FROM messages_groups WHERE msgid IN (" . implode (',', $msgids ) . ") AND deleted = 0;";
                        $groups = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                    }
                }

                $retgroups = [];
                foreach ($groups as $group) {
                    if ($group['msgid'] == $rets[$msg['id']]['id']) {
                        #error_log("Add message {$msg['id']} {$msg['subject']} group {$group['msgid']}, {$rets[$msg['id']]['id']} val {$group['groupid']}");
                        $retgroups[] = $group;
                    }
                }

                $rets[$msg['id']]['groups'] = $retgroups;
            } else {
                $rets[$msg['id']]['groups'] = presdef('groups', $msg, []);
            }

            $rets[$msg['id']]['showarea'] = TRUE;
            $rets[$msg['id']]['showpc'] = TRUE;

            # We don't use foreach with & because that copies data by reference which causes bugs.
            for ($groupind = 0; $groupind < count($rets[$msg['id']]['groups']); $groupind++ ) {
                if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || $seeall) {
                    if (pres('approvedby', $rets[$msg['id']]['groups'][$groupind])) {
                        if (!pres($rets[$msg['id']]['groups'][$groupind]['approvedby'], $approvedcache)) {
                            $appby = $this->dbhr->preQuery("SELECT id, fullname, firstname, lastname FROM users WHERE id = ?;", [
                                $rets[$msg['id']]['groups'][$groupind]['approvedby']
                            ]);

                            foreach ($appby as $app) {
                                $name = pres('fullname', $app) ? $app['fullname'] : "{$app['firstname']} {$app['lastname']}";
                                $approvedcache[$rets[$msg['id']]['groups'][$groupind]['approvedby']] = [
                                    'id' => $rets[$msg['id']]['groups'][$groupind]['approvedby'],
                                    'displayname' => $name
                                ];
                            }
                        }

                        $rets[$msg['id']]['groups'][$groupind]['approvedby'] = $approvedcache[$rets[$msg['id']]['groups'][$groupind]['approvedby']];
                    }
                }

                $rets[$msg['id']]['groups'][$groupind]['arrival'] = ISODate($rets[$msg['id']]['groups'][$groupind]['arrival']);
                $g = Group::get($this->dbhr, $this->dbhm, $rets[$msg['id']]['groups'][$groupind]['groupid']);
                $rets[$msg['id']]['groups'][$groupind]['namedisplay'] = $g->getName();
                #error_log("Message {$group['msgid']} {$group['groupid']} {$group['namedisplay']}");

                # Work out the maximum number of autoreposts to prevent expiry before that has occurred.
                $reposts = $g->getSetting('reposts', [ 'offer' => 3, 'wanted' => 14, 'max' => 10, 'chaseups' => 2]);
                $repost = $msg['type'] == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];
                $maxreposts = $repost * $reposts['max'];
                $rets[$msg['id']]['expiretime'] = max(Message::EXPIRE_TIME, $maxreposts);

                if (array_key_exists('canedit', $rets[$msg['id']]) && !$rets[$msg['id']]['canedit'] && $myid && $myid === $msg['fromuser'] && $msg['source'] == Message::PLATFORM) {
                    # This is our own message, which we may be able to edit if the group allows it.
                    $allowedits = $g->getSetting('allowedits', [ 'moderated' => TRUE, 'group' => TRUE ]);
                    $ourPS = $me->getMembershipAtt($rets[$msg['id']]['groups'][$groupind]['groupid'], 'ourPostingStatus');

                    if (((!$ourPS || $ourPS === Group::POSTING_MODERATED) && $allowedits['moderated']) ||
                        ($ourPS === Group::POSTING_DEFAULT && $allowedits['group'])) {
                        # Yes, we can edit.
                        $rets[$msg['id']]['canedit'] = TRUE;
                    }
                }

                if (!$summary) {
                    $keywords = $g->getSetting('keywords', $g->defaultSettings['keywords']);
                    $rets[$msg['id']]['keyword'] = presdef(strtolower($msg['type']), $keywords, $msg['type']);

                    # Some groups disable the area or postcode.  If so, hide that.
                    $includearea = $g->getSetting('includearea', TRUE);
                    $includepc = $g->getSetting('includepc', TRUE);
                    $rets[$msg['id']]['showarea'] = !$includearea ? FALSE : $rets[$msg['id']]['showarea'];
                    $rets[$msg['id']]['showpc'] = !$includepc ? FALSE : $rets[$msg['id']]['showpc'];

                    if (pres('mine', $rets[$msg['id']])) {
                        # Can we repost?
                        $rets[$msg['id']]['canrepost'] = FALSE;

                        $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
                        $interval = $msg['type'] == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];
                        $arrival = strtotime($rets[$msg['id']]['groups'][$groupind]['arrival']);

                        if ($interval < 365) {
                            # Some groups set very high values as a way of turning this off.
                            $rets[$msg['id']]['canrepostat'] = ISODate('@' . ($arrival + $interval * 3600 * 24));

                            if ($rets[$msg['id']]['groups'][$groupind]['hoursago'] > $interval * 24) {
                                $rets[$msg['id']]['canrepost'] = TRUE;
                            }
                        }
                    }
                }
            }
        }
    }

    public function getPublicLocation($me, $myid, &$rets, $msgs, $roles, $seeall, &$locationlist) {
        $l = new Location($this->dbhr, $this->dbhm);

        # Cache the locations we'll need efficiently.
        $locids = array_filter(array_column($msgs, 'locationid'));
        $l->getByIds($locids, $locationlist);

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if (pres('locationid', $msg)) {
                $l = $this->getLocation($msg['locationid'], $locationlist);

                # We can always see any area and top-level postcode.  If we're a mod or this is our message
                # we can see the precise location.
                if (pres('showarea', $rets[$msg['id']])) {
                    $areaid = $l->getPrivate('areaid');
                    if ($areaid) {
                        # This location is quite specific.  Return the area it's in.
                        $a = $this->getLocation($areaid, $locationlist);
                        $rets[$msg['id']]['area'] = $a->getPublic();
                    } else {
                        # This location isn't in an area; it is one.  Return it.
                        $rets[$msg['id']]['area'] = $l->getPublic();
                    }
                }

                if (pres('showpc', $rets[$msg['id']])) {
                    $pcid = $l->getPrivate('postcodeid');
                    if ($pcid) {
                        $p = $this->getLocation($pcid, $locationlist);
                        $rets[$msg['id']]['postcode'] = $p->getPublic();
                    }
                }

                if ($seeall || $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER || ($myid && $msg['fromuser'] == $myid)) {
                    $rets[$msg['id']]['location'] = $l->getPublic();
                }
            }

            unset($rets[$msg['id']]['showarea']);
            unset($rets[$msg['id']]['showpc']);
        }
    }

    public function getPublicItem(&$rets, $msgs) {
        $search = [];

        foreach ($msgs as $msg) {
            if (pres('itemid', $msg)) {
                $rets[$msg['id']]['item'] = [
                    'id' => $msg['itemid'],
                    'name' => $msg['itemname']
                ];
            } else if (preg_match("/(.+)\:(.+)\((.+)\)/", $rets[$msg['id']]['subject'], $matches)) {
                # See if we can find it.
                $item = trim($matches[2]);
                $itemid = NULL;
                $search[] = [
                    'id' => $msg['id'],
                    'item' => $item
                ];
            }
        }

        if (count($search)) {
            $sql = "";
            foreach ($search as $s) {
                if ($sql !== '') {
                    $sql .= " UNION ";
                }

                $sql .= "SELECT items.id, items.name FROM items WHERE name LIKE " . $this->dbhr->quote($s['item']);
            }

            $items = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);

            foreach ($items as $item) {
                foreach ($rets as &$ret) {
                    if (!pres('item', $ret) && $ret['id'] == $s['id']) {
                        $ret['item'] = [
                            'id' => $item['id'],
                            'name' => $item['name']
                        ];
                    }
                }
            }
        }
    }

    public function getPublicReplies($me, $myid, &$rets, $msgs, $summary, $roles, $seeall, $messagehistory) {
        $allreplies = NULL;
        $lastreplies = NULL;
        $allpromises = NULL;
        $replyusers = [];

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if ($summary) {
                # We set this when constructing from MessageCollection.
                $rets[$msg['id']]['replycount'] = presdef('replycount', $msg, 0);
            } else if (!$summary) {
                if ($allreplies === NULL) {
                    # Get all the replies for these messages.
                    $msgids = array_filter(array_column($msgs, 'id'));
                    $allreplies = [];

                    if (count($msgids)) {
                        $sql = "SELECT DISTINCT t.* FROM (
SELECT chat_messages.id, chat_messages.refmsgid, chat_roster.status , chat_messages.userid, chat_messages.chatid, MAX(chat_messages.date) AS lastdate FROM chat_messages 
LEFT JOIN chat_roster ON chat_messages.chatid = chat_roster.chatid AND chat_roster.userid = chat_messages.userid 
WHERE refmsgid IN (" . implode(',', $msgids) . ") AND reviewrejected = 0 AND reviewrequired = 0 AND chat_messages.type = ? GROUP BY chat_messages.userid, chat_messages.chatid, chat_messages.refmsgid) t 
ORDER BY lastdate DESC;";

                        $res = $this->dbhr->preQuery($sql, [
                            ChatMessage::TYPE_INTERESTED
                        ], NULL, FALSE);

                        foreach ($res as $r) {
                            if (!pres($r['refmsgid'], $allreplies)) {
                                $allreplies[$r['refmsgid']] = [$r];
                            } else {
                                $allreplies[$r['refmsgid']][] = $r;
                            }
                        }

                        $userids = array_filter(array_column($res, 'userid'));
                        if (count($userids)) {
                            $u = new User($this->dbhr, $this->dbhm);
                            $ctx = NULL;
                            $replyusers = $u->getPublicsById($userids, NULL, $messagehistory, FALSE, $ctx, MODTOOLS, MODTOOLS, MODTOOLS, MODTOOLS, FALSE, [MessageCollection::APPROVED], FALSE);
                            $u->getInfos($replyusers);
                        }
                    }
                }

                # Can always see the replycount.  The count should include even people who are blocked.
                $replies = presdef($msg['id'], $allreplies, []);
                $rets[$msg['id']]['replies'] = [];
                $rets[$msg['id']]['replycount'] = count($replies);

                # Can see replies if:
                # - we want everything
                # - we're on ModTools and we're a mod for this message
                # - it's our message
                if ($seeall || (MODTOOLS && ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER)) || ($myid && $msg['fromuser'] == $myid)) {
                    # Add replies, as long as they're not awaiting review or rejected, or blocked.
                    $ourreplies = [];
                    foreach ($replies as $reply) {
                        $ctx = NULL;
                        if ($reply['userid'] && $reply['status'] != ChatRoom::STATUS_BLOCKED) {
                            $thisone = [
                                'id' => $reply['id'],
                                'user' => $replyusers[$reply['userid']],
                                'chatid' => $reply['chatid']
                            ];

                            # Add the last reply date and a snippet.
                            if (!$lastreplies) {
                                # Get the last replies for all the relevant chats.
                                $chatids = [];
                                foreach ($allreplies as $allreplyid => $allreply) {
                                    $chatids = array_merge($chatids, array_filter(array_column($allreply, 'chatid')));
                                }

                                $sql = "SELECT DISTINCT m1.* FROM chat_messages m1 LEFT JOIN chat_messages m2 ON (m1.chatid = m2.chatid AND m1.id < m2.id) WHERE m2.id IS NULL AND m1.chatid IN (" . implode(',', $chatids) . ");";
                                $lastreplies = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                            }

                            foreach ($lastreplies as $lastreply) {
                                if ($lastreply['chatid'] == $reply['chatid']) {
                                    $thisone['lastdate'] = ISODate($lastreply['date']);
                                    $thisone['snippet'] = substr($lastreply['message'], 0, 30);
                                }
                            }

                            $ourreplies[] = $thisone;;
                        }
                    }

                    $rets[$msg['id']]['replies'] = $ourreplies;

                    # Whether or not we will auto-repost depends on whether there are replies.
                    $rets[$msg['id']]['willautorepost'] = count($rets[$msg['id']]['replies']) == 0;

                    $rets[$msg['id']]['promisecount'] = 0;
                }

                if ($msg['type'] == Message::TYPE_OFFER) {
                    # Add promises, i.e. one or more people we've said can have this.
                    if (!$allpromises) {
                        $msgids = array_filter(array_column($msgs, 'id'));
                        $sql = "SELECT * FROM messages_promises WHERE msgid IN (" . implode(',', $msgids) . ");";
                        $ps = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
                        $allpromises = [];

                        foreach ($ps as $p) {
                            if (!pres($p['msgid'], $allpromises)) {
                                $allpromises[$p['msgid']] = [ $p ];
                            } else {
                                $allpromises[$p['msgid']][] = $p;
                            }
                        }
                    }

                    $promises = presdef($msg['id'], $allpromises, []);

                    if ($seeall || (MODTOOLS && ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER)) || ($myid && $msg['fromuser'] == $myid)) {
                        $rets[$msg['id']]['promises'] = $promises;

                        foreach ($rets[$msg['id']]['replies'] as $key => $reply) {
                            foreach ($rets[$msg['id']]['promises'] as $promise) {
                                $rets[$msg['id']]['replies'][$key]['promised'] = presdef('promised', $reply, FALSE) || ($promise['userid'] == $reply['user']['id']);
                            }
                        }
                    }

                    $rets[$msg['id']]['promisecount'] = count($promises);
                    $rets[$msg['id']]['promised'] = count($promises) > 0;
                }
            }
        }
    }

    public function getPublicOutcomes($me, $myid, &$rets, $msgs, $summary, $roles, $seeall) {
        $outcomes = NULL;

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];
            $rets[$msg['id']]['outcomes'] = [];

            # Add any outcomes.  No need to expand the user as any user in an outcome should also be in a reply.
            if ($summary) {
                # We set this when constructing.
                $rets[$msg['id']]['outcomes'] = presdef('outcomes', $msg, []);
            } else {
                if ($outcomes === NULL) {
                    $msgids = array_filter(array_column($msgs, 'id'));
                    $outcomes = [];

                    if (count($msgids)) {
                        $sql = "SELECT * FROM messages_outcomes WHERE msgid IN (" . implode(',', $msgids) . ") ORDER BY id DESC;";
                        $outcomes = $this->dbhr->preQuery($sql, [ $msg['id'] ], FALSE, FALSE);
                    }
                }

                foreach ($outcomes as &$outcome) {
                    if ($outcome['msgid'] == $msg['id']) {
                        # We can only see the details of the outcome if we have access.
                        if (!($seeall || ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) || ($myid && $msg['fromuser'] == $myid))) {
                            $outcome['userid'] = NULL;
                            $outcome['happiness'] = NULL;
                            $outcome['comments'] = NULL;
                        }

                        $outcome['timestamp'] = ISODate($outcome['timestamp']);

                        $rets[$msg['id']]['outcomes'][] = $outcome;
                    }
                }
            }

            if (count($rets[$msg['id']]['outcomes']) === 0) {
                # No outcomes - but has it expired?  Need to check the groups though - it might be reposted later, in
                # which case the time on messages_groups is bumped whereas the message arrival time is the same..
                foreach ($rets[$msg['id']]['groups'] as $group) {
                    $grouparrival = strtotime($group['arrival']);
                    $grouparrivalago = floor((time() - $grouparrival) / 86400);

                    if ($grouparrivalago > presdef('expiretime', $rets[$msg['id']], 0)) {
                        # Assume anything this old is no longer available.
                        $rets[$msg['id']]['outcomes'] = [
                            [
                                'timestamp' => $rets[$msg['id']]['arrival'],
                                'outcome' => Message::OUTCOME_EXPIRED
                            ]
                        ];
                    }
                }
            }

            unset($rets[$msg['id']]['expiretime']);
        }
    }

    public function getPublicFromUser(&$userlist, &$rets, $msgs, $roles, $messagehistory) {
        # Get all the fromusers in a single call - saves on DB ops.
        $u = new User($this->dbhr, $this->dbhm);
        $fromuids = [];
        $groupids = [];

        foreach ($rets as $ret) {
            if (pres('groups', $ret)) {
                foreach ($ret['groups'] as $group) {
                    $groupids[] = $group['groupid'];
                }
            }
        }

        $groupids = array_unique($groupids);

        $fromusers = [];

        foreach ($msgs as $msg) {
            if (pres('fromuser', $msg)) {
                $fromuids[] = $msg['fromuser'];
            }
        }

        $fromuids = array_unique($fromuids);
        $emails = count($fromuids) ? $u->getEmailsById($fromuids) : [];

        if (count($fromuids)) {
            $ctx = NULL;
            $fromusers = $u->getPublicsById($fromuids, $groupids, $messagehistory, FALSE, $ctx, MODTOOLS, MODTOOLS, MODTOOLS, MODTOOLS, FALSE, [ MessageCollection::APPROVED ], FALSE);
            $u->getInfos($fromusers);
        }

        foreach ($msgs as $msg) {
            $role = $roles[$msg['id']][0];

            if (pres('fromuser', $rets[$msg['id']])) {
                # We know who sent this.  We may be able to return this (depending on the role we have for the message
                # and hence the attributes we have already filled in).  We also want to know if we have consent
                # to republish it.
                $rets[$msg['id']]['fromuser'] = $fromusers[$rets[$msg['id']]['fromuser']];

                if ($role == User::ROLE_OWNER || $role == User::ROLE_MODERATOR) {
                    # We can see their emails.
                    $rets[$msg['id']]['fromuser']['emails'] = $emails[$msg['fromuser']];
                } else if (pres('partner', $_SESSION)) {
                    # Partners can see emails which belong to us, for the purposes of replying.
                    $es = $emails[$msg['fromuser']];
                    $rets[$msg['id']]['fromuser']['emails'] = [];
                    foreach ($es as $email) {
                        if (ourDomain($email['email'])) {
                            $rets[$msg['id']]['fromuser']['emails'] = $email;
                        }
                    }
                }

                filterResult($rets[$msg['id']]['fromuser']);
            }
        }
    }

    public function getPublicRelated(&$rets, $msgs) {
        $msgids = array_filter(array_column($msgs, 'id'));
        $relateds = [];

        if (count($msgids)) {
            $relateds = $this->dbhr->preQuery("SELECT * FROM messages_related WHERE id1 IN (" . implode(',', $msgids) . ") OR id2  IN (" . implode(',', $msgids) . ");");
        }

        foreach ($msgs as $msg) {
            # Add any related messages
            $rets[$msg['id']]['related'] = [];
            $relids = [];

            foreach ($relateds as $rel) {
                if ($rel['id1'] == $msg['id'] || $rel['id2'] == $msg['id']) {
                    $id = $rel['id1'] == $msg['id'] ? $rel['id2'] : $rel['id1'];

                    if (!array_key_exists($id, $relids)) {
                        $m = new Message($this->dbhr, $this->dbhm, $id);
                        $rets[$msg['id']]['related'][] = $m->getPublic(FALSE, FALSE);
                        $relids[$id] = TRUE;
                    }
                }
            }
        }
    }

    public function getPublicHeld(&$userlist, &$rets, $msgs, $messagehistory) {
        foreach ($msgs as $msg) {
            if (pres('heldby', $rets[$msg['id']])) {
                $rets[$msg['id']]['heldby'] = $this->getUser($rets[$msg['id']]['heldby'], FALSE, $userlist, FALSE);
                filterResult($rets[$msg['id']]);
            }
        }
    }

    public function getPublicAttachments(&$rets, $msgs, $summary) {
        $msgids = array_filter(array_column($msgs, 'id'));

        $atts = NULL;

        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getByIds($msgids);

        foreach ($msgs as $msg) {
            if ($summary) {
                # Construct a minimal attachment list, i.e. just one if we have it.
                $rets[$msg['id']]['attachments'] = presdef('attachments', $msg, []);
            } else if (!$summary) {
                # Add any attachments - visible to non-members.
                $rets[$msg['id']]['attachments'] = [];
                $atthash = [];

                foreach ($atts as $att) {
                    /** @var $att Attachment */
                    $pub = $att->getPublic();

                    if ($pub['msgid'] == $msg['id']) {
                        # We suppress return of duplicate attachments by using the image hash.  This helps in the case where
                        # the same photo is (for example) included in the mail both as an inline attachment and as a link
                        # in the text.
                        $hash = $att->getHash();
                        if (!$hash || !pres($msg['id'] . '-' . $hash, $atthash)) {
                            $rets[$msg['id']]['attachments'][] = $pub;
                            $atthash[$msg['id'] . '-' . $hash] = TRUE;
                        }
                    }
                }
            }
        }
    }

    public function getPublicPostingHistory(&$rets, $msgs, $me, $myid) {
        $fetch = [];

        foreach ($rets as $ret) {
            if ($myid && pres('fromuser', $ret) && $ret['fromuser']['id'] == $myid) {
                $fetch[] = $ret['id'];
            }
        }

        if (count($fetch)) {
            # For our own messages, return the posting history.
            $posts = $this->dbhr->preQuery("SELECT * FROM messages_postings WHERE msgid IN (" . implode(',', $fetch) . ") ORDER BY date ASC;", NULL, FALSE, FALSE);

            foreach ($rets as &$ret) {
                $ret['postings'] = [];

                foreach ($posts as $post) {
                    if ($post['msgid'] == $ret['id']) {
                        $post['date'] = ISODate($post['date']);
                        $ret['postings'][] = $post;
                    }
                }
            }
        }
    }

    public function getPublicEditHistory(&$userlist, &$rets, $msgs, $me, $myid) {
        $doit = MODTOOLS && $me && $me->isModerator();
        $msgids = array_filter(array_column($msgs, 'id'));

        if (count($msgids)) {
            if ($doit) {
                # Return any edit history, most recent first.
                $edits = $this->dbhr->preQuery("SELECT * FROM messages_edits WHERE msgid IN (" . implode(',', $msgids) . ") ORDER BY id DESC;", [
                    $this->id
                ], FALSE, FALSE);
            }

            # We can't use foreach because then data is copied by reference.
            foreach ($rets as $retind => $ret) {
                $rets[$retind]['edits'] = [];

                if ($doit) {
                    for ($editind = 0; $editind < count($edits); $editind++) {
                        if ($rets[$retind]['id'] == $edits[$editind]['msgid']) {
                            $thisedit = $edits[$editind]; 
                            $thisedit['timestamp'] = ISODate($thisedit['timestamp']);

                            if (pres('byuser', $thisedit)) {
                                $u = User::get($this->dbhr, $this->dbhm, $thisedit['byuser']);
                                $ctx = NULL;
                                $thisedit['byuser'] = $u->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
                            }
                            
                            $rets[$retind]['edits'][] = $thisedit;
                        }
                    }

                    if (count($rets[$retind]['edits']) === 0) {
                        $rets[$retind]['edits'] = NULL;
                    }
                }
            }
        }
    }

    public function getWorry(&$msgs) {
       if (MODTOOLS) {
           # We check the messages again.  This means if something is added to worry words while our message is in
           # pending, we'll see it.
           $w = new WorryWords($this->dbhr, $this->dbhm);

           foreach ($msgs as $msgind => $msg) {
               $msgs[$msgind]['worry'] = $w->checkMessage($msg['id'], pres('fromuser', $msg) ? $msg['fromuser']['id'] : NULL, $msg['subject'], $msg['textbody'], FALSE);
           }
       }
    }

    /**
     * @param bool $messagehistory
     * @param bool $related
     * @param bool $seeall
     * @param null $userlist is a way to cache users over multiple calls
     * @param null $locationlist is a way to cache users over multiple calls
     * @param bool $summary
     * @return array
     */
    public function getPublic($messagehistory = TRUE, $related = TRUE, $seeall = FALSE, &$userlist = NULL, &$locationlist = [], $summary = FALSE) {
        $msgs = $this->getThisAsArray();
        $rets = $this->getPublics($msgs, $messagehistory, $related, $seeall, $userlist, $locationlist, $summary);
        $ret = $rets[$this->id];
        return($ret);
    }

    public function getPublics($msgs, $messagehistory = TRUE, $related = TRUE, $seeall = FALSE, &$userlist = NULL, &$locationlist = [], $summary = FALSE) {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        # We call the methods that handle an array of messages, which are shared with MessageCollection.  Each of
        # these return their info in an array indexed by message id.
        $roles = $this->getRolesForMessages($me, $msgs);
        $rets = $this->getPublicAtts($me, $myid, $msgs, $roles, $seeall, $summary);
        $this->getPublicGroups($me, $myid, $userlist, $rets, $msgs, $roles, $summary, $seeall);
        $this->getPublicReplies($me, $myid, $rets, $msgs, $summary, $roles, $seeall, FALSE);
        $this->getPublicOutcomes($me, $myid, $rets, $msgs, $summary, $roles, $seeall);
        $this->getPublicAttachments($rets, $msgs, $summary);

        if (!$summary) {
            $this->getPublicLocation($me, $myid, $rets, $msgs, $roles, $seeall, $locationlist);
            $this->getPublicItem($rets, $msgs);
            $this->getPublicFromUser($userlist, $rets, $msgs, $roles, $messagehistory);
            $this->getPublicHeld($userlist, $rets, $msgs, $messagehistory);
            $this->getPublicPostingHistory($rets, $msgs, $me, $myid);
            $this->getPublicEditHistory($userlist, $rets, $msgs, $me, $myid);
            $this->getWorry($rets);

            if ($related) {
                $this->getPublicRelated($rets, $msgs);
            }
        }

        return($rets);
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getFromIP()
    {
        return $this->fromip;
    }

    /**
     * @return mixed
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @return mixed
     */
    public function getSourceheader()
    {
        return $this->sourceheader;
    }

    /**
     * @param mixed $fromip
     */
    public function setFromIP($fromip)
    {
        $name = NULL;

        if ($fromip) {
            # If the call returns a hostname which is the same as the IP, then it's
            # not resolvable.
            $name = gethostbyaddr($fromip);
            $name = ($name == $fromip) ? NULL : $name;

            if ($name) {
                $this->fromhost = $name;
            }
        }

        if ($fromip) {
            $this->fromip = $fromip;
            $this->dbhm->preExec("UPDATE messages SET fromip = ? WHERE id = ? AND fromip IS NULL;",
                [$fromip, $this->id]);
            $this->dbhm->preExec("UPDATE messages_history SET fromip = ?, fromhost = ? WHERE msgid = ? AND fromip IS NULL;",
                [$fromip, $name, $this->id]);
        }
    }

    /**
     * @return mixed
     */
    public function getFromhost()
    {
        if (!$this->fromhost && $this->fromip) {
            # If the call returns a hostname which is the same as the IP, then it's
            # not resolvable.
            $name = gethostbyaddr($this->fromip);
            $name = ($name == $this->fromip) ? NULL : $name;
            $this->fromhost = $name;
        }

        return $this->fromhost;
    }

    const EMAIL = 'Email';
    const YAHOO_APPROVED = 'Yahoo Approved';
    const YAHOO_PENDING = 'Yahoo Pending';
    const YAHOO_SYSTEM = 'Yahoo System';
    const PLATFORM = 'Platform'; // Us

    /**
     * @return null
     */
    public function getID()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getMessageID()
    {
        return $this->messageid;
    }

    /**
     * @return mixed
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return mixed
     */
    public function getTnpostid()
    {
        return $this->tnpostid;
    }

    /**
     * @return mixed
     */
    public function getEnvelopefrom()
    {
        return $this->envelopefrom;
    }

    /**
     * @return mixed
     */
    public function getEnvelopeto()
    {
        return $this->envelopeto;
    }

    /**
     * @return mixed
     */
    public function getFromname()
    {
        return $this->fromname;
    }

    /**
     * @return mixed
     */
    public function getFromaddr()
    {
        return $this->fromaddr;
    }

    /**
     * @return mixed
     */
    public function getTextbody()
    {
        return $this->textbody;
    }

    /**
     * @return mixed
     */
    public function getHtmlbody()
    {
        return $this->htmlbody;
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * @return array
     */
    public function getInlineimgs()
    {
        return $this->inlineimgs;
    }

    /**
     * @return PhpMimeMailParser\Attachment[]
     */
    public function getParsedAttachments()
    {
        return $this->attachments;
    }

    # Get attachments which have been saved
    public function getAttachments() {
        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getById($this->getID());
        return($atts);
    }

    private static function keywords() {
        # We try various mis-spellings, and Welsh.  This is not to suggest that Welsh is a spelling error.
        return([
            Message::TYPE_OFFER => [
                'ofer', 'offr', 'offrer', 'ffered', 'offfered', 'offrered', 'offered', 'offeer', 'cynnig', 'offred',
                'offer', 'offering', 'reoffer', 're offer', 're-offer', 'reoffered', 're offered', 're-offered',
                'offfer', 'offeed', 'available'],
            Message::TYPE_TAKEN => ['collected', 'take', 'stc', 'gone', 'withdrawn', 'ta ke n', 'promised',
                'cymeryd', 'cymerwyd', 'takln', 'taken', 'cymryd'],
            Message::TYPE_WANTED => ['wnted', 'requested', 'rquested', 'request', 'would like', 'want',
                'anted', 'wated', 'need', 'needed', 'wamted', 'require', 'required', 'watnted', 'wented',
                'sought', 'seeking', 'eisiau', 'wedi eisiau', 'eisiau', 'wnated', 'wanted', 'looking', 'waned'],
            Message::TYPE_RECEIVED => ['recieved', 'reiceved', 'receved', 'rcd', 'rec\'d', 'recevied',
                'receive', 'derbynewid', 'derbyniwyd', 'received', 'recivered'],
            Message::TYPE_ADMIN => ['admin', 'sn']
        ]);
    }

    public static function determineType($subj) {
        $type = Message::TYPE_OTHER;
        $pos = PHP_INT_MAX;

        foreach (Message::keywords() as $keyword => $vals) {
            foreach ($vals as $val) {
                if (preg_match('/\b(' . preg_quote($val) . ')\b/i', $subj, $matches, PREG_OFFSET_CAPTURE)) {
                    if ($matches[1][1] < $pos) {
                        # We want the match earliest in the string - Offerton etc.
                        $type = $keyword;
                        $pos = $matches[1][1];
                    }
                }
            }
        }

        return($type);
    }

    public function getPrunedSubject() {
        $subj = $this->getSubject();

        if (preg_match('/(.*)\(.*\)/', $subj, $matches)) {
            # Strip possible location - useful for reuse groups
            $subj = $matches[1];
        }
        if (preg_match('/\[.*\](.*)/', $subj, $matches)) {
            # Strip possible group name
            $subj = $matches[1];
        }

        $subj = trim($subj);

        # Remove any odd characters.
        $subj = quoted_printable_encode($subj);

        return($subj);
    }

    public function createDraft() {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;
        $sess = session_id();

        $rc = $this->dbhm->preExec("INSERT INTO messages (source, sourceheader, date, fromip, message) VALUES(?,?, NOW(), ?, '');", [
            Message::PLATFORM,
            Message::PLATFORM,
            presdef('REMOTE_ADDR', $_SERVER, NULL)
        ]);
        $id = $rc ? $this->dbhm->lastInsertId() : NULL;

        if ($id) {
            $rc = $this->dbhm->preExec("INSERT INTO messages_drafts (msgid, userid, session) VALUES (?, ?, ?);", [ $id, $myid, $sess ]);
            $id = $rc ? $id : NULL;
        }

        #error_log("Created draft $id");
        return($id);
    }

    private function removeAttachDir() {
        # Be careful what we delete.
        if ($this->attach_dir && strpos($this->attach_dir, sys_get_temp_dir()) !== FALSE) {
            foreach ($this->attachments as $att) {
                /** @var \PhpMimeMailParser\Attachment $att */
                $fn = $this->attach_dir . DIRECTORY_SEPARATOR . $att->getFilename();
                @unlink($fn);
            }

            rmdir($this->attach_dir);
        }

        $this->attach_dir = NULL;
    }
    
    # Parse a raw SMTP message.
    public function parse($source, $envelopefrom, $envelopeto, $msg, $groupid = NULL)
    {
        $this->message = $msg;
        $this->groupid = $groupid;
        $this->source = $source;

        $Parser = new PhpMimeMailParser\Parser();
        $this->parser = $Parser;
        $Parser->setText($msg);

        # We save the attachments to a temp directory.  This is tidied up on destruction or save.
        $this->attach_dir = tmpdir();
        try {
            $this->attach_files = $Parser->saveAttachments($this->attach_dir . DIRECTORY_SEPARATOR);
            $this->attachments = $Parser->getAttachments();
        } catch (Exception $e) {
            # We've seen this error when some of the attachments have weird non-relative filenames, which may be
            # a hack attempt.
            error_log("Parse of attachments failed " . $e->getMessage());
            $this->attachments = [];
        }

        $this->yahooapprove = NULL;
        $this->yahooreject = NULL;

        if ($source == Message::YAHOO_PENDING) {
            # This is an APPROVE mail; we need to extract the included copy of the original message.
            $this->yahooapprove = $Parser->getHeader('reply-to');
            if (preg_match('/^(.*-reject-.*yahoogroups.*?)($| |=)/im', $msg, $matches)) {
                $this->yahooreject = trim($matches[1]);
            }

            $atts = $this->getParsedAttachments();
            if (count($atts) >= 1 && $atts[0]->getContentType() == 'message/rfc822') {
                $attachedmsg = $atts[0]->getContent();

                # Remove the old attachments as we're overwriting them.
                $this->removeAttachDir();

                $Parser->setText($attachedmsg);
                $this->attach_files = $Parser->saveAttachments($this->attach_dir);
                $this->attachments = $Parser->getAttachments();
            }
        }

        # Get IP
        $ip = $this->getHeader('x-freegle-ip');
        $ip = $ip ? $ip : $this->getHeader('x-trash-nothing-user-ip');
        $ip = $ip ? $ip : $this->getHeader('x-yahoo-post-ip');
        $ip = $ip ? $ip : $this->getHeader('x-originating-ip');
        $ip = preg_replace('/[\[\]]/', '', $ip);
        $this->fromip = $ip;

        # See if we can find a group this is intended for.  Can't trust the To header, as the client adds it,
        # and we might also be CC'd or BCC'd.
        $groupname = NULL;
        $to = $this->getApparentlyTo();

        if (count($to) == 0) {
            # ...but if we can't find it, it'll do.
            $to = $this->getTo();
        }

        $rejected = $this->getHeader('x-egroups-rejected-by');

        if (!$rejected) {
            # Rejected messages can look a bit like messages to the group, but they're not.
            $togroup = FALSE;
            $toours = FALSE;

            foreach ($to as $t) {
                # Check it's to a group (and not the owner).
                #
                # Yahoo members can do a reply to all which results in a message going to both one of our users
                # and the group, so in that case we want to ignore the group aspect.
                if (ourDomain($t['address'])) {
                    $toours = TRUE;
                }

                if (preg_match('/(.*)@yahoogroups\.co.*/', $t['address'], $matches) &&
                    strpos($t['address'], '-owner@') === FALSE) {
                    # Yahoo group.
                    $groupname = $matches[1];
                    $togroup = TRUE;
                    #error_log("Got $groupname from {$t['address']}");
                } else if (preg_match('/(.*)@' . GROUP_DOMAIN . '/', $t['address'], $matches) &&
                    strpos($t['address'], '-volunteers@') === FALSE &&
                    strpos($t['address'], '-auto@') === FALSE) {
                    # Native group.
                    $groupname = $matches[1];
                    $togroup = TRUE;
                    #error_log("Got $groupname from {$t['address']}");
                }
            }

            if ($toours && $togroup) {
                # Drop the group aspect.
                $groupname = NULL;
            }
        }

        if ($groupname) {
            if (!$this->groupid) {
                # Check if it's a group we host.
                $g = Group::get($this->dbhr, $this->dbhm);
                $this->groupid = $g->findByShortName($groupname);
            }
        }

        if (($source == Message::YAHOO_PENDING || $source == Message::YAHOO_APPROVED) && !$this->groupid) {
            # This is a message from Yahoo, but not for a group we host.  We don't want it.
            $this->removeAttachDir();
            return (FALSE);
        }
        
        $this->envelopefrom = $envelopefrom;
        $this->envelopeto = $envelopeto;
        $this->yahoopendingid = NULL;
        $this->yahooapprovedid = NULL;

        # Get Yahoo pending message id
        if (preg_match('/pending\?view=1&msg=(\d*)/im', $msg, $matches)) {
            $this->yahoopendingid = $matches[1];
        }

        # Get Yahoo approved message id
        $newmanid = $Parser->getHeader('x-yahoo-newman-id');
        if ($newmanid && preg_match('/.*\-m(\d*)/', $newmanid, $matches)) {
            $this->yahooapprovedid = $matches[1];
        }

        # Yahoo posts messages from the group address, but with a header showing the
        # original from address.
        $originalfrom = $Parser->getHeader('x-original-from');

        if ($originalfrom) {
            $from = mailparse_rfc822_parse_addresses($originalfrom);
        } else {
            $from = mailparse_rfc822_parse_addresses($Parser->getHeader('from'));
        }

        $this->fromname = count($from) > 0 ? $from[0]['display'] : NULL;
        $this->fromaddr = count($from) > 0 ? $from[0]['address'] : NULL;

        if (!$this->fromaddr) {
            # We have failed to parse out this message.
            $this->removeAttachDir();
            return (FALSE);
        }

        $this->date = gmdate("Y-m-d H:i:s", strtotime($Parser->getHeader('date')));

        $this->sourceheader = $Parser->getHeader('x-freegle-source');
        $this->sourceheader = ($this->sourceheader == 'Unknown' ? NULL : $this->sourceheader);

        # Store Reply-To only if different from fromaddr.
        $rh = $this->getReplyTo();
        $rh = $rh ? $rh[0]['address'] : NULL;
        $this->replyto = ($rh && strtolower($rh) != strtolower($this->fromaddr)) ? $rh : NULL;

        if (!$this->sourceheader) {
            $this->sourceheader = $Parser->getHeader('x-trash-nothing-source');
            if ($this->sourceheader) {
                $this->sourceheader = "TN-" . $this->sourceheader;
            }
        }

        if (!$this->sourceheader && $Parser->getHeader('x-mailer') == 'Yahoo Groups Message Poster') {
            $this->sourceheader = 'Yahoo-Web';
        }

        if (!$this->sourceheader && (strpos($Parser->getHeader('x-mailer'), 'Freegle Message Maker') !== FALSE)) {
            $this->sourceheader = 'MessageMaker';
        }

        if (!$this->sourceheader) {
            if (ourDomain($this->fromaddr)) {
                $this->sourceheader = 'Platform';
            } else {
                $this->sourceheader = 'Yahoo-Email';
            }
        }

        $this->subject = $Parser->getHeader('subject');
        $this->messageid = $Parser->getHeader('message-id');
        $this->messageid = str_replace('<', '', $this->messageid);
        $this->messageid = str_replace('>', '', $this->messageid);
        $this->tnpostid = $Parser->getHeader('x-trash-nothing-post-id');

        $this->textbody = $Parser->getMessageBody('text');
        $this->htmlbody = $Parser->getMessageBody('html');

        if ($this->htmlbody) {
            # The HTML body might contain images as img tags, rather than actual attachments.  Extract these too.
            $doc = new DOMDocument();
            @$doc->loadHTML($this->htmlbody);
            $imgs = $doc->getElementsByTagName('img');

            /* @var DOMNodeList $imgs */
            foreach ($imgs as $img) {
                $src = $img->getAttribute('src');

                # We only want to get images from http or https to avoid the security risk of fetching a local file.
                #
                # Wait for 120 seconds to fetch.  We don't want to wait forever, but we see occasional timeouts from Yahoo
                # at 60 seconds.
                #
                # We don't want Yahoo's megaphone images - they're just generic footer images.  Likewise Avast.  And
                # not any from our own domain - they might be ads, or item images in chat notifications.
                if ((stripos($src, 'http://') === 0 || stripos($src, 'https://') === 0) &&
                    (stripos($src, 'https://s.yimg.com/ru/static/images/yg/img/megaphone') === FALSE) &&
                    (stripos($src, 'ilovefreegle.org') === FALSE) &&
                    (stripos($src, 'https://ipmcdn.avast.com') === FALSE)) {
                    #error_log("Get inline image $src");
                    $ctx = stream_context_create(array('http' =>
                        array(
                            'timeout' => 120
                        )
                    ));

                    $data = @file_get_contents($src, false, $ctx);

                    if ($data) {
                        # Try to convert to an image.  If it's not an image, this will fail.
                        $img = new Image($data);

                        if ($img->img) {
                            $newdata = $img->getData(100);

                            # Ignore small images - Yahoo adds small ones as (presumably) a tracking mechanism, and also their
                            # logo.
                            if ($newdata && $img->width() > 50 && $img->height() > 50) {
                                $this->inlineimgs[] = $newdata;
                            }
                        }
                    }
                }
            }
        }

        $this->textbody = $this->stripSigs($this->textbody);

        # Trash Nothing sends attachments too, but just as links - get those.
        #
        # - links to flic.kr, for groups which for some reason don't like images hosted on TN
        # - links to TN itself
        if (preg_match_all('/(http:\/\/flic\.kr.*)$/m', $this->textbody, $matches)) {
            $urls = [];
            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $urls[] = $url;
                }
            }

            $urls = array_unique($urls);
            foreach ($urls as $url) {
                $ctx = stream_context_create(array('http' =>
                    array(
                        'timeout' => 120
                    )
                ));

                $data = @file_get_contents($url, false, $ctx);

                if ($data) {
                    # Now get the link to the actual image.  DOMDocument chokes on the HTML so do it the dirty way.
                    if (preg_match('#<meta property="og:image" content="(.*)"  data-dynamic="true">#', $data, $matches)) {
                        $imgurl = $matches[1];
                        $ctx = stream_context_create(array('http' =>
                            array(
                                'timeout' => 120
                            )
                        ));

                        $data = @file_get_contents($imgurl, false, $ctx);

                        if ($data) {
                            # Try to convert to an image.  If it's not an image, this will fail.
                            $img = new Image($data);

                            if ($img->img) {
                                $newdata = $img->getData(100);

                                if ($newdata && $img->width() > 50 && $img->height() > 50) {
                                    $this->inlineimgs[] = $newdata;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (preg_match_all('/(https:\/\/trashnothing\.com\/pics\/.*)$/m', $this->textbody, $matches)) {
            $urls = [];
            foreach ($matches as $val) {
                foreach ($val as $url) {
                    $urls[] = $url;
                }
            }

            $urls = array_unique($urls);
            foreach ($urls as $url) {
                $ctx = stream_context_create(array('http' =>
                    array(
                        'timeout' => 120
                    )
                ));

                $data = @file_get_contents($url, false, $ctx);

                if ($data) {
                    # Now get the link to the actual images.
                    $doc = new DOMDocument();
                    @$doc->loadHTML($data);
                    $imgs = $doc->getElementsByTagName('img');

                    /* @var DOMNodeList $imgs */
                    foreach ($imgs as $img) {
                        $src = $img->getAttribute('src');

                        if (strpos($src, '/img/') !== FALSE || strpos($src, '/tn-photos/')) {
                            $ctx = stream_context_create(array('http' =>
                                array(
                                    'timeout' => 120
                                )
                            ));

                            $data = @file_get_contents($src, false, $ctx);

                            if ($data) {
                                error_log("Got it");
                                # Try to convert to an image.  If it's not an image, this will fail.
                                $img = new Image($data);

                                if ($img->img) {
                                    $newdata = $img->getData(100);

                                    if ($newdata && $img->width() > 50 && $img->height() > 50) {
                                        $this->inlineimgs[] = $newdata;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        # If this is a reuse group, we need to determine the type.
        $g = Group::get($this->dbhr, $this->dbhm, $this->groupid);
        if ($g->getPrivate('type') == Group::GROUP_FREEGLE ||
            $g->getPrivate('type') == Group::GROUP_REUSE
        ) {
            $this->type = $this->determineType($this->subject);
        } else {
            $this->type = Message::TYPE_OTHER;
        }

        if ($source == Message::YAHOO_PENDING || $source == Message::YAHOO_APPROVED  || $source == Message::EMAIL) {
            # Make sure we have a user for the sender.
            $u = User::get($this->dbhr, $this->dbhm);

            # If there is a Yahoo uid in here - which there isn't always - we might be able to find them that way.
            #
            # This is important as well as checking the email address as users can send from the owner address (which
            # we do not allow to be attached to a specific user, as it can be shared by many).
            $iznikid = NULL;
            $userid = NULL;
            $yahoouid = NULL;
            $emailid = NULL;
            $this->modmail = FALSE;

            $iznikid = $Parser->getHeader('x-iznik-from-user');
            if ($iznikid) {
                # We know who claims to have sent this.  There's a slight exploit here where someone could spoof
                # the modmail setting and get a more prominent display.  I may regret writing this comment.
                $userid = $iznikid;
                $this->modmail = filter_var($Parser->getHeader('x-iznik-modmail'), FILTER_VALIDATE_BOOLEAN);
            }

            if (!$userid) {
                # They might have posted from Yahoo.
                $gp = $Parser->getHeader('x-yahoo-group-post');
                if ($gp && preg_match('/u=(.*);/', $gp, $matches)) {
                    $yahoouid = $matches[1];
                    $userid = $u->findByYahooUserId($yahoouid);
                }
            }

            if (!$userid) {
                # Or we might have their email.
                $userid = $u->findByEmail($this->fromaddr);
            }

            if (!$userid) {
                # We don't know them.  Add.
                #
                # We don't have a first and last name, so use what we have. If the friendly name is set to an
                # email address, take the first part.
                $name = $this->fromname;
                if (preg_match('/(.*)@/', $name, $matches)) {
                    $name = $matches[1];
                }

                $userid = $u->create(NULL, NULL, $name, "Incoming message #{$this->id} from {$this->fromaddr} on $groupname");

                # Use the m handle to make sure we find it later.
                $this->dbhr = $this->dbhm;
            }

            if ($userid) {
                # We have a user.
                $u = User::get($this->dbhm, $this->dbhm, $userid);

                # We might not have this yahoo user id associated with this user.
                if ($yahoouid) {
                    $u->setPrivate('yahooUserId', $yahoouid);
                }

                # We might not have this email associated with this user.
                $emailid = $u->addEmail($this->fromaddr, 0, FALSE);

                if ($emailid && ($source == Message::YAHOO_PENDING || $source == Message::YAHOO_APPROVED)) {
                    # Make sure we have a membership for the originator of this message; they were a member
                    # at the time they sent this.  If they have since left we'll pick that up later via a sync.
                    if (!$u->isApprovedMember($this->groupid)) {
                        $u->addMembership($this->groupid, User::ROLE_MEMBER, $emailid, MembershipCollection::APPROVED, NULL, NULL, FALSE);
                    }
                }
            }

            $this->fromuser = $userid;
        }

        return(TRUE);
    }

    public function pruneMessage() {
        # We are only interested in image attachments; those are what we hive off into the attachments table,
        # and what we display.  They bulk up the message source considerably, which chews up disk space.  Worse,
        # we might have message attachments which are not even image attachments, just for messages we are
        # moderating on groups.
        #
        # So we remove all attachment data within the message.  We do this with a handrolled lame parser, as we
        # don't have a full MIME reassembler.
        $current = $this->message;
        #error_log("Start prune len " . strlen($current));

        # Might have wrong LF format.
        $current = preg_replace('~\R~u', "\r\n", $current);
        $p = 0;

        do {
            $found = FALSE;
            $p = stripos($current, 'Content-Type:', $p);
            #error_log("Found content type at $p");

            if ($p) {
                $crpos = strpos($current, "\r\n", $p);
                $ct = strtolower(substr($current, $p, $crpos - $p));
                #error_log($ct);

                $found = TRUE;

                # We don't want to prune a multipart, only the bottom level parts.
                if (stripos($ct, "multipart") === FALSE) {
                    #error_log("Prune it");
                    # Find the boundary before it.
                    $boundpos = strrpos(substr($current, 0, $p), "\r\n--");

                    if ($boundpos) {
                        #error_log("Found bound");
                        $crpos = strpos($current, "\r\n", $boundpos + 2);
                        $boundary = substr($current, $boundpos + 2, $crpos - ($boundpos + 2));

                        # Find the end of the bodypart headers.
                        $breakpos = strpos($current, "\r\n\r\n", $boundpos);

                        # Find the end of the bodypart.
                        $nextboundpos = strpos($current, $boundary, $breakpos);

                        # Always prune image and HTML bodyparts - images are stored off as attachments, and HTML
                        # bodyparts are quite long and typically there's also a text one present.  Ideally we might
                        # keep HTML, but we need to control our disk space usage.
                        #
                        # For other bodyparts keep a max of 10K. Observant readers may wish to comment on this
                        # definition of K.
                        #error_log("$ct breakpos $breakpos nextboundpos $nextboundpos size " . ($nextboundpos - $breakpos) . " strpos " . strpos($ct, 'image/') . ", " . strpos($ct, 'text/html'));
                        if ($breakpos && $nextboundpos &&
                            (($nextboundpos - $breakpos > 10000) ||
                                (strpos($ct, 'image/') !== FALSE) ||
                                (strpos($ct, 'text/html') !== FALSE))) {
                            # Strip out the bodypart data and replace it with some short text.
                            $current = substr($current, 0, $breakpos + 2) .
                                "\r\n...Content of size " . ($nextboundpos - $breakpos + 2) . " removed...\r\n\r\n" .
                                substr($current, $nextboundpos);
                            #error_log($this->id . " Content of size " . ($nextboundpos - $breakpos + 2) . " removed...");
                        }
                    }
                }
            }

            $p++;
        } while ($found);

        #error_log("End prune len " . strlen($current));

        # Something went horribly wrong?
        # TODO Test.
        $current = (strlen($current) == 0) ? $this->message : $current;

        return($current);
    }

    private function saveAttachments($msgid) {
        if ($this->type != Message::TYPE_TAKEN && $this->type != Message::TYPE_RECEIVED) {
            # Don't want attachments for TAKEN/RECEIVED.  They can occur if people forward the original message.
            #
            # If we crash or fail at this point, we would have mislaid an attachment for a message.  That's not great, but the
            # perf cost of a transaction for incoming messages is significant, and we can live with it.
            $a = new Attachment($this->dbhr, $this->dbhm);

            foreach ($this->attachments as $att) {
                /** @var \PhpMimeMailParser\Attachment $att */
                $ct = $att->getContentType();
                $fn = $this->attach_dir . DIRECTORY_SEPARATOR . $att->getFilename();

                # Can't use LOAD_FILE as server may be remote.
                $data = file_get_contents($fn);

                # Scale the image if it's large.  Ideally we'd store the full size image, but images can be many meg, and
                # it chews up disk space.
                $i = new Image($data);
                if ($i->img) {
                    $ow = $w = $i->width();
                    $oh = $h = $i->height();

                    if (strlen($data) > 300000) {
                        $w = min(1024, $w);
                        $i->scale($w, NULL);
                        $data = $i->getData();
                        $ct = 'image/jpeg';
                    }

                    if ($ow && $oh) {
                        $r = $ow / $oh;

                        # We want to remove images which are likely to be signature images.
                        #
                        # Camera use aspect ratios like 4:3, 3:2, 16:9.  If it's too far from that, then it's
                        # probably not a photo, more likely to be an image signature, if it's small.
                        #
                        # We also only want images which are a decent size; otherwise more likely to just be
                        # logos and suchlike.
                        if ($ow > 150 && $oh > 150 && ($ow > 600 || $oh > 600 || ($r >= 0.5 && $r <= 1.5))) {
                            $a->create($msgid, $ct, $data);
                        }
                    }
                }
            }

            foreach ($this->inlineimgs as $att) {
                $a->create($msgid, 'image/jpeg', $att);
            }
        }
    }

    # Save a parsed message to the DB
    public function save($log = TRUE) {
        # Despite what the RFCs might say, it's possible that a message can appear on Yahoo without a Message-ID.  We
        # require unique message ids, so this causes us a problem.  Invent one.
        $this->messageid = $this->messageid ? $this->messageid : (microtime(TRUE). '@' . USER_DOMAIN);

        # We now manipulate the message id a bit.  This is because although in future we will support the same message
        # appearing on multiple groups, and therefore have a unique key on message id, we've not yet tested this.  IT
        # will probably require client changes, and there are issues about what to do when a message is sent to two
        # groups and edited differently on both.  Meanwhile we need to be able to handle messages which are sent to
        # multiple groups, which would otherwise overwrite each other.
        #
        # There is code that does this on message submission too, so that when the message comes back we recognise it.
        $this->messageid = $this->groupid ? ($this->messageid . "-" . $this->groupid) : $this->messageid;

        # See if we have a record of approval from Yahoo.
        $approvedby = NULL;
        $approval = $this->getHeader('x-egroups-approved-by');

        if ($approval) {
            if (preg_match('/(.*?) /', $approval, $matches)) {
                $yid = $matches[1];
                $u = new User($this->dbhr, $this->dbhm);
                $approvedby = $u->findByYahooId($yid);
            }
        }

        # Reduce the size of the message source
        $this->message = $this->pruneMessage();

        $oldid = NULL;

        # A message we are saving as approved may previously have been in the system:
        # - a message we receive as approved will usually have been here as pending
        # - a message we receive as pending may have been here as approved if it was approved elsewhere before it
        #   reached is.
        $already = FALSE;
        $this->id = NULL;
        $oldid = $this->checkEarlierCopies($approvedby);

        if ($oldid) {
            # Existing message.
            $this->id = $oldid;

            # We might not have matched the related messages earlier, if things arrived in a strange order.
            $this->recordRelated();

            $already = TRUE;
        } else {
            # New message.  Trigger mapping and get subject suggestion.
            $this->suggestedsubject = $this->suggestSubject($this->groupid, $this->subject);

            # Save into the messages table.
            $sql = "INSERT INTO messages (date, source, sourceheader, message, fromuser, envelopefrom, envelopeto, fromname, fromaddr, replyto, fromip, subject, suggestedsubject, messageid, tnpostid, textbody, htmlbody, type, lat, lng, locationid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?);";
            $rc = $this->dbhm->preExec($sql, [
                $this->date,
                $this->source,
                $this->sourceheader,
                $this->message,
                $this->fromuser,
                $this->envelopefrom,
                $this->envelopeto,
                $this->fromname,
                $this->fromaddr,
                $this->replyto,
                $this->fromip,
                $this->subject,
                $this->suggestedsubject,
                $this->messageid,
                $this->tnpostid,
                $this->textbody,
                $this->htmlbody,
                $this->type,
                $this->lat,
                $this->lng,
                $this->locationid
            ]);

            if ($rc) {
                $this->id = $this->dbhm->lastInsertId();
                $this->saveAttachments($this->id);

                if ($log) {
                    $l = new Log($this->dbhr, $this->dbhm);
                    $l->log([
                        'type' => Log::TYPE_MESSAGE,
                        'subtype' => Log::SUBTYPE_RECEIVED,
                        'msgid' => $this->id,
                        'user' => $this->fromuser,
                        'text' => $this->messageid,
                        'groupid' => $this->groupid
                    ]);
                }

                # Now that we have a ID, record which messages are related to this one.
                $this->recordRelated();

                if ($this->groupid) {
                    # Save the group we're on.  If we crash or fail at this point we leave the message stranded, which is ok
                    # given the perf cost of a transaction.
                    $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, msgtype, yahoopendingid, yahooapprovedid, yahooreject, yahooapprove, collection, approvedby,arrival) VALUES (?,?,?,?,?,?,?,?,?,NOW());", [
                        $this->id,
                        $this->groupid,
                        $this->type,
                        $this->yahoopendingid,
                        $this->yahooapprovedid,
                        $this->yahooreject,
                        $this->yahooapprove,
                        MessageCollection::INCOMING,
                        $approvedby
                    ]);
                }

                # Also save into the history table, for spam checking.
                $this->addToMessageHistory();
            }
        }

        # Attachments now safely stored in the DB
        $this->removeAttachDir();

        return([ $this->id, $already ]);
    }

    public function addToMessageHistory() {
        $sql = "INSERT IGNORE INTO messages_history (groupid, source, fromuser, envelopefrom, envelopeto, fromname, fromaddr, fromip, subject, prunedsubject, messageid, msgid) VALUES(?,?,?,?,?,?,?,?,?,?,?,?);";
        $this->dbhm->preExec($sql, [
            $this->groupid,
            $this->source,
            $this->fromuser,
            $this->envelopefrom,
            $this->envelopeto,
            $this->fromname,
            $this->fromaddr,
            $this->fromip,
            $this->subject,
            $this->getPrunedSubject(),
            $this->messageid,
            $this->id
        ]);
    }

    function recordFailure($reason) {
        $this->dbhm->preExec("UPDATE messages SET retrycount = LAST_INSERT_ID(retrycount),
          retrylastfailure = NOW() WHERE id = ?;", [$this->id]);
        $count = $this->dbhm->lastInsertId();

        $l = new Log($this->dbhr, $this->dbhm);
        $l->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_FAILURE,
            'msgid' => $this->id,
            'text' => $reason
        ]);

        return($count);
    }

    public function getHeader($hdr) {
        return($this->parser->getHeader($hdr));
    }

    public function getTo() {
        return(mailparse_rfc822_parse_addresses($this->parser->getHeader('to')));
    }

    public function getApparentlyTo() {
        return(mailparse_rfc822_parse_addresses($this->parser->getHeader('x-apparently-to')));
    }

    public function getReplyTo() {
        $rt = mailparse_rfc822_parse_addresses($this->parser->getHeader('reply-to'));

        # Yahoo can save off the original Reply-To header field.
        $rt = $rt ? $rt : mailparse_rfc822_parse_addresses($this->parser->getHeader('x-original-reply-to'));
        return($rt);
    }

    public function getGroups($includedeleted = FALSE, $justids = TRUE) {
        if ($justids === FALSE || count($this->groups) === 0) {
            # Need to query for them.
            $ret = [];
            $delq = $includedeleted ? "" : " AND deleted = 0";
            $sql = "SELECT " . ($justids ? 'groupid' : '*') . " FROM messages_groups WHERE msgid = ? $delq;";
            $groups = $this->dbhr->preQuery($sql, [ $this->id ]);
            foreach ($groups as $group) {
                $ret[] = $justids ? $group['groupid'] : $group;
            }

            if (!$justids) {
                # Save groups for next time
                $this->groups = $groups;
            }
        } else {
            # We have the groups in hand.
            if ($justids) {
                $ret = array_filter(array_column($this->groups, 'groupid'));
            } else {
                $ret = $this->groups;
            }
        }

        return($ret);
    }

    public function isChatByEmail() {
        $refs = $this->dbhr->preQuery("SELECT * FROM chat_messages_byemail WHERE msgid = ?;", [ $this->id ]);
        return(count($refs) > 0);
    }

    public function isPending($groupid) {
        $sql = "SELECT msgid FROM messages_groups WHERE msgid = ? AND groupid = ? AND collection = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid,
            MessageCollection::PENDING
        ]);

        return(count($groups) > 0);
    }

    public function isApproved($groupid = NULL) {
        $groupq = $groupid ? " AND groupid = $groupid ": "";
        $sql = "SELECT msgid FROM messages_groups WHERE msgid = ? $groupq AND collection = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [
            $this->id,
            MessageCollection::APPROVED
        ]);

        return(count($groups) > 0);
    }

    private function maybeMail($groupid, $subject, $body, $action) {
        if ($subject) {
            # We have a mail to send.
            $me = whoAmI($this->dbhr, $this->dbhm);
            $myid = $me->getId();

            $to = $this->getEnvelopefrom();
            $to = $to ? $to : $this->getFromaddr();

            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $atts = $g->getPublic();

            # Find who to send it from.  If we have a config to use for this group then it will tell us.
            $name = $me->getName();
            $c = new ModConfig($this->dbhr, $this->dbhm);
            $cid = $c->getForGroup($me->getId(), $groupid);
            $c = new ModConfig($this->dbhr, $this->dbhm, $cid);
            $fromname = $c->getPrivate('fromname');

            $bcc = $c->getBcc($action);

            if ($bcc) {
                $bcc = str_replace('$groupname', $atts['nameshort'], $bcc);
            }

            if ($fromname == 'Groupname Moderator') {
                $name = '$groupname Moderator';
            }

            # We can do a simple substitution in the from name.
            $name = str_replace('$groupname', $atts['namedisplay'], $name);

            # We add the message into chat.  For users who we host, we leave the message unseen; that will then
            # later generate a notification to them.  Otherwise we mail them the message and mark it as seen,
            # because they would get confused by a mail in our notification format.
            $r = new ChatRoom($this->dbhr, $this->dbhm);
            $rid = $r->createUser2Mod($this->getFromuser(), $groupid);

            if ($rid) {
                $m = new ChatMessage($this->dbhr, $this->dbhm);
                $mid = $m->create($rid,
                    $myid,
                    "$subject\r\n\r\n$body",
                    ChatMessage::TYPE_MODMAIL,
                    $this->id,
                    FALSE,
                    NULL);

                $this->mailer($me, TRUE, $this->getFromname(), $bcc, NULL, $name, $g->getModsEmail(), $subject, "(This is a BCC of a message sent to Freegle user #" . $this->getFromuser() . " $to)\n\n" . $body);

                # We, as a mod, have seen this message - update the roster to show that.  This avoids this message
                # appearing as unread to us and other mods.
                $r->updateRoster($myid, $mid);
            }

            if (!ourDomain($to)) {
                # Mail it out, naked of any of our notification wrapping.
                $this->mailer($me, TRUE, $this->getFromname(), $to, $bcc, $name, $g->getModsEmail(), $subject, $body);

                # Mark the message as seen, because have mailed it.
                $r->updateRoster($myid, $this->getFromuser());
            }
        }
    }

    public function reject($groupid, $subject, $body, $stdmsgid) {
        # No need for a transaction - if things go wrong, the message will remain in pending, which is the correct
        # behaviour.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => $subject ? Log::SUBTYPE_REJECTED : Log::SUBTYPE_DELETED,
            'msgid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'user' => $this->fromuser,
            'groupid' => $groupid,
            'text' => $subject,
            'stdmsgid' => $stdmsgid
        ]);

        $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);
        foreach ($groups as $group) {
            if ($group['yahooreject']) {
                # We can trigger rejection by email - do so.
                $this->mailer($me, TRUE, $group['yahooreject'], $group['yahooreject'], NULL, MODERATOR_EMAIL, MODERATOR_EMAIL, "My name is Iznik and I reject this message", "");
            }

            if ($group['yahoopendingid']) {
                # We can trigger rejection via the plugin - do so.
                $p = new Plugin($this->dbhr, $this->dbhm);
                $p->add($groupid, [
                    'type' => 'RejectPendingMessage',
                    'id' => $group['yahoopendingid']
                ]);
            }
        }

        # When rejecting, we put it in the appropriate collection, which means the user can potentially edit and
        # resend.
        if ($subject) {
            $sql = $subject ? "UPDATE messages_groups SET collection = ? WHERE msgid = ?;" : "UPDATE messages_groups SET deleted = 1 WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                MessageCollection::REJECTED,
                $this->id
            ]);
        } else {
            $sql = $subject ? "UPDATE messages_groups SET collection = 'Rejected', rejectedat = NOW() WHERE msgid = ?;" : "UPDATE messages_groups SET deleted = 1 WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                $this->id
            ]);
        }

        $this->notif->notifyGroupMods($groupid);

        $this->maybeMail($groupid, $subject, $body, 'Reject');
    }

    public function approve($groupid, $subject = NULL, $body = NULL, $stdmsgid = NULL, $yahooonly = FALSE) {
        # No need for a transaction - if things go wrong, the message will remain in pending, which is the correct
        # behaviour.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if (!$yahooonly) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_APPROVED,
                'msgid' => $this->id,
                'user' => $this->fromuser,
                'byuser' => $myid,
                'groupid' => $groupid,
                'stdmsgid' => $stdmsgid,
                'text' => $subject
            ]);
        }

        $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);
        foreach ($groups as $group) {
            if ($group['yahooapprove']) {
                # We can trigger approval by email - do so.
                $this->mailer($me, TRUE, $group['yahooapprove'], $group['yahooapprove'], NULL, MODERATOR_EMAIL, MODERATOR_EMAIL, "My name is Iznik and I reject this message", "");
            }

            if ($group['yahoopendingid']) {
                # We can trigger approval via the plugin - do so.
                $p = new Plugin($this->dbhr, $this->dbhm);
                $p->add($groupid, [
                    'type' => 'ApprovePendingMessage',
                    'id' => $group['yahoopendingid']
                ]);
            }
        }

        if (!$yahooonly) {
            # Update the arrival time to NOW().  This is because otherwise we will fail to send out messages which
            # were held for moderation to people who are on immediate emails.
            #
            # Make sure we don't approve multiple times, as this will lead to the same message being sent
            # out multiple times.
            $sql = "UPDATE messages_groups SET collection = ?, approvedby = ?, approvedat = NOW(), arrival = NOW() WHERE msgid = ? AND groupid = ? AND collection != ?;";
            $rc = $this->dbhm->preExec($sql, [
                MessageCollection::APPROVED,
                $myid,
                $this->id,
                $groupid,
                MessageCollection::APPROVED
            ]);

            #error_log("Approve $rc from $sql, $myid, {$this->id}, $groupid");

            $this->notif->notifyGroupMods($groupid);

            $this->maybeMail($groupid, $subject, $body, 'Approve');
        }

        $this->index();
    }

    public function reply($groupid, $subject, $body, $stdmsgid) {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_REPLIED,
            'msgid' => $this->id,
            'user' => $this->fromuser,
            'byuser' => $me ? $me->getId() : NULL,
            'groupid' => $groupid,
            'text' => $subject,
            'stdmsgid' => $stdmsgid
        ]);

        $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ?;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);
        foreach ($groups as $group) {
            $this->maybeMail($groupid, $subject, $body, $group['collection'] == MessageCollection::APPROVED ? 'Leave Approved Message' : 'Leave');
        }
    }

    function hold() {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $sql = "UPDATE messages SET heldby = ? WHERE id = ?;";
        $rc = $this->dbhm->preExec($sql, [ $me->getId(), $this->id ]);

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_HOLD,
                'msgid' => $this->id,
                'byuser' => $me ? $me->getId() : NULL
            ]);
        }
    }

    function release() {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $sql = "UPDATE messages SET heldby = NULL WHERE id = ?;";
        $rc = $this->dbhm->preExec($sql, [ $this->id ]);

        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_RELEASE,
                'msgid' => $this->id,
                'byuser' => $me ? $me->getId() : NULL
            ]);
        }
    }

    function delete($reason = NULL, $groupid = NULL, $subject = NULL, $body = NULL, $stdmsgid = NULL, $localonly = FALSE)
    {
        $me = whoAmI($this->dbhr, $this->dbhm);
        $rc = true;

        if ($this->attach_dir) {
            rrmdir($this->attach_dir);
        }

        if ($this->id) {
            # Delete from a specific or all groups that it's on.
            $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND " . ($groupid ? " groupid = ?" : " ?") . ";";
            $groups = $this->dbhr->preQuery($sql,
                [
                    $this->id,
                    $groupid ? $groupid : 1
                ]);

            $logged = FALSE;

            foreach ($groups as $group) {
                $groupid = $group['groupid'];

                $this->log->log([
                    'type' => Log::TYPE_MESSAGE,
                    'subtype' => Log::SUBTYPE_DELETED,
                    'msgid' => $this->id,
                    'user' => $this->fromuser,
                    'byuser' => $me ? $me->getId() : NULL,
                    'text' => $reason,
                    'groupid' => $groupid,
                    'stdmsgid' => $stdmsgid
                ]);

                # The message has been allocated to a group; mark it as deleted.  We keep deleted messages for
                # PD.
                #
                # We must zap the Yahoo IDs as we have a unique index on them.
                $rc = $this->dbhm->preExec("UPDATE messages_groups SET deleted = 1, yahooapprovedid = NULL, yahoopendingid = NULL WHERE msgid = ? AND groupid = ?;", [
                    $this->id,
                    $groupid
                ]);

                if (!$localonly) {
                    # We might be deleting an approved message or spam.
                    if ($group['yahooapprovedid']) {
                        # We can trigger deleted via the plugin - do so.
                        $p = new Plugin($this->dbhr, $this->dbhm);
                        $p->add($groupid, [
                            'type' => 'DeleteApprovedMessage',
                            'id' => $group['yahooapprovedid']
                        ]);
                    } else {
                        # Or we might be deleting a pending or spam message, in which case it may also need rejecting on Yahoo.
                        if ($group['yahooreject']) {
                            # We can trigger rejection by email - do so.
                            $this->mailer($me, TRUE, $group['yahooreject'], $group['yahooreject'], NULL, MODERATOR_EMAIL, MODERATOR_EMAIL, "My name is Iznik and I reject this message", "");
                        }

                        if ($group['yahoopendingid']) {
                            # We can trigger rejection via the plugin - do so.
                            $p = new Plugin($this->dbhr, $this->dbhm);
                            $p->add($groupid, [
                                'type' => 'RejectPendingMessage',
                                'id' => $group['yahoopendingid']
                            ]);
                        }
                    }
                }

                if ($groupid) {
                    $this->notif->notifyGroupMods($groupid);
                    $this->maybeMail($groupid, $subject, $body, $group['collection'] == MessageCollection::APPROVED ? 'Delete Approved Message' : 'Delete');
                }
            }

            # If we have deleted this message from all groups, mark it as deleted in the messages table.
            $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND deleted = 0;";
            $groups = $this->dbhr->preQuery($sql, [ $this->id ]);

            if (count($groups) === 0) {
                # We must zap the messageid as we have a unique index on it
                $rc = $this->dbhm->preExec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?;", [ $this->id ]);

                # Remove from the search index.
                $this->deindex();
            }
        }

        return($rc);
    }

    public function index() {
        $groups = $this->getGroups(FALSE, FALSE);
        foreach ($groups as $group) {
            # Add into the search index.
            $this->s->add($this->id, $this->subject, strtotime($group['arrival']), $group['groupid']);
        }
    }

    public function deindex() {
        $this->s->delete($this->id);
    }

    public function findEarlierCopy($groupid, $pendingid, $approvedid) {
        $sql = "SELECT msgid, collection FROM messages_groups WHERE groupid = ? AND " . ($pendingid ? 'yahoopendingid' : 'yahooapprovedid') . " = ?;";
        $msgs = $this->dbhr->preQuery($sql, [
            $groupid,
            $pendingid ? $pendingid : $approvedid
        ]);

        $msgid = count($msgs) == 0 ? NULL : $msgs[0]['msgid'];
        $collection = count($msgs) == 0 ? NULL : $msgs[0]['collection'];

        return([ $msgid, $collection ]);
    }

    public function checkEarlierCopies($approvedby) {
        # We don't need a transaction for this - transactions aren't great for scalability and worst case we
        # leave a spurious message around which a mod will handle.
        $ret = NULL;
        $sql = "SELECT * FROM messages WHERE messageid = ? ";
        if ($this->groupid) {
            # We might have a message already present which doesn't match on Message-ID (because Yahoo doesn't
            # always put it in) but matches on the approved/pending id.
            if ($this->yahooapprovedid) {
                $sql .= " OR id = (SELECT msgid FROM messages_groups WHERE groupid = {$this->groupid} AND yahooapprovedid = {$this->yahooapprovedid}) ";
            }
            if ($this->yahoopendingid) {
                $sql .= " OR id = (SELECT msgid FROM messages_groups WHERE groupid = {$this->groupid} AND yahoopendingid = {$this->yahoopendingid}) ";
            }
        }

        $msgs = $this->dbhr->preQuery($sql, [ $this->getMessageID() ]);
        #error_log($sql . $this->getMessageID());

        foreach ($msgs as $msg) {
            #error_log("In #{$this->id} found {$msg['id']} with " . $this->getMessageID());
            $ret = $msg['id'];
            $changed = '';
            $m = new Message($this->dbhr, $this->dbhm, $msg['id']);

            # We want the new message to have the spam type of the old message, because we check this to ensure
            # we don't move messages back from not spam to spam.
            $this->spamtype = $m->getPrivate('spamtype');
            
            # We want the old message to be on whatever group this message was sent to.
            #
            # We want to see the message on the group even if it's been deleted, otherwise we'll go ahead and try
            # to re-add it and get an exception.
            $oldgroups = $m->getGroups(TRUE);
            #error_log("Compare groups $this->groupid vs " . var_export($oldgroups, TRUE));
            if (!in_array($this->groupid, $oldgroups)) {
                // This code is here for future handling of the same message on multiple groups, but since we
                // currently make the message id per-group, we can't reach it.  Keep it for later use but don't
                // worry that we can't cover it.
                // @codeCoverageIgnoreStart
                /* @cov $collection */
                $collection = NULL;
                if ($this->getSource() == Message::YAHOO_PENDING) {
                    $collection = MessageCollection::PENDING;
                } else if ($this->getSource() == Message::YAHOO_APPROVED) {
                    $collection = MessageCollection::APPROVED;
                } else if ($this->getSource() == Message::EMAIL && $this->groupid) {
                    $collection = MessageCollection::INCOMING;
                }
                #error_log("Not on group, add to $collection");

                if ($collection) {
                    $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, msgtype, yahoopendingid, yahooapprovedid, yahooreject, yahooapprove, collection, approvedby, arrival) VALUES (?,?,?,?,?,?,?,?,?,NOW());", [
                        $msg['id'],
                        $this->groupid,
                        $m->getType(),
                        $this->yahoopendingid,
                        $this->yahooapprovedid,
                        $this->yahooreject,
                        $this->yahooapprove,
                        $collection,
                        $approvedby
                    ]);
                }
            } else {
                // @codeCoverageIgnoreEnd
                # Already on the group; pick up any new and better info.
                #error_log("Already on group, pick ");
                $gatts = $this->dbhr->preQuery("SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ?;", [
                    $msg['id'],
                    $this->groupid
                ]);

                foreach ($gatts as $gatt) {
                    foreach (['yahooapprovedid', 'yahoopendingid'] as $newatt) {
                        #error_log("Compare old {$gatt[$newatt]} vs new {$this->$newatt}");
                        if (!$gatt[$newatt] || ($this->$newatt && $gatt[$newatt] != $this->$newatt)) {
                            #error_log("Update mesages_groups for $newatt");
                            $this->dbhm->preExec("UPDATE messages_groups SET $newatt = ? WHERE msgid = ? AND groupid = ?;", [
                                $this->$newatt,
                                $msg['id'],
                                $this->groupid
                            ]);
                        }
                    }
                }
            }

            # For pending messages which come back to us as approved, it might not be the same.
            # This can happen if a message is handled on another system, e.g. moderated directly on Yahoo.
            # But if it's been edited on here, we don't want to take the Yahoo versions, which might be older.
            #
            # For approved messages which only reach us as pending later, we don't want to change the approved
            # version.
            if ($this->source == Message::YAHOO_APPROVED && !$this->isEdited()) {
                # Subject is a special case because Yahoo can add a subject tag in.
                #
                # We would never really want to have a subject that was empty (once we've stripped the tag).
                # This seems to happen sometimes due to Yahoo oddities when editing.
                $subj = $this->getPrivate('subject');
                $subj = trim(preg_replace('/^\[.*?\]\s*/', '', $subj));

                if (strlen($subj) && $subj != $m->getPrivate('subject')) {
                    # The new subject isn't empty.
                    $m->setPrivate('subject', $this->getPrivate('subject'));
                    $changed .= ' subject';
                }

                # Other atts which we want the latest version of.
                foreach (['date', 'message', 'textbody', 'htmlbody'] as $att) {
                    $oldval = $m->getPrivate($att);
                    $newval = $this->getPrivate($att);

                    if (!$oldval || ($newval && $oldval != $newval)) {
                        $changed .= ' $att';
                        #error_log("Update messages for $att, value len " . strlen($oldval) . " vs " . strlen($newval));
                        $m->setPrivate($att, $newval);
                    }
                }

                # We might need a new suggested subject, and mapping.
                $m->setPrivate('suggestedsubject', NULL);
                $m->suggestedsubject = $m->suggestSubject($this->groupid, $this->subject);

                # We keep the old set of attachments, because they might be mentioned in (for example) the text
                # of the message.  This means that we don't support editing of the attachments on Yahoo.

                # Pick up any new approvedby, unless we already have one.  If we have one it's because it was
                # approved on here, which is an authoritative record, whereas the Yahoo approval might have been
                # done via plugin work and another mod.
                $rc = $this->dbhm->preExec("UPDATE messages_groups SET approvedby = ?, approvedat = NOW() WHERE msgid = ? AND groupid = ? AND approvedby IS NULL;",
                    [
                        $approvedby,
                        $msg['id'],
                        $this->groupid
                    ]);

                $changed = $rc ? ' approvedby' : $changed;

                # This message might have moved from pending to approved.
                if ($m->getSource() == Message::YAHOO_PENDING && $this->getSource() == Message::YAHOO_APPROVED) {
                    $this->dbhm->preExec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?;", [
                        $msg['id'],
                        $this->groupid
                    ]);
                    $changed = TRUE;
                }

                if ($changed != '') {
                    $me = whoAmI($this->dbhr, $this->dbhm);

                    $this->log->log([
                        'type' => Log::TYPE_MESSAGE,
                        'subtype' => Log::SUBTYPE_EDIT,
                        'msgid' => $msg['id'],
                        'byuser' => $me ? $me->getId() : NULL,
                        'text' => "Updated from new incoming message ($changed)"
                    ]);
                }
            }
        }

        return($ret);
    }

    /**
     * @return mixed
     */
    public function getFromuser()
    {
        return $this->fromuser;
    }

    public function findFromReply($userid) {
        # TN puts a useful header in.
        $msgid = $this->getHeader('x-fd-msgid');

        if ($msgid) {
            # Check the message exists.
            $m = new Message($this->dbhr, $this->dbhm, $msgid);

            if ($m->getID() === $msgid) {
                return($msgid);
            }
        }

        # Unfortunately, it's fairly common for people replying by email to compose completely new
        # emails with subjects of their choice, or reply from Yahoo Groups which doesn't add
        # In-Reply-To headers.  So we just have to do the best we can using the email subject.  The Damerau–Levenshtein
        # distance does this for us - if we get a subject which is just "Re: " and the original, then that will come
        # top.  We can't do that in the DB, though, as we need to strip out some stuff.
        #
        # We only expect to be matching replies for reuse/Freegle groups, and it's not worth matching against any
        # old messages.
        $sql = "SELECT messages.id, subject, messages.date FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND fromuser = ? INNER JOIN groups ON groups.id = messages_groups.groupid AND groups.type IN ('Freegle', 'Reuse') AND DATEDIFF(NOW(), messages.arrival) < 90 LIMIT 1000;";
        $messages = $this->dbhr->preQuery($sql, [ $userid ]);

        $thissubj = Message::canonSubj($this->subject);

        # This is expected to be a reply - so remove the most common reply tag.
        $thissubj = preg_replace('/^Re\:/i', '', $thissubj);

        # Remove any punctuation and whitespace from the purported item.
        $thissubj = preg_replace('/\-|\,|\.| /', '', $thissubj);

        $mindist = PHP_INT_MAX;
        $match = FALSE;
        $matchmsg = NULL;

        foreach ($messages as $message) {
            $subj1 = $thissubj;
            $subj2 = Message::canonSubj($message['subject']);

            # Remove any punctuation and whitespace from the purported item.
            $subj2 = preg_replace('/\-|\,|\.| /', '', $subj2);

            # Find the distance.  We do this in PHP rather than in MySQL because we have done all this
            # munging on the subject.
            $d = new DamerauLevenshtein(strtolower($subj1), strtolower($subj2));
            $message['dist'] = $d->getSimilarity();
            $mindist = min($mindist, $message['dist']);

            error_log("Compare subjects $subj1 vs $subj2 dist {$message['dist']} min $mindist lim " . (strlen($subj1) * 3 / 4));

            if ($message['dist'] <= $mindist && $message['dist'] <= strlen($subj1) * 3 / 4) {
                # This is the closest match, but not utterly different.
                #error_log("Closest");
                $match = TRUE;
                $matchmsg = $message;
            }
        }

        return(($match && $matchmsg['id']) ? $matchmsg['id'] : NULL);
    }
    
    public function stripQuoted() {
        # Try to get the text we care about by stripping out quoted text.  This can't be
        # perfect - quoting varies and it's a well-known hard problem.
        $htmlbody = $this->getHtmlbody();
        $textbody = $this->getTextbody();

        if ($htmlbody && !$textbody) {
            $html = new \Html2Text\Html2Text($htmlbody);
            $textbody = $html->getText();
            #error_log("Converted HTML text $textbody");
        }

        # Convert unicode spaces to ascii spaces
        $textbody = str_replace("\xc2\xa0", "\x20", $textbody);

        # Remove basic quoting.
        #error_log("BEfore quote $textbody");
        $textbody = trim(preg_replace('#(^(>|\|).*(\n|$))+#mi', "", $textbody));
        #error_log("After squote $textbody");

        # We might have a section like this, for example from eM Client, which could be top or bottom-quoted.
        #
        # ------ Original Message ------
        # From: "Edward Hibbert" <notify-5147-16226909@users.ilovefreegle.org>
        # To: log@ehibbert.org.uk
        # Sent: 14/05/2016 14:19:19
        # Subject: Re: [FreeglePlayground] Offer: chair (Hesketh Lane PR3)
        $p = strpos($textbody, '------ Original Message ------');

        if ($p !== FALSE) {
            $q = strpos($textbody, "\r\n\r\n", $p);
            $textbody = ($q !== FALSE) ? (substr($textbody, 0, $p) . substr($textbody, $q)) : substr($textbody, 0, $p);
        }

        # Or this similar one, which is top-quoted.
        #
        # ----Original message----
        $p = strpos($textbody, '----Original message----');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this, which is top-quoted.
        $p = strpos($textbody, '--------------------------------------------');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or TN's
        #
        # _________________________________________________________________
        $p = strpos($textbody, '_________________________________________________________________');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        #
        # -------- Original message --------
        $p = strpos($textbody, '-------- Original message --------');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        #
        # ----- Original Message -----
        $p = strpos($textbody, '----- Original Message -----');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        # _____
        $p = strpos($textbody, '_____');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or this:
        # _____
        $p = strpos($textbody, '-----Original Message-----');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or Windows phones:
        #
        # ________________________________
        $p = strpos($textbody, '________________________________');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Some group sigs
        $p = strpos($textbody, '~*~*~*~*~*~*');
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or maybe just the headers with no preceding line.
        if (preg_match('/(.*)^From\:.*?ilovefreegle.org$(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }
        if (preg_match('/(.*)^From\:.*?trashnothing.com$(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }

        # A reply from us.
        $p = strpos($textbody, "You can respond by just replying to this email");
        $textbody = $p ? substr($textbody, 0, $p) : $textbody;

        # Or we might have this, for example from GMail:
        #
        # On Sat, May 14, 2016 at 2:19 PM, Edward Hibbert <
        # notify-5147-16226909@users.ilovefreegle.org> wrote:
        #
        # We're assuming here that everyone replies at the top.  Standard mail clients do this.
        if (preg_match('/(.*)^\s*On.*?wrote\:(\s*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        # Or we might have this, as a reply from a Yahoo Group message.
        if (preg_match('/(.*)^To\:.*yahoogroups.*$.*__,_._,___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }

        if (preg_match('/(.*?)__,_._,___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        if (preg_match('/(.*?)__._,_.___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        # Or we might have some headers, possibly indented with space.
        $textbody = preg_replace('/[\r\n](\s*)To:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)From:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Sent:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Date:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Subject:.*?$/is', '', $textbody);

        # Get rid of sigs
        $textbody = $this->stripSigs($textbody);

        // Duff text added by Yahoo Mail app.
        $textbody = str_replace('blockquote, div.yahoo_quoted { margin-left: 0 !important; border-left:1px #715FFA solid !important; padding-left:1ex !important; background-color:white !important; }', '', $textbody);
        $textbody = preg_replace('/\#yiv.*\}\}/', '', $textbody);

        #error_log("Pruned text to $textbody");

        // We might have links to our own site.  Strip these in case they contain login information.
        $textbody = preg_replace('/https:\/\/' . USER_SITE . '\S*/', 'https://' . USER_SITE, $textbody);

        // Redundant line breaks.
        $textbody = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\r\n\r\n", $textbody);

        // Our own footer.
        $textbody = preg_replace('/This message was from user .*?, and this mail was sent to .*?$/mi', "", $textbody);
        $textbody = preg_replace('/Freegle is registered as a charity.*?nice./mi', "", $textbody);
        $textbody = preg_replace('/This mail was sent to.*?/mi', "", $textbody);
        $textbody = preg_replace('/You can change your settings by clicking here.*?/mi', "", $textbody);

        # | is used to quote.
        $textbody = preg_replace('/^\|/mi', '', $textbody);

        $textbody = trim($textbody);
        if (substr($textbody, -1) == '|') {
            $textbody = substr($textbody, 0, strlen($textbody) - 1);
        }

        # Strip underscores and dashes, which can arise due to quoting issues.
        return(trim($textbody, " \t\n\r\0\x0B_-"));
    }

    public function stripSigs($textbody) {
        $textbody = preg_replace('/^Get Outlook for Android.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my Xperia.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my BlueMail/ms', '', $textbody);
        $textbody = preg_replace('/^Sent using the mail.com mail app.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my phone.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my iPad.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my .*smartphone./ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my iPhone.*/ms', '', $textbody);
        $textbody = preg_replace('/Sent.* from my iPhone/i', '', $textbody);
        $textbody = preg_replace('/^Sent from EE.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my Samsung device.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my Windows Phone.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from the trash nothing! Mobile App.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my account on trashnothing.com.*/ms', '', $textbody);
        $textbody = preg_replace('/^Save time browsing & posting to.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent on the go from.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from Yahoo Mail.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from Mail.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my BlackBerry.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from my Huawei Mobile.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from myMail for iOS.*/ms', '', $textbody);
        $textbody = preg_replace('/^Von meinem Samsung Galaxy Smartphone gesendet.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from Samsung Mobile.*/ms', '', $textbody);
        $textbody = preg_replace('/^(\r\n|\r|\n)---(\r\n|\r|\n)This email has been checked for viruses.*/ms', '', $textbody);
        $textbody = preg_replace('/^Sent from TypeApp.*/ms', '', $textbody);
        $textbody = preg_replace('/^Enviado a partir do meu smartphone.*/ms', '', $textbody);
        $textbody = preg_replace('/^Getting too many emails from.*Free your inbox.*trashnothing.com/ms', '', $textbody);
        $textbody = preg_replace('/^Try trashnothing.com for quicker and easier access.*!/ms', '', $textbody);
        $textbody = preg_replace('/^Discover a better way to browse.*trashnothing.com/ms', '', $textbody);

        return(trim($textbody));
    }
    
    public static function canonSubj($subj, $lower = TRUE) {
        if ($lower) {
            $subj = strtolower($subj);
        }

        // Remove any group tag
        $subj = preg_replace('/^\[.*?\](.*)/', "$1", $subj);

        // Remove duplicate spaces
        $subj = preg_replace('/\s+/', ' ', $subj);

        $subj = trim($subj);

        return($subj);
    }

    public static function removeKeywords($type, $subj) {
        $keywords = Message::keywords();
        if (pres($type, $keywords)) {
            foreach ($keywords[$type] as $keyword) {
                $subj = preg_replace('/(^|\b)' . preg_quote($keyword) . '\b/i', '', $subj);
            }
        }

        return($subj);
    }

    public function recordRelated() {
        # Message A is related to message B if:
        # - they are from the same underlying sender (people may post via multiple routes)
        # - A is an OFFER and B a TAKEN, or A is a WANTED and B is a RECEIVED
        # - the TAKEN/RECEIVED is more recent than the OFFER/WANTED (because if it's earlier, it can't be a TAKEN for this OFFER)
        # - the OFFER/WANTED is more recent than any previous previous similar TAKEN/RECEIVED (because then we have a repost
        #   or similar items scenario, and the earlier TAKEN will be related to still earlier OFFERs
        #
        # We might explicitly flag a message using X-Iznik-Related-To
        switch ($this->type) {
            case Message::TYPE_OFFER: $type = Message::TYPE_TAKEN; $datedir = 1; break;
            case Message::TYPE_TAKEN: $type = Message::TYPE_OFFER; $datedir = -1; break;
            case Message::TYPE_WANTED: $type = Message::TYPE_RECEIVED; $datedir = 1; break;
            case Message::TYPE_RECEIVED: $type = Message::TYPE_WANTED; $datedir = -1; break;
            default: $type = NULL;
        }

        $found = 0;
        $loc = NULL;

        $thissubj = Message::canonSubj($this->subject);

        if (preg_match('/.*?\:.*\((.*)\)/', $thissubj, $matches)) {
            $loc = trim($matches[1]);
        }

        if (preg_match('/.*?\:(.*)\(.*\)/', $this->subject, $matches)) {
            # Standard format - extract the item.
            $thissubj = trim($matches[1]);
        } else {
            # Non-standard format.  Remove the keywords.
            $thissubj = Message::removeKeywords($this->type, $thissubj);
        }

        # Remove any punctuation and whitespace from the purported item.
        $thissubj = preg_replace('/\-|\,|\.| /', '', $thissubj);

        if ($type) {
            # Don't want to look for any messages which already have an outcome, otherwise we would fail to handle
            # crosspost messages correctly - we'd link all TAKENs to the same OFFER.
            #
            # A consequence of this is that we may not relate TAKEN/RECEIVED for platform messages correctly as
            # we create an outcome first and then send the message to Yahoo.  But that is less bad, and Yahoo is
            # on its way out.
            #
            # No point caching as we are unlikely to repeat this query.
            $groupq = $this->groupid ? (" INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.groupid = " . $this->dbhr->quote($this->groupid) . " ") : '';
            $sql = "SELECT messages.id, subject, date FROM messages LEFT JOIN messages_outcomes ON messages.id = messages_outcomes.msgid $groupq WHERE fromuser = ? AND type = ? AND DATEDIFF(NOW(), messages.arrival) <= 31 AND messages_outcomes.id IS NULL;";
            $messages = $this->dbhr->preQuery($sql, [ $this->fromuser, $type ], FALSE);
            #error_log($sql . var_export([ $thissubj, $thissubj, $this->fromuser, $type ], TRUE));
            $thistime = strtotime($this->date);

            $mindist = PHP_INT_MAX;
            $match = FALSE;
            $matchmsg = NULL;

            foreach ($messages as $message) {
                $messsubj = Message::canonSubj($message['subject']);
                #error_log("Compare {$message['date']} vs {$this->date}, " . strtotime($message['date']) . " vs $thistime");

                if ((($datedir == 1) && strtotime($message['date']) >= $thistime) ||
                    (($datedir == -1) && strtotime($message['date']) <= $thistime)) {
                    if (preg_match('/.*?\:(.*)\(.*\)/', $messsubj, $matches)) {
                        # Standard format = extract the item.
                        $subj2 = trim($matches[1]);
                    } else {
                        # Non-standard - remove keywords.
                        $subj2 = Message::removeKeywords($type, $messsubj);

                        # We might have identified a valid location in the original message which appears in a non-standard
                        # way.
                        $subj2 = $loc ? str_ireplace($loc, '', $subj2) : $subj2;
                    }

                    $subj1 = $thissubj;

                    # Remove any punctuation and whitespace from the purported item.
                    $subj2 = preg_replace('/\-|\,|\.| /', '', $subj2);

                    # Find the distance.  We do this in PHP rather than in MySQL because we have done all this
                    # munging on the subject to extract the relevant bit.
                    $d = new DamerauLevenshtein(strtolower($subj1), strtolower($subj2));
                    $message['dist'] = $d->getSimilarity();
                    $mindist = min($mindist, $message['dist']);

                    #error_log("Compare subjects $subj1 vs $subj2 dist {$message['dist']} min $mindist lim " . (strlen($subj1) * 3 / 4));

                    if (strtolower($subj1) == strtolower($subj2)) {
                        # Exact match
                        #error_log("Exact");
                        $match = TRUE;
                        $matchmsg = $message;
                    } else if ($message['dist'] <= $mindist && $message['dist'] <= strlen($subj1) * 3 / 4) {
                        # This is the closest match, but not utterly different.
                        #error_log("Closest");
                        $match = TRUE;
                        $matchmsg = $message;
                    }
                }
            }

            #error_log("Match $match message " . var_export($matchmsg, TRUE));

            if ($match && $matchmsg['id']) {
                # We seem to get a NULL returned in circumstances I don't quite understand but but which relate to
                # the use of DAMLEVLIM.
                #error_log("Best match {$matchmsg['subject']}");
                $sql = "INSERT IGNORE INTO messages_related (id1, id2) VALUES (?,?);";
                $this->dbhm->preExec($sql, [ $this->id, $matchmsg['id']] );

                if ($this->getSourceheader() != Message::PLATFORM &&
                    ($this->type == Message::TYPE_TAKEN || $this->type == Message::TYPE_RECEIVED)) {
                    # Also record an outcome on the original message.  We only need to do this when the message didn't
                    # come from our platform, because if it did that has already happened.  This also avoids the
                    # situation where we match against the wrong message because of the order messages arrive from Yahoo.
                    $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, userid, comments) VALUES (?,?,?,?,?);", [
                        $matchmsg['id'],
                        $this->type == Message::TYPE_TAKEN ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED,
                        NULL,
                        NULL,
                        $this->getTextbody()
                    ]);
                }

                $found++;
            }
        }

        return($found);
    }

    public function spam($groupid) {
        # We mark is as spam on all groups, and delete it on the specific one in question.
        $this->dbhm->preExec("UPDATE messages_groups SET collection = ? WHERE msgid = ?;", [ MessageCollection::SPAM, $this->id ]);
        $this->delete("Deleted as spam", $groupid);

        # Record for training.
        $this->dbhm->preExec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?);", [ $this->id , Spam::SPAM ]);
    }

    public function notSpam() {
        if ($this->spamtype == Spam::REASON_SUBJECT_USED_FOR_DIFFERENT_GROUPS) {
            # This subject is probably fine, then.
            $s = new Spam($this->dbhr, $this->dbhm);
            $s->notSpamSubject($this->getPrunedSubject());
        }

        # We leave the spamreason and type set in the message, because it can be useful for later PD.
        #
        # Record for training.
        $this->dbhm->preExec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?);", [ $this->id , Spam::HAM ]);
    }

    public function suggestSubject($groupid, $subject) {
        $newsubj = $subject;
        $type = $this->determineType($subject);

        # We only need to do this for OFFER/WANTED messages (TAKEN/RECEIVED are less important and tend to be
        # automatically generated), and also for messages which aren't from a platform which constructs the subject
        # line in a trustworthy way (i.e. us and TN).
        $srchdr = $this->getSourceheader();

        if ($srchdr != Message::PLATFORM && strpos($srchdr, 'TN-') !== 0 &&
            ($type == Message::TYPE_OFFER || $type == Message::TYPE_WANTED)) {
            # We only need to do this if the message came from elsewhere - ones we composed are well formatted and
            # already mapped.
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);

            # This method is used to improve subjects, and also to map - because we need to make sure we understand the
            # subject format before can map.
            $keywords = $g->getSetting('keywords', []);

            # Remove any subject tag.
            $subject = preg_replace('/\[.*?\]\s*/', '', $subject);

            $pretag = $subject;

            # Strip any of the keywords.
            foreach ($this->keywords()[$type] as $keyword) {
                $subject = preg_replace('/(^|\b)' . preg_quote($keyword) . '\b/i', '', $subject);
            }

            # Only proceed if we found the type tag.
            if ($subject != $pretag) {
                # Shrink multiple spaces
                $subject = preg_replace('/\s+/', ' ', $subject);
                $subject = trim($subject);

                # Find a location in the subject.  Only seek ) at end because if it's in the middle it's probably
                # not a location.
                $loc = NULL;
                $l = new Location($this->dbhr, $this->dbhm);

                if (preg_match('/(.*)\((.*)\)$/', $subject, $matches)) {
                    # Find the residue, which will be the item, and tidy it.
                    $residue = trim($matches[1]);

                    $aloc = $matches[2];

                    # Check if it's a good location.
                    #error_log("Check loc $aloc");
                    $locs = $l->search($aloc, $groupid, 1);
                    #error_log(var_export($locs, TRUE));

                    if (count($locs) == 1) {
                        # Take the name we found, which may be better than the one we have, if only in capitalisation.
                        $loc = $locs[0];
                    }
                } else {
                    # The subject is not well-formed.  But we can try anyway.
                    #
                    # Look for an exact match for a known location in the subject.
                    $locs = $l->locsForGroup($groupid);
                    $bestpos = 0;
                    $bestlen = 0;
                    $loc = NULL;

                    foreach ($locs as $aloc) {
                        #error_log($aloc['name']);
                        $xp = '/\b' . preg_quote($aloc['name'],'/') . '\b/i';
                        #error_log($xp);
                        $p = preg_match($xp, $subject, $matches, PREG_OFFSET_CAPTURE);
                        #error_log("$subject matches as $p with $xp");
                        $p = $p ? $matches[0][1] : FALSE;
                        #error_log("p2 $p");

                        if ($p !== FALSE &&
                            (strlen($aloc['name']) > $bestlen ||
                                (strlen($aloc['name']) == $bestlen && $p > $bestpos))) {
                            # The longer a location is, the more likely it is to be the correct one.  If we get a
                            # tie, then the further right it is, the more likely to be a location.
                            $loc = $aloc;
                            $bestpos = $p;
                            $bestlen = strlen($loc['name']);
                        }
                    }

                    $residue = preg_replace('/' . preg_quote($loc['name']) . '/i', '', $subject);
                }

                if ($loc) {
                    $punc = '\(|\)|\[|\]|\,|\.|\-|\{|\}|\:|\;| ';
                    $residue = preg_replace('/^(' . $punc . ')*/','', $residue);
                    $residue = preg_replace('/(' . $punc . '){2,}$/','', $residue);
                    $residue = trim($residue);

                    if ($residue == strtoupper($residue)) {
                        # All upper case.  Stop it being shouty.
                        $residue = strtolower($residue);
                    }

                    $typeval = presdef(strtolower($type), $keywords, strtoupper($type));
                    $newsubj = $typeval . ": $residue ({$loc['name']})";

                    $this->lat = $loc['lat'];
                    $this->lng = $loc['lng'];
                    $this->locationid = $loc['id'];

                    if ($this->fromuser) {
                        # Save off this as the last known location for this user.
                        $this->dbhm->preExec("UPDATE users SET lastlocation = ? WHERE id = ?;", [
                            $this->locationid,
                            $this->fromuser
                        ]);
                        User::clearCache($this->fromuser);
                    }
                }
            }
        }

        return($newsubj);
    }

    public function replaceAttachments($atts) {
        # We have a list of attachments which may or may not currently be attached to the message we're interested in,
        # which might have other attachments which need zapping.
        $oldids = [];
        $oldatts = $this->dbhm->preQuery("SELECT id FROM messages_attachments WHERE msgid = ?;", [ $this->id ]);
        foreach ($oldatts as $oldatt) {
            $oldids[] = $oldatt['id'];
        }

        foreach ($atts as $attid) {
            if ($attid) {
                $this->dbhm->preExec("UPDATE messages_attachments SET msgid = ? WHERE id = ?;", [ $this->id, $attid ]);
                $key = array_search($attid, $oldids);
                if ($key !== FALSE) {
                    unset($oldids[$key]);
                }
            }
        }

        foreach ($oldids as $oldid) {
            $this->dbhm->preExec("DELETE FROM messages_attachments WHERE id = ?;", [ $oldid ]);
        }
    }

    public function search($string, &$context, $limit = Search::Limit, $restrict = NULL, $groups = NULL, $locationid = NULL, $exactonly = FALSE) {
        $ret = $this->s->search($string, $context, $limit, $restrict, $groups, $exactonly);
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if (count($ret) > 0 && $myid) {
            $maxid = $ret[0]['id'];
            $s = new UserSearch($this->dbhr, $this->dbhm);
            $s->create($myid, $maxid, $string, $locationid);
        }

        $this->dbhm->preExec("INSERT INTO search_history (userid, term, locationid, groups) VALUES (?, ?, ?, ?);", [
            $myid,
            $string,
            $locationid,
            $groups ? implode(',', $groups) : NULL
        ]);

        return($ret);
    }

    public function mailf($fromemail, $toemail, $hdrs, $body) {
        $rc = FALSE;
        $mailf = Mail::factory("mail", "-f " . $fromemail);
        if ($mailf->send($toemail, $hdrs, $body) === TRUE) {
            $rc = TRUE;
        }

        return($rc);
    }

    public function constructSubject($groupid) {
        # Construct the subject - do this now as it may get displayed to the user before we get the membership.
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);
        $keywords = $g->getSetting('keywords', $g->defaultSettings['keywords']);

        $atts = $this->getPublic(FALSE, FALSE, TRUE);
        $items = $this->getItems();

        if (pres('location', $atts) && count($items) > 0) {
            # Normally we should have an area and postcode to use, but as a fallback we use the area we have.
            if (pres('area', $atts) && pres('postcode', $atts)) {
                $includearea = $g->getSetting('includearea', TRUE);
                $includepc = $g->getSetting('includepc', TRUE);
                if ($includearea && $includepc) {
                    # We want the area in the group, e.g. Edinburgh EH4.
                    $loc = $atts['area']['name'] . ' ' . $atts['postcode']['name'];
                } else if ($includepc) {
                    # Just postcode, e.g. EH4
                    $loc = $atts['postcode']['name'];
                } else  {
                    # Just area or foolish settings, e.g. Edinburgh
                    $loc = $atts['area']['name'];
                }
            } else {
                $l = new Location($this->dbhr, $this->dbhm, $atts['location']['id']);
                $loc = $l->ensureVague();
            }

            $subject = presdef(strtolower($this->type), $keywords, strtoupper($this->type)) . ': ' . $items[0]['name'] . " ($loc)";
            $this->setPrivate('subject', $subject);
        }
    }

    public function queueForMembership(User $fromuser, $groupid) {
        # We would like to submit this message, but we can't do so because we don't have a membership on the Yahoo
        # group yet.  So fire off an application for one; when this gets processed, we will submit the
        # message.
        $ret = NULL;
        $this->setPrivate('fromuser', $fromuser->getId());

        # If this message is already on this group, that's fine.
        $rc = $this->dbhm->preExec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival, msgtype) VALUES (?,?,?,NOW(),?);", [
            $this->id,
            $groupid,
            MessageCollection::QUEUED_YAHOO_USER,
            $this->getType()
        ]);

        if ($rc) {
            # We've stored the message; send a subscription.
            $ret = $fromuser->triggerYahooApplication($groupid);
        }
        
        return($ret);
    }

    public function addItem($itemid) {
        # Ignore duplicate msgid/itemid.
        $this->dbhm->preExec("INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?);", [ $this->id, $itemid]);
    }

    public function getItems() {
        return($this->dbhr->preQuery("SELECT * FROM messages_items INNER JOIN items ON messages_items.itemid = items.id WHERE msgid = ?;", [ $this->id ]));
    }

    public function submit(User $fromuser, $fromemail, $groupid) {
        $rc = FALSE;
        $this->setPrivate('fromuser', $fromuser->getId());

        # Submit a draft or repost a message. Either way, it currently has:
        #
        # - a locationid
        # - a type
        # - an item
        # - a subject
        # - a fromuser
        # - a textbody
        # - zero or more attachments
        #
        # We need to turn this into a full message:
        # - create a Message-ID
        # - other bits and pieces
        # - create a full MIME message
        # - send it
        # - remove it from the drafts table
        # - remove any previous outcomes.
        $atts = $this->getPublic(FALSE, FALSE, TRUE);

        if (pres('location', $atts)) {
            $messageid = $this->id . '@' . USER_DOMAIN;
            $this->setPrivate('messageid', $messageid);

            $this->setPrivate('fromaddr', $fromemail);
            $this->setPrivate('fromaddr', $fromemail);
            $this->setPrivate('fromname', $fromuser->getName());
            $this->setPrivate('lat', $atts['location']['lat']);
            $this->setPrivate('lng', $atts['location']['lng']);

            # Save off this as the last known location for this user.
            $fromuser->setPrivate('lastlocation', $atts['location']['id']);

            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $this->setPrivate('envelopeto', $g->getGroupEmail());

            $this->dbhm->preExec("DELETE FROM messages_outcomes WHERE msgid = ?;", [ $this->id ]);
            $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);

            # The from IP and country.
            $ip = presdef('REMOTE_ADDR', $_SERVER, NULL);

            if ($ip) {
                $this->setPrivate('fromip', $ip);

                try {
                    $reader = new Reader(MMDB);
                    $record = $reader->country($ip);
                    $this->setPrivate('fromcountry', $record->country->isoCode);
                } catch (Exception $e) {
                    # Failed to look it up.
                    error_log("Failed to look up $ip " . $e->getMessage());
                }
            }

            $txtbody = $this->textbody;
            $htmlbody = "<p>{$this->textbody}</p>";

            $atts = $this->getAttachments();

            if (count($atts) > 0) {
                # We have attachments.  Include them as image tags.
                $htmlbody .= "<table><tbody><tr>";
                $count = 0;

                foreach ($atts as $att) {
                    $path = $att->getPath(FALSE);
                    $htmlbody .= '<td><a href="' . $path . '" target="_blank"><img width="200px" src="' . $path . '" /></a></td>';

                    $count++;

                    $htmlbody .= ($count % 3 == 0) ? '</tr><tr>' : '';
                }

                $htmlbody .= "</tr></tbody></table>";
            }

            $htmlbody = str_replace("\r\n", "<br>", $htmlbody);
            $htmlbody = str_replace("\r", "<br>", $htmlbody);
            $htmlbody = str_replace("\n", "<br>", $htmlbody);

            $this->setPrivate('textbody', $txtbody);
            $this->setPrivate('htmlbody', $htmlbody);

            # Strip possible group name.
            $subject = $this->subject;
            if (preg_match('/\[.*?\](.*)/', $subject, $matches)) {
                $subject = trim($matches[1]);
            }

            if ($g->getPrivate('onyahoo')) {
                # This group is on Yahoo so we need to send the email there.  Now construct the actual message to send.
                try {
                    list ($transport, $mailer) = getMailer();

                    $message = Swift_Message::newInstance()
                        ->setSubject($subject)
                        ->setFrom([$fromemail => $fromuser->getName()])
                        ->setTo([$g->getGroupEmail()])
                        ->setDate(time())
                        ->setId($messageid)
                        ->setBody($txtbody);

                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                    # Outlook.
                    $htmlPart = Swift_MimePart::newInstance();
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                    $htmlPart->setContentType('text/html');
                    $htmlPart->setBody($htmlbody);
                    $message->attach($htmlPart);

                    # We add some headers so that if we receive this back, we can identify it as a mod mail.
                    $headers = $message->getHeaders();
                    $headers->addTextHeader('X-Iznik-MsgId', $this->id);
                    $headers->addTextHeader('X-Iznik-From-User', $fromuser->getId());

                    # Store away the constructed message.
                    $this->setPrivate('message', $message->toString());

                    # Reset the message id we have in the DB to be per-group.  This is so that we recognise it when
                    # it comes back - see save() code above.
                    $this->setPrivate('messageid', "$messageid-$groupid");

                    $mailer->send($message);

                    # This message is now pending.  That means it will show up in ModTools; if it is approved before
                    # it reaches Yahoo and we get notified then we will handle that in submitYahooQueued.
                    $this->dbhm->preExec("UPDATE messages_groups SET senttoyahoo = 1, collection = ? WHERE msgid = ?;", [ MessageCollection::PENDING, $this->id]);

                    # Add to message history for spam checking.
                    $this->addToMessageHistory();

                    $rc = TRUE;
                } catch (Exception $e) {
                    error_log("Send failed with " . $e->getMessage());
                    $rc = FALSE;
                }
            } else {
                # No need to submit by email.  Just notify the group mods.
                $rc = TRUE;
                $n = new PushNotifications($this->dbhr, $this->dbhm);
                $n->notifyGroupMods($groupid);
            }

            # This message is now not a draft.
            $this->dbhm->preExec("DELETE FROM messages_drafts WHERE msgid = ?;", [ $this->id ]);

            # Record the posting, which is also used in producing the messagehistory.
            $this->dbhm->preExec("INSERT INTO messages_postings (msgid, groupid) VALUES (?,?);", [ $this->id, $groupid ]);
        }

        return($rc);
    }

    public function promise($userid) {
        # Promise this item to a user.
        #
        # We can't promise to multiple users.  This is because when we mark something as TAKEN, we ask who it was
        # given to, and assume that anyone who it was promised to who isn't that person reneged.
        #
        # If we change that, we can remove the unique index, but we'll need to change the UI too.
        $sql = "REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?);";
        $this->dbhm->preExec($sql, [
            $this->id,
            $userid
        ]);
    }

    public function renege($userid) {
        # Unpromise this item.
        #
        # Record it - we use this to determine member reliability.
        $this->dbhm->preExec("INSERT INTO messages_reneged (userid, msgid) VALUES (?, ?);", [
            $userid,
            $this->id
        ]);

        $sql = "DELETE FROM messages_promises WHERE msgid = ? AND userid = ?;";
        $this->dbhm->preExec($sql, [
            $this->id,
            $userid
        ]);
    }

    public function reverseSubject() {
        $subj = $this->getSubject();
        $type = $this->getType();

        # Remove any group tag at the start.
        if (preg_match('/^\[.*?\](.*)/', $subj, $matches)) {
            # Strip possible group name
            $subj = trim($matches[1]);
        }

        # Strip any attachments tag put in by Yahoo
        if (preg_match('/(.*)\[.*? Attachment.*\].*/', $subj, $matches)) {
            # Strip possible group name
            $subj = trim($matches[1]);
        }

        # Strip the relevant keywords.
        $keywords = Message::keywords()[$type];

        foreach ($keywords as $keyword) {
            if (preg_match('/ ' . preg_quote($keyword) . '\:(.*)/i', $subj, $matches)) {
                $subj = $matches[1];
            }
        }

        foreach ($keywords as $keyword) {
            if (preg_match('/.*' . preg_quote($keyword) . '.*\:(.*)/i', $subj, $matches)) {
                $subj = $matches[1];
            }
        }

        foreach ($keywords as $keyword) {
            if (preg_match('/^' . preg_quote($keyword) . '\b(.*)/i', $subj, $matches)) {
                $subj = $matches[1];
            }
        }

        # Now we have to add in the corresponding keyword.  The message should be on at least one group; if the
        # groups have different keywords then there's not much we can do.
        $groups = $this->getGroups();
        $key = strtoupper($type == Message::TYPE_OFFER ? Message::TYPE_TAKEN : Message::TYPE_RECEIVED);
        
        foreach ($groups as $groupid) {
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);
            $defs = $g->getDefaults()['keywords'];
            $keywords = $g->getSetting('keywords', $defs);

            foreach ($keywords as $word => $val) {
                if (strtoupper($word) == $key) {
                    $key = $val;
                }
            }
            break;
        }

        $subj = substr($subj, 0, 1) == ':' ? $subj : ":$subj";
        $subj = $key . $subj;

        return($subj);
    }

    public function intendedOutcome($outcome) {
        $sql = "INSERT INTO messages_outcomes_intended (msgid, outcome) VALUES (?, ?) ON DUPLICATE KEY UPDATE outcome = ?;";
        $this->dbhm->preExec($sql, [
            $this->id,
            $outcome,
            $outcome
        ]);
    }

    public function mark($outcome, $comment, $happiness, $userid) {
        $me = whoAmI($this->dbhr, $this->dbhm);

        $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);
        $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, userid, comments) VALUES (?,?,?,?,?);", [
            $this->id,
            $outcome,
            $happiness,
            $userid,
            $comment
        ]);

        # You might think that if we are passed a $userid then we could log a renege for any other users to whom
        # this was promised - but we can promise to multiple users, whereas we can only mark a single user in the
        # TAKEN (which is probably a bug).  And if we are withdrawing it, then we don't really know why - it could
        # be that we changed our minds, which isn't the fault of the person we promised it to.

        # This message may be on one or more Yahoo groups; if so we need to send a TAKEN.
        $subj = $this->reverseSubject();
        $u = User::get($this->dbhr, $this->dbhm, $this->fromuser);

        $groups = $this->getGroups();

        foreach ($groups as $groupid) {
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);

            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_OUTCOME,
                'msgid' => $this->id,
                'user' => $me ? $me->getId() : NULL,
                'groupid' => $groupid,
                'text' => "$outcome $comment"
            ]);

            if ($g->getPrivate('onyahoo')) {
                # For Yahoo, we send a TAKEN/RECEIVED message.  Not for native.
                list ($eid, $email) = $u->getEmailForYahooGroup($groupid, TRUE, TRUE);
                $this->mailer(
                    $u,
                    FALSE,
                    $g->getGroupEmail(),
                    $g->getGroupEmail(),
                    NULL,
                    $u->getName(),
                    $email,
                    $subj,
                    ($happiness === NULL || $happiness == User::HAPPY || $happiness == User::FINE) ? $comment : ''
                );
            }
        }

        # Let anyone who was interested, and who didn't get it, know.
        $userq = $userid ? " AND user1 != $userid AND user2 != $userid " : "";
        $sql = "SELECT DISTINCT t.* FROM (SELECT chatid FROM chat_messages INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid AND chat_rooms.chattype = ? WHERE refmsgid = ? AND reviewrejected = 0 $userq GROUP BY userid, chatid) t;";
        $replies = $this->dbhr->preQuery($sql, [ ChatRoom::TYPE_USER2USER, $this->id ]);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);

        foreach ($replies as $reply) {
            $mid = $cm->create($reply['chatid'], $this->getFromuser(), NULL, ChatMessage::TYPE_COMPLETED, $this->id);

            # Make sure this message is highlighted in chat/email.
            $r = new ChatRoom($this->dbhr, $this->dbhm, $reply['chatid']);
            $r->upToDate($this->getFromuser());
        }
    }

    public function withdraw($comment, $happiness) {
        $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);

        $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments) VALUES (?,?,?,?);", [
            $this->id,
            Message::OUTCOME_WITHDRAWN,
            $happiness,
            $comment
        ]);

        $me = whoAmI($this->dbhr, $this->dbhm);

        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_OUTCOME,
            'msgid' => $this->id,
            'user' => $this->getFromuser(),
            'byuser' => $me ? $me->getId() : NULL,
            'text' => "Withdrawn: $comment"
        ]);
    }

    public function backToDraft() {
        # Convert a message back to a draft.
        $rollback = FALSE;
        $me = whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        if ($this->dbhm->beginTransaction()) {
            $rollback = TRUE;

            if ($this->id) {
                # This might already be a draft, so ignore dups.
                $rc = $this->dbhm->preExec("INSERT IGNORE INTO messages_drafts (msgid, userid, session) VALUES (?, ?, ?);", [ $this->id, $myid, session_id() ]);

                if ($rc) {
                    $rc = $this->dbhm->preExec("DELETE FROM messages_groups WHERE msgid = ?;", [ $this->id ]);

                    if ($rc) {
                        $rc = $this->dbhm->commit();

                        if ($rc) {
                            $rollback = FALSE;
                        }
                    }
                }
            }
        }

        if ($rollback) {
            $this->dbhm->rollBack();
        }

        return(!$rollback);
    }

    public function autoRepostGroup($type, $mindate, $groupid = NULL, $msgid = NULL) {
        $count = 0;
        $warncount = 0;
        $groupq = $groupid ? " AND id = $groupid " : "";
        $msgq = $msgid ? " AND messages_groups.msgid = $msgid " : "";

        # Randomise the order to give all groups a chance if the script gets killed or something.
        $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE type = ? $groupq ORDER BY RAND();", [ $type ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['id']);
            $reposts = $g->getSetting('reposts', [ 'offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);

            # We want approved messages which haven't got an outcome, aren't promised, don't have any replies and
            # which we originally sent.
            #
            # The replies part is because we can't really rely on members to let us know what happens to a message,
            # especially if they are not receiving emails reliably.  At least this way it avoids the case where a
            # message gets resent repeatedly and people keep replying and not getting a response.
            #
            # The sending user must also still be a member of the group.
            $sql = "SELECT messages_groups.msgid, messages_groups.groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago, autoreposts, lastautopostwarning, messages.type, messages.fromaddr FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid INNER JOIN memberships ON memberships.userid = messages.fromuser AND memberships.groupid = messages_groups.groupid LEFT OUTER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid LEFT OUTER JOIN messages_promises ON messages_promises.msgid = messages.id LEFT OUTER JOIN chat_messages ON messages.id = chat_messages.refmsgid WHERE messages_groups.arrival > ? AND messages_groups.groupid = ? AND messages_groups.collection = 'Approved' AND messages_outcomes.msgid IS NULL AND messages_promises.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND sourceheader IN ('Platform', 'FDv2') AND messages.deleted IS NULL AND chat_messages.refmsgid IS NULL $msgq;";
            #error_log("$sql, $mindate, {$group['id']}");
            $messages = $this->dbhr->preQuery($sql, [
                $mindate,
                $group['id']
            ]);

            $now = time();

            foreach ($messages as $message) {
                if (ourDomain($message['fromaddr'])) {
                    if ($message['autoreposts'] < $reposts['max']) {
                        # We want to send a warning 24 hours before we repost.
                        $lastwarnago = $message['lastautopostwarning'] ? ($now - strtotime($message['lastautopostwarning'])) : NULL;
                        $interval = $message['type'] == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];

                        # If we have messages which are older than we could have been trying for, ignore them.
                        $maxage = $interval * ($reposts['max'] + 1);

                        error_log("Consider repost {$message['msgid']}, posted {$message['hoursago']} interval $interval lastwarning $lastwarnago maxage $maxage");

                        if ($message['hoursago'] < $maxage * 24) {
                            # Reposts might be turned off.
                            if ($interval > 0 && $reposts['max'] > 0) {
                                if ($message['hoursago'] <= $interval * 24 &&
                                    $message['hoursago'] > ($interval - 1) * 24 &&
                                    ($lastwarnago === NULL || $lastwarnago > 24)
                                ) {
                                    # We will be reposting within 24 hours, and we've either not sent a warning, or the last one was
                                    # an old one (probably from the previous repost).
                                    if (!$message['lastautopostwarning'] || ($lastwarnago > 24 * 60 * 60)) {
                                        # And we haven't sent a warning yet.
                                        $this->dbhm->preExec("UPDATE messages_groups SET lastautopostwarning = NOW() WHERE msgid = ?;", [$message['msgid']]);
                                        $warncount++;

                                        $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);
                                        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
                                        $g = new Group($this->dbhr, $this->dbhm, $message['groupid']);
                                        $gatts = $g->getPublic();

                                        if ($u->getId()) {
                                            $to = $u->getEmailPreferred();
                                            $subj = $m->getSubject();

                                            # Remove any group tag.
                                            $subj = trim(preg_replace('/^\[.*?\](.*)/', "$1", $subj));

                                            $completed = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/completed", User::SRC_REPOST_WARNING);
                                            $withdraw = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/withdraw", User::SRC_REPOST_WARNING);
                                            $othertype = $m->getType() == Message::TYPE_OFFER ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
                                            $text = "We will automatically repost your message $subj soon, so that more people will see it.  If you don't want us to do that, please go to $completed to mark as $othertype or $withdraw to withdraw it.";
                                            $html = autorepost_warning(USER_SITE,
                                                USERLOGO,
                                                $subj,
                                                $u->getName(),
                                                $to,
                                                $othertype,
                                                $completed,
                                                $withdraw
                                            );

                                            list ($transport, $mailer) = getMailer();

                                            if (Swift_Validate::email($to)) {
                                                $message = Swift_Message::newInstance()
                                                    ->setSubject("Re: " . $subj)
                                                    ->setFrom([$g->getAutoEmail() => $gatts['namedisplay']])
                                                    ->setReplyTo([$g->getModsEmail() => $gatts['namedisplay']])
                                                    ->setTo($to)
                                                    ->setBody($text);

                                                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                                # Outlook.
                                                $htmlPart = Swift_MimePart::newInstance();
                                                $htmlPart->setCharset('utf-8');
                                                $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                                $htmlPart->setContentType('text/html');
                                                $htmlPart->setBody($html);
                                                $message->attach($htmlPart);

                                                $mailer->send($message);
                                            }
                                        }
                                    }
                                } else if ($message['hoursago'] > $interval * 24) {
                                    # We can autorepost this one.
                                    $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);
                                    error_log($g->getPrivate('nameshort') . " #{$message['msgid']} " . $m->getFromaddr() . " " . $m->getSubject() . " repost due");
                                    $m->autoRepost($message['autoreposts'] + 1, $reposts['max']);

                                    $count++;
                                }
                            }
                        }
                    }
                }
            }
        }

        return([$count, $warncount]);
    }

    public function chaseUp($type, $mindate, $groupid = NULL) {
        $count = 0;
        $groupq = $groupid ? " AND id = $groupid " : "";

        # Randomise the order in case the script gets killed or something - gives all groups a chance.
        $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE type = ? $groupq ORDER BY RAND();", [ $type ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['id']);
            $reposts = $g->getSetting('reposts', [ 'offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);

            # We want approved messages which haven't got an outcome, i.e. aren't TAKEN/RECEIVED, which don't have
            # some other outcome (e.g. withdrawn), aren't promised, have any replies and which we originally sent.
            #
            # The sending user must also still be a member of the group.
            #
            # Using UNION means we can be more efficiently indexed.
            $sql = "SELECT messages_groups.msgid, messages_groups.groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago, lastchaseup, messages.type, messages.fromaddr FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid INNER JOIN memberships ON memberships.userid = messages.fromuser AND memberships.groupid = messages_groups.groupid LEFT OUTER JOIN messages_related ON id1 = messages.id LEFT OUTER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid LEFT OUTER JOIN messages_promises ON messages_promises.msgid = messages.id INNER JOIN chat_messages ON messages.id = chat_messages.refmsgid WHERE messages_groups.arrival > ? AND messages_groups.groupid = ? AND messages_groups.collection = 'Approved' AND messages_related.id1 IS NULL AND messages_outcomes.msgid IS NULL AND messages_promises.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND sourceheader IN ('Platform', 'FDv2') AND messages.deleted IS NULL
                    UNION SELECT messages_groups.msgid, messages_groups.groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago, lastchaseup, messages.type, messages.fromaddr FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid INNER JOIN memberships ON memberships.userid = messages.fromuser AND memberships.groupid = messages_groups.groupid LEFT OUTER JOIN messages_related ON id2 = messages.id LEFT OUTER JOIN messages_outcomes ON messages.id = messages_outcomes.msgid LEFT OUTER JOIN messages_promises ON messages_promises.msgid = messages.id INNER JOIN chat_messages ON messages.id = chat_messages.refmsgid WHERE messages_groups.arrival > ? AND messages_groups.groupid = ? AND messages_groups.collection = 'Approved' AND messages_related.id1 IS NULL AND messages_outcomes.msgid IS NULL AND messages_promises.msgid IS NULL AND messages.type IN ('Offer', 'Wanted') AND sourceheader IN ('Platform', 'FDv2') AND messages.deleted IS NULL;";
            #error_log("$sql, $mindate, {$group['id']}");
            $messages = $this->dbhr->preQuery($sql, [
                $mindate,
                $group['id'],
                $mindate,
                $group['id']
            ]);

            $now = time();

            foreach ($messages as $message) {
                if (ourDomain($message['fromaddr'])) {
                    # Find the last reply.
                    $m = new Message($this->dbhr, $this->dbhm, $message['msgid']);

                    if ($m->canChaseup()) {
                        $sql = "SELECT MAX(date) AS latest FROM chat_messages WHERE chatid IN (SELECT chatid FROM chat_messages WHERE refmsgid = ?);";
                        $replies = $this->dbhr->preQuery($sql, [ $message['msgid'] ]);
                        $lastreply = $replies[0]['latest'];
                        $age = ($now - strtotime($lastreply)) / (60 * 60);
                        $interval = array_key_exists('chaseups', $reposts) ? $reposts['chaseups'] : 2;
                        error_log("Consider chaseup $age vs $interval");

                        if ($interval > 0 && $age > $interval * 24) {
                            # We can chase up.
                            $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
                            $g = new Group($this->dbhr, $this->dbhm, $message['groupid']);
                            $gatts = $g->getPublic();

                            if ($u->getId() && $m->canRepost()) {
                                error_log($g->getPrivate('nameshort') . " #{$message['msgid']} " . $m->getFromaddr() . " " . $m->getSubject() . " chaseup due");
                                $count++;
                                $this->dbhm->preExec("UPDATE messages_groups SET lastchaseup = NOW() WHERE msgid = ?;", [$message['msgid']]);

                                $to = $u->getEmailPreferred();
                                $subj = $m->getSubject();

                                # Remove any group tag.
                                $subj = trim(preg_replace('/^\[.*?\](.*)/', "$1", $subj));

                                $completed = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/completed", User::SRC_CHASEUP);
                                $withdraw = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/withdraw", User::SRC_CHASEUP);
                                $repost = $u->loginLink(USER_SITE, $u->getId(), "/mypost/{$message['msgid']}/repost", User::SRC_CHASEUP);
                                $othertype = $m->getType() == Message::TYPE_OFFER ? Message::OUTCOME_TAKEN : Message::OUTCOME_RECEIVED;
                                $text = "Can you let us know what happened with this?  Click $repost to post it again, or $completed to mark as $othertype, or $withdraw to withdraw it.  Thanks.";
                                $html = chaseup(USER_SITE,
                                    USERLOGO,
                                    $subj,
                                    $u->getName(),
                                    $to,
                                    $othertype,
                                    $repost,
                                    $completed,
                                    $withdraw
                                );

                                list ($transport, $mailer) = getMailer();

                                if (Swift_Validate::email($to)) {
                                    $message = Swift_Message::newInstance()
                                        ->setSubject("Re: " . $subj)
                                        ->setFrom([$g->getAutoEmail() => $gatts['namedisplay']])
                                        ->setReplyTo([$g->getModsEmail() => $gatts['namedisplay']])
                                        ->setTo($to)
                                        ->setBody($text);

                                    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                                    # Outlook.
                                    $htmlPart = Swift_MimePart::newInstance();
                                    $htmlPart->setCharset('utf-8');
                                    $htmlPart->setEncoder(new Swift_Mime_ContentEncoder_Base64ContentEncoder);
                                    $htmlPart->setContentType('text/html');
                                    $htmlPart->setBody($html);
                                    $message->attach($htmlPart);

                                    $mailer->send($message);
                                }
                            }
                        }
                    }
                }
            }
        }

        return($count);
    }

    public function processIntendedOutcomes($msgid = NULL) {
        $count = 0;

        # If someone responded to a chaseup mail, but didn't complete the process in half an hour, we do it for them.
        #
        # This is quite common, and helps get more activity even from members who are put to shame by goldfish.
        $msgq = $msgid ? " AND msgid = $msgid " : "";
        $intendeds = $this->dbhr->preQuery("SELECT * FROM messages_outcomes_intended WHERE TIMESTAMPDIFF(MINUTE, timestamp, NOW()) > 30 $msgq;");
        foreach ($intendeds as $intended) {
            $m = new Message($this->dbhr, $this->dbhm, $intended['msgid']);

            if (!$m->hasOutcome()) {
                switch ($intended['outcome']) {
                    case 'Taken':
                        $m->mark(Message::OUTCOME_TAKEN, NULL, NULL, NULL);
                        $count++;
                        break;
                    case 'Received':
                        $m->mark(Message::OUTCOME_RECEIVED, NULL, NULL, NULL);
                        $count++;
                        break;
                    case 'Withdrawn':
                        $m->withdraw(NULL, NULL);
                        $count++;
                        break;
                    case 'Repost':
                        # We might get an intended outcome of repost multiple times if they click on the reminder
                        # multiple times with more than 30 minutes in between.  So we shouldn't repost if the
                        # message is not currently eligible to be reposted.
                        $atts = $m->getPublic(FALSE, FALSE, FALSE);
                        if ($atts['canrepost']) {
                            $m->repost();
                            $count++;
                        }
                        break;
                }
            }
        }

        return($count);
    }

    public function canRepost() {
        $ret = FALSE;
        $groups = $this->dbhr->preQuery("SELECT groupid, TIMESTAMPDIFF(HOUR, arrival, NOW()) AS hoursago FROM messages_groups WHERE msgid = ?;", [ $this->id ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
            $interval = $this->getType() == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];

            if ($group['hoursago'] > $interval * 24) {
                $ret = TRUE;
            }
        }

        return($ret);
    }

    public function canChaseup() {
        $ret = FALSE;
        $groups = $this->dbhr->preQuery("SELECT groupid, lastchaseup FROM messages_groups WHERE msgid = ?;", [ $this->id ]);

        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['groupid']);
            $reposts = $g->getSetting('reposts', ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5]);
            $interval = $this->getType() == Message::TYPE_OFFER ? $reposts['offer'] : $reposts['wanted'];
            $interval = max($interval, (array_key_exists('chaseups', $reposts) ? $reposts['chaseups'] : 2) * 24);

            $ret = TRUE;

            if ($group['lastchaseup']) {
                $age = (time() - strtotime($group['lastchaseup'])) / (60 * 60);
                $ret = $age > $interval * 24;
            }
        }

        return($ret);
    }

    public function repost() {
        # Make sure we don't keep doing this.
        $this->dbhm->preExec("DELETE FROM messages_outcomes_intended WHERE msgid = ?;", [ $this-> id ]);

        # All we need to do to repost is update the arrival time - that will cause the message to appear on the site
        # near the top, and get mailed out again.
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = NOW() WHERE msgid = ?;", [ $this->id ]);

        # ...and update the search index.
        $this->s->bump($this->id, time());

        # Record that we've done this.
        $groups = $this->getGroups();
        foreach ($groups as $groupid) {
            $sql = "INSERT INTO messages_postings (msgid, groupid, repost, autorepost) VALUES(?,?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $groupid,
                1,
                0
            ]);
        }
    }

    public function autoRepost($reposts, $max) {
        # All we need to do to repost is update the arrival time - that will cause the message to appear on the site
        # near the top, and get mailed out again.
        #
        # Don't resend to Yahoo - the complexities of trying to keep the single message we have in sync
        # with multiple copies on Yahoo are just too horrible to be worth trying to do.
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = NOW(), autoreposts = autoreposts + 1 WHERE msgid = ?;", [ $this->id ]);

        # ...and update the search index.
        $this->s->bump($this->id, time());

        # Record that we've done this.
        $groups = $this->getGroups();
        foreach ($groups as $groupid) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_AUTO_REPOSTED,
                'msgid' => $this->id,
                'groupid' => $groupid,
                'user' => $this->getFromuser(),
                'text' => "$reposts / $max"
            ]);

            $sql = "INSERT INTO messages_postings (msgid, groupid, repost, autorepost) VALUES(?,?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $groupid,
                1,
                1
            ]);
        }
    }

    public function isBounce()
    {
        $bounce = FALSE;

        foreach ($this->bounce_subjects as $subj) {
            if (stripos($this->subject, $subj) !== FALSE) {
                $bounce = TRUE;
            }
        }

        if (!$bounce) {
            foreach ($this->bounce_bodies as $body) {
                if (stripos($this->message, $body) !== FALSE) {
                    $bounce = TRUE;
                }
            }
        }

        return ($bounce);
    }
    
    public function isAutoreply()
    {
        $autoreply = FALSE;

        foreach ($this->autoreply_subjects as $subj) {
            if (stripos($this->subject, $subj) !== FALSE) {
                $autoreply = TRUE;
            }
        }

        if (!$autoreply) {
            foreach ($this->autoreply_bodies as $body) {
                if (stripos($this->message, $body) !== FALSE) {
                    $autoreply = TRUE;
                }
            }
        }

        if (!$autoreply) {
            foreach ($this->autoreply_text_start as $body) {
                if (stripos($this->textbody, $body) === 0) {
                    $autoreply = TRUE;
                }
            }
        }

        if (!$autoreply) {
            $auto = $this->getHeader('auto-submitted');
            if ($auto && stripos($auto, 'auto-') !== FALSE) {
                $autoreply = TRUE;
            }
        }

        if (!$autoreply && stripos($this->getFromaddr(), 'notify@yahoogroups.com') !== FALSE) {
            # Some Yahoo system message we don't want to see.
            $autoreply = TRUE;
        }

        return ($autoreply);
    }

    public function hasOutcome() {
        $sql = "SELECT * FROM messages_outcomes WHERE msgid = ? ORDER BY id DESC;";
        $outcomes = $this->dbhr->preQuery($sql, [ $this->id ]);
        return(count($outcomes) > 0 ? $outcomes[0]['outcome'] : NULL);
    }

    public function promisedTo() {
        $sql = "SELECT * FROM messages_promises WHERE msgid = ?;";
        $promises = $this->dbhr->preQuery($sql, [ $this->id ]);
        return(count($promises) > 0 ? $promises[0]['userid'] : NULL);
    }

    public function isEdited() {
        return($this->editedby !== NULL);
    }

    public function quickDelete($schema, $id) {
        # This bypasses referential integrity checks, but honours them by querying the schema.  It's intended for
        # when we are deleting large numbers of messages and want to avoid blocking the server because of
        # cascaded deletes.  This is particularly true on a Percona cluster where a stream of DELETE ops tends
        # to cripple things.
        $this->dbhm->preExec("SET FOREIGN_KEY_CHECKS=0;", NULL, FALSE);
        $this->dbhr->preQuery("USE iznik;");

        foreach ($schema as $table) {
            $todel = $this->dbhm->preQuery("SELECT {$table['COLUMN_NAME']} FROM {$table['TABLE_NAME']} WHERE {$table['COLUMN_NAME']} = $id", NULL, FALSE, FALSE);
            #error_log("$id ..." . count($todel) . " from {$table['TABLE_NAME']}");
            if (count($todel) > 0) {
                $this->dbhm->preExec("DELETE FROM {$table['TABLE_NAME']} WHERE {$table['COLUMN_NAME']} = $id", NULL, FALSE);
            }
        }
        
        $this->dbhm->preExec("DELETE FROM messages WHERE id = $id;", NULL, FALSE);
        $this->dbhm->preExec("SET FOREIGN_KEY_CHECKS=1;", NULL, FALSE);
    }
}
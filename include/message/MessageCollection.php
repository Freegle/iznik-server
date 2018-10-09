<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

class MessageCollection
{
    # These match the collection enumeration.
    const INCOMING = 'Incoming';
    const APPROVED = 'Approved';
    const PENDING = 'Pending';
    const SPAM = 'Spam';
    const DRAFT = 'Draft';
    const QUEUED_YAHOO_USER = 'QueuedYahooUser'; # Awaiting a user on the Yahoo group before it can be sent
    const QUEUED_USER = 'QueuedUser'; # Awaiting a user on a native group before it can be sent
    const REJECTED = 'Rejected'; # Rejected by mod; user can see and resend.
    const ALLUSER = 'AllUser';
    const CHAT = 'Chat'; # Chat message
    const OWNPOSTS = 120;

    /** @var  $dbhr LoggedPDO */
    public $dbhr;
    /** @var  $dbhm LoggedPDO */
    public $dbhm;

    private $collection;

    private $userlist = [];
    private $locationlist = [];

    private $allUser = FALSE;

    /**
     * @return null
     */
    public function getCollection()
    {
        return $this->collection;
    }

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $collection = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        switch ($collection) {
            case MessageCollection::APPROVED:
            case MessageCollection::PENDING:
            case MessageCollection::SPAM:
            case MessageCollection::DRAFT:
            case MessageCollection::QUEUED_YAHOO_USER:
            case MessageCollection::QUEUED_USER:
            case MessageCollection::REJECTED:
                $this->collection = [$collection];
                break;
            case MessageCollection::ALLUSER:
                # The ones users should be able to see, e.g. on My Posts.
                $this->allUser = TRUE;

                $this->collection = [
                    MessageCollection::DRAFT,
                    MessageCollection::APPROVED,
                    MessageCollection::PENDING,
                    MessageCollection::REJECTED,
                    MessageCollection::QUEUED_YAHOO_USER,
                    MessageCollection::QUEUED_USER
                ];
                break;
            default:
                $this->collection = [];
        }
    }

    function get(&$ctx, $limit, $groupids, $userids = NULL, $types = NULL, $age = NULL, $hasoutcome = NULL, $summary = FALSE)
    {
        do {
            $msgids = [];

            if (in_array(MessageCollection::DRAFT, $this->collection)) {
                # Draft messages are handled differently, as they're not attached to any group.  Only show
                # recent drafts - if they've not completed within a reasonable time they're probably stuck.
                $mysqltime = date("Y-m-d", strtotime("Midnight 7 days ago"));
                $oldest = " AND timestamp >= '$mysqltime' ";

                $me = whoAmI($this->dbhr, $this->dbhm);
                $userids = $userids ? $userids : ($me ? [$me->getId()] : NULL);

                $summjoin = $summary ? ", (SELECT messages_attachments.id FROM messages_attachments WHERE msgid = messages.id ORDER BY messages_attachments.id LIMIT 1) AS attachmentid, (SELECT messages_outcomes.id FROM messages_outcomes WHERE msgid = messages.id ORDER BY id DESC LIMIT 1) AS outcomeid": '';

                $sql = $userids ? ("SELECT msgid, messages.type AS msgtype, fromuser $summjoin FROM messages_drafts INNER JOIN messages ON messages_drafts.msgid = messages.id WHERE (session = ? OR userid IN (" . implode(',', $userids) . ")) $oldest;") : "SELECT msgid, messages.type AS msgtype, fromuser $summjoin FROM messages_drafts INNER JOIN messages ON messages_drafts.msgid = messages.id  WHERE session = ? $oldest;";
                $msgs = $this->dbhr->preQuery($sql, [
                    session_id()
                ]);

                foreach ($msgs as $msg) {
                    $msgids[] = [
                        'id' => $msg['msgid'],
                        'msgtype' => $msg['msgtype'],
                        'fromuser' => $msg['fromuser'],
                        'outcomeid' => $msg['outcomeid'],
                        'collection' => MessageCollection::DRAFT,
                        'groupid' => NULL,
                        'arrival' => $msg['arrival'],
                        'attachmentid' => $msg['attachmentid']
                    ];
                }
            }

            $collection = array_filter($this->collection, function ($val) {
                return ($val != MessageCollection::DRAFT);
            });

            if (count($collection) > 0) {
                $typeq = $types ? (" AND `type` IN (" . implode(',', $types) . ") ") : '';

                # At the moment we only support ordering by arrival DESC.  Note that arrival can either be when this
                # message arrived for the very first time, or when it was reposted.
                $date = ($ctx == NULL || !pres('Date', $ctx)) ? NULL : $this->dbhr->quote(date("Y-m-d H:i:s", intval($ctx['Date'])));
                $dateq = !$date ? ' 1=1 ' : (" (messages_groups.arrival < $date OR messages_groups.arrival = $date AND messages_groups.msgid < " . $this->dbhr->quote($ctx['id']) . ") ");
                $oldest = '';

                if (in_array(MessageCollection::SPAM, $collection)) {
                    # We only want to show spam messages upto 31 days old to avoid seeing too many, especially on first use.
                    # See also Group.
                    #
                    # This fits with Yahoo's policy on deleting pending activity.
                    #
                    # This code assumes that if we're called to retrieve SPAM, it's the only collection.  That's true at
                    # the moment as the only use of multiple collection values is via ALLUSER, which doesn't include SPAM.
                    $mysqltime = date("Y-m-d", strtotime("Midnight 31 days ago"));
                    $oldest = " AND messages_groups.arrival >= '$mysqltime' ";
                } else if ($age !== NULL) {
                    $mysqltime = date("Y-m-d", strtotime("Midnight $age days ago"));
                    $oldest = " AND messages_groups.arrival >= '$mysqltime' ";
                }

                # We might be looking for posts with no outcome.
                $outcomeq1 = $hasoutcome !== NULL ? " LEFT JOIN messages_outcomes ON messages_outcomes.id = messages.id " : '';
                $outcomeq2 = $hasoutcome !== NULL ? " AND messages_outcomes.msgid IS NULL " : '';

                # We might be getting a summary, in which case we want to get an attachment in the same query
                # for performance reasons.
                # TODO This doesn't work for messages on multiple groups.
                $summjoin = $summary ? ", messages_groups.msgtype, messages.fromuser, (SELECT groupid FROM messages_groups WHERE msgid = messages.id) AS groupid, (SELECT messages_attachments.id FROM messages_attachments WHERE msgid = messages.id ORDER BY messages_attachments.id LIMIT 1) AS attachmentid, (SELECT messages_outcomes.id FROM messages_outcomes WHERE msgid = messages.id ORDER BY id DESC LIMIT 1) AS outcomeid": '';

                # We may have some groups to filter by.
                $groupq = $groupids ? (" AND groupid IN (" . implode(',', $groupids) . ") ") : '';

                # We have a complicated set of different queries we can do.  This is because we want to make sure that
                # the query is as fast as possible, which means:
                # - access as few tables as we need to
                # - use multicolumn indexes
                $collectionq = " AND collection IN ('" . implode("','", $collection) . "') ";
                if ($userids) {
                    # We only query on a small set of userids, so it's more efficient to get the list of messages from them
                    # first.
                    $seltab = "(SELECT id, arrival, fromuser, deleted, `type` FROM messages WHERE fromuser IN (" . implode(',', $userids) . ")) messages";
                    $sql = "SELECT messages_groups.msgid AS id, messages.arrival, messages_groups.collection $summjoin FROM messages_groups INNER JOIN $seltab ON messages_groups.msgid = messages.id AND messages.deleted IS NULL $outcomeq1 WHERE $dateq $oldest $typeq $groupq $collectionq AND messages_groups.deleted = 0 $outcomeq2 ORDER BY messages_groups.arrival DESC LIMIT $limit";
                } else if (count($groupids) > 0) {
                    # The messages_groups table has a multi-column index which makes it quick to find the relevant messages.
                    $typeq = $types ? (" AND `msgtype` IN (" . implode(',', $types) . ") ") : '';
                    $sql = "SELECT msgid as id, messages_groups.arrival, messages_groups.collection $summjoin FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid WHERE 1=1 $groupq $collectionq AND messages_groups.deleted = 0 AND $dateq $oldest $typeq ORDER BY arrival DESC LIMIT $limit;";
                } else {
                    # We are not searching within a specific group, so we have no choice but to do a larger join.
                    $sql = "SELECT msgid AS id, messages.arrival, messages_groups.collection $summjoin FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id AND messages.deleted IS NULL WHERE $dateq $oldest $typeq $collectionq AND messages_groups.deleted = 0 ORDER BY messages_groups.arrival DESC LIMIT $limit";
                }

                #error_log("Messages get $sql");

                $msglist = $this->dbhr->preQuery($sql);

                # Get an array of just the message ids.  Save off context for next time.
                $ctx = ['Date' => NULL, 'id' => PHP_INT_MAX];

                foreach ($msglist as $msg) {
                    $msgids[] = [
                        'id' => $msg['id'],
                        'msgtype' => presdef('msgtype', $msg, NULL),
                        'fromuser' => presdef('fromuser', $msg, NULL),
                        'outcomeid' => presdef('outcomeid', $msg, NULL),
                        'groupid' => $msg['groupid'],
                        'arrival' => $msg['arrival'],
                        'collection' => $msg['collection'],
                        'attachmentid' => $msg['attachmentid']
                    ];

                    $thisepoch = strtotime($msg['arrival']);

                    if ($ctx['Date'] == NULL || $thisepoch < $ctx['Date']) {
                        $ctx['Date'] = $thisepoch;
                    }

                    $ctx['id'] = min($msg['id'], $ctx['id']);
                }
            }

            list($groups, $msgs) = $this->fillIn($msgids, $limit, NULL, $summary);
//            error_log("Filled in " . count($msgs) . " from " . count($msgids));

            # We might have excluded all the messages we found; if so, keep going.
        } while (count($msgids) > 0 && count($msgs) == 0);

        return ([$groups, $msgs]);
    }

    public function fillIn($msglist, $limit, $messagetype, $summary)
    {
        $msgs = [];
        $groups = [];
        $roles = [];

        # Don't return the message attribute as it will be huge.  They can get that via a call to the
        # message API call.
        foreach ($msglist as $msg) {
            if ($summary) {
                # We have fetched all the info we will need; set up a message object using that.  In the summary
                # case, this saves any DB ops in filling in this message, while still using the logic within Message
                # (e.g. for access).  More code complexity, but much better performance.
                $m = new Message($this->dbhr, $this->dbhm, $msg['id'], [
                    'msgtype' => $msg['msgtype'],
                    'fromuser' => $msg['fromuser'],
                ]);

                if (pres('groupid', $msg)) {
                    # TODO If we support messages on multiple groups then this needs reworking.
                    $m->setGroups([
                        [
                            'id' => $msg['groupid'],
                            'arrival' => ISODate($msg['arrival']),
                            'collection' => $msg['collection']
                        ]
                    ]);
                }

                if (pres('outcomeid', $msg)) {
                    $m->setOutcomes([ $msg['outcomeid'] ]);
                }

                $m->setAttachments([]);

                if (pres('attachmentid', $msg)) {
                    $a = new Attachment($this->dbhr, $this->dbhm);

                    $m->setAttachments([
                        [
                            'id' => $msg['attachmentid'],
                            'path' => $a->getpath(false, $msg['attachmentid']),
                            'paththumb' => $a->getpath(true, $msg['attachmentid'])
                        ]
                    ]);
                }

                # TODO getRoleForMessage still has DB ops.
                # TODO canSee still has DB ops
            } else {
                # We will fetch and later return all the message info, which is slower.
                $m = new Message($this->dbhr, $this->dbhm, $msg['id']);
            }

            $public = $m->getPublic(MODTOOLS, TRUE, FALSE, $this->userlist, $this->locationlist, $summary);

            $type = $m->getType();
            if (!$messagetype || $type == $messagetype) {
                $role = NULL;

                $thisgroups = $m->getGroups(TRUE);

                foreach ($thisgroups as $groupid) {
                    #error_log("Got role? $groupid");
                    if (array_key_exists($groupid, $roles)) {
                        $role = $roles[$groupid];
                        #error_log("Saved roll get $role");
                    }
                }

                if (!$role) {
                    list ($role, $gid) = $m->getRoleForMessage(FALSE);
                    #error_log("Got role $role for $gid");

                    if ($gid) {
                        # Save the role on this group for later messages.
                        $roles[$gid] = $role;
                    }
                }

                $cansee = $m->canSee($public);
                $coll = presdef('collection', $msg, MessageCollection::APPROVED);

                if ($cansee && $coll != MessageCollection::DRAFT) {
                    # Make sure we only return this if it's on a group.
                    $cansee = FALSE;

                    foreach ($thisgroups as $groupid) {
                        $cansee = TRUE;

                        if (!$summary) {
                            # No need to return the groups for summary case.
                            if (!array_key_exists($groupid, $groups)) {
                                $g = Group::get($this->dbhr, $this->dbhm, $groupid);
                                $groups[$groupid] = $g->getPublic();
                            }
                        }
                    }
                }

                if ($cansee) {
                    switch ($coll) {
                        case MessageCollection::DRAFT:
                        case MessageCollection::QUEUED_YAHOO_USER:
                        case MessageCollection::QUEUED_USER:
                            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                                # Only visible to moderators or owners, or self (which returns a role of moderator).
                                $n = $public;
                                unset($n['message']);
                                $msgs[] = $n;
                                $limit--;
                            }
                            break;
                        case MessageCollection::APPROVED:
                            $n = $public;
                            unset($n['message']);
                            $n['matchedon'] = presdef('matchedon', $msg, NULL);
                            $msgs[] = $n;
                            $limit--;
                            break;
                        case MessageCollection::PENDING:
                        case MessageCollection::REJECTED:
                            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                                # Only visible to moderators or owners
                                $n = $public;
                                unset($n['message']);
                                $n['matchedon'] = presdef('matchedon', $msg, NULL);
                                $msgs[] = $n;
                                $limit--;
                            }
                            break;
                        case MessageCollection::SPAM:
                            if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
                                # Only visible to moderators or owners
                                $n = $public;
                                unset($n['message']);
                                $n['matchedon'] = presdef('matchedon', $msg, NULL);
                                $msgs[] = $n;
                                $limit--;
                            }
                            break;
                    }
                }
            }

            if ($limit <= 0) {
                break;
            }
        }

        return ([$groups, $msgs]);
    }

    function findByYahooApprovedId($groupid, $id)
    {
        # We need to include deleted messages, otherwise we could delete something and then recreate it during a
        # sync, before our delete had hit Yahoo.
        $sql = "SELECT msgid FROM messages_groups WHERE groupid = ? AND yahooapprovedid = ?;";
        $msglist = $this->dbhr->preQuery($sql, [
            $groupid,
            $id
        ]);

        if (count($msglist) == 1) {
            return ($msglist[0]['msgid']);
        } else {
            return NULL;
        }
    }

    function findByYahooPendingId($groupid, $id)
    {
        # We need to include deleted messages, otherwise we could delete something and then recreate it during a
        # sync, before our delete had hit Yahoo.
        $sql = "SELECT msgid FROM messages_groups WHERE groupid = ? AND yahoopendingid = ? AND collection = 'Pending';";
        $msglist = $this->dbhr->preQuery($sql, [
            $groupid,
            $id
        ]);

        if (count($msglist) == 1) {
            return ($msglist[0]['msgid']);
        } else {
            return NULL;
        }
    }

    function getRecentMessages($type = Group::GROUP_FREEGLE)
    {
        $groupq = $type ? " AND groups.type = '$type' " : "";
        $mysqltime = date("Y-m-d H:i:s", strtotime('30 minutes ago'));
        $messages = $this->dbhr->preQuery("SELECT messages.id, messages_groups.arrival, messages_groups.groupid, messages.subject FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid INNER JOIN groups ON messages_groups.groupid = groups.id INNER JOIN users ON messages.fromuser = users.id WHERE messages_groups.arrival > ? AND collection = ? AND publishconsent = 1 $groupq ORDER BY messages_groups.arrival ASC;", [
            $mysqltime,
            MessageCollection::APPROVED
        ]);

        $ret = [];

        $last = NULL;
        foreach ($messages as $message) {
            $g = Group::get($this->dbhr, $this->dbhm, $message['groupid']);
            $namedisplay = $g->getPrivate('namefull') ? $g->getPrivate('namefull') : $g->getPrivate('nameshort');
            $arrival = strtotime($message['arrival']);
            $delta = $last !== NULL ? ($arrival - $last) : 0;
            $last = $arrival;

            $ret[] = [
                'id' => $message['id'],
                'message' => [
                    'id' => $message['id'],
                    'subject' => $message['subject'],
                    'arrival' => ISODate($message['arrival']),
                    'delta' => $delta,
                ],
                'group' => [
                    'id' => $g->getId(),
                    'nameshort' => $g->getPrivate('nameshort'),
                    'namedisplay' => $namedisplay,
                    'lat' => $g->getPrivate('lat'),
                    'lng' => $g->getPrivate('lng')
                ]
            ];
        }

        return ($ret);
    }

    function getChanges($since)
    {
        $mysqltime = date("Y-m-d H:i:s", strtotime($since));

        # We want messages which have been deleted, or had an outcome.
        $changes = $this->dbhm->preQuery("SELECT id, deleted AS timestamp, 'Deleted' AS `type` FROM messages WHERE deleted > ? UNION SELECT msgid AS id, timestamp, outcome AS `type` FROM messages_outcomes WHERE timestamp > ?;", [
            $mysqltime,
            $mysqltime
        ]);

        foreach ($changes as &$change) {
            $change['timestamp'] = ISODate($change['timestamp']);
        }

        return ($changes);
    }
}
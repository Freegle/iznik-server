<?php
namespace Freegle\Iznik;

class Group extends Entity
{
    # We have a cache of groups, because we access groups a _lot_, and this can speed things up significantly by avoiding
    # hitting the DB.  This is only preserved within this process.
    static $processCache = [];
    static $processCacheDeleted = [];
    const PROCESS_CACHE_SIZE = 100;

    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'nameshort', 'namefull', 'nameabbr', 'namedisplay', 'settings', 'type', 'region', 'logo', 'publish',
        'onhere', 'ontn', 'membercount', 'modcount', 'lat', 'lng',
        'profile', 'cover', 'onmap', 'tagline', 'legacyid', 'external', 'welcomemail', 'description',
        'contactmail', 'fundingtarget', 'affiliationconfirmed', 'affiliationconfirmedby', 'mentored', 'privategroup', 'defaultlocation',
        'moderationstatus', 'maxagetoshow', 'nearbygroups', 'microvolunteering', 'microvolunteeringoptions', 'autofunctionoverride', 'overridemoderation', 'precovidmoderated');

    const GROUP_REUSE = 'Reuse';
    const GROUP_FREEGLE = 'Freegle';
    const GROUP_OTHER = 'Other';
    const GROUP_UT = 'UnitTest';

    const POSTING_MODERATED = 'MODERATED';
    const POSTING_PROHIBITED = 'PROHIBITED';
    const POSTING_DEFAULT = 'DEFAULT';
    const POSTING_UNMODERATED = 'UNMODERATED';

    const OVERRIDE_MODERATION_NONE = 'None';
    const OVERRIDE_MODERATION_ALL = 'ModerateAll';

    const FILTER_NONE = 0;
    const FILTER_WITHCOMMENTS = 1;
    const FILTER_MODERATORS = 2;
    const FILTER_BOUNCING = 3;
    const FILTER_MOSTACTIVE = 4;
    const FILTER_BANNED = 5;

    /** @var  $log Log */
    private $log;

    public $defaultSettings;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $atts = NULL)
    {
        # We need special SQL to pick up postvisibility in text.
        $sql = "SELECT `groups`.*, ST_AsText(postvisibility) AS postvisibility FROM `groups` WHERE id = ?";

        if ($atts) {
            # We've been passed all the atts we need to construct the group
            $this->fetch($dbhr, $dbhm, $id, 'groups', 'group', $this->publicatts, $atts, FALSE, $sql);
        } else {
            $this->fetch($dbhr, $dbhm, $id, 'groups', 'group', $this->publicatts, NULL, FALSE, $sql);

            if ($id && !$this->id) {
                # We were passed an id, but didn't find the group.  See if the id is a legacyid.
                #
                # This assumes that the legacy and current ids don't clash.  Which they don't.  So that's a good assumption.
                $groups = $this->dbhr->preQuery("SELECT id FROM `groups` WHERE legacyid = ?;", [ $id ]);
                foreach ($groups as $group) {
                    $this->fetch($dbhr, $dbhm, $group['id'], 'groups', 'group', $this->publicatts, NULL, FALSE, $sql);
                }
            }
        }

        $this->setDefaults();
    }

    public function setDefaults() {
        $this->defaultSettings = [
            'showchat' => 1,
            'communityevents' => 1,
            'volunteering' => 1,
            'stories' => 1,
            'includearea' => 1,
            'includepc' => 1,
            'moderated' => 0,
            'allowedits' => [
                'moderated' => 1,
                'group' => 1
            ],
            'autoapprove' => [
                'members' => 0,
                'messages' => 0
            ], 'duplicates' => [
                'check' => 1,
                'offer' => 14,
                'taken' => 14,
                'wanted' => 14,
                'received' => 14
            ], 'spammers' => [
                'chatreview' => 1,
                'messagereview' => 1
            ], 'joiners' => [
                'check' => 1,
                'threshold' => 5
            ], 'keywords' => [
                'OFFER' => 'OFFER',
                'TAKEN' => 'TAKEN',
                'WANTED' => 'WANTED',
                'RECEIVED' => 'RECEIVED'
            ], 'reposts' => [
                'offer' => 3,
                'wanted' => 7,
                'max' => 5,
                'chaseups' => 5
            ],
            'relevant' => 1,
            'newsfeed' => 1,
            'newsletter' => 1,
            'businesscards' => 1,
            'autoadmins' => 1,
            'mentored' => 0,
            'nearbygroups' => 5,
            'showjoin' => 0,
            'engagement' => 1
        ];

        if ($this->id) {
            if (!$this->group['settings'] || strlen($this->group['settings']) == 0) {
                $this->group['settings'] = json_encode($this->defaultSettings);
            }
        }

        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public static function get(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $gsecache = TRUE) {
        if ($id) {
            # We cache the constructed group.
            if ($gsecache && array_key_exists($id, Group::$processCache) && Group::$processCache[$id]->getId() == $id) {
                # We found it.
                #error_log("Found $id in cache");

                # @var Group
                $g = Group::$processCache[$id];

                if (!Group::$processCacheDeleted[$id]) {
                    # And it's not zapped - so we can use it.
                    #error_log("Not zapped");
                    return ($g);
                } else {
                    # It's zapped - so refetch.
                    #error_log("Zapped, refetch " . $id);
                    $g->fetch($g->dbhr, $g->dbhm, $id, 'groups', 'group', $g->publicatts, NULL, FALSE);

                    if (!$g->group['settings'] || strlen($g->group['settings']) == 0) {
                        $g->group['settings'] = json_encode($g->defaultSettings);
                    }

                    Group::$processCache[$id] = $g;
                    Group::$processCacheDeleted[$id] = FALSE;
                    return($g);
                }
            }
        }

        #error_log("$id not in cache");
        $g = new Group($dbhr, $dbhm, $id);

        if ($id && count(Group::$processCache) < Group::PROCESS_CACHE_SIZE) {
            # Store for next time in this process.
            #error_log("store $id in cache");
            Group::$processCache[$id] = $g;
            Group::$processCacheDeleted[$id] = FALSE;
        }

        return($g);
    }

    public static function clearCache($id = NULL) {
        # Remove this group from our process cache.
        #error_log("Clear $id from cache");
        if ($id) {
            Group::$processCacheDeleted[$id] = TRUE;
        } else {
            Group::$processCache = [];
            Group::$processCacheDeleted = [];
        }
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function getDefaults() {
        return($this->defaultSettings);
    }

    public function setPrivate($att, $val) {
        $ret = TRUE;

        if ($att == 'postvisibility') {
            # Check validity of spatial data
            $ret = FALSE;

            try {
                $valid = $this->dbhm->preQuery($this->dbhr->isV8() ? "SELECT ST_IsValid(ST_GeomFromText(?, {$this->dbhr->SRID()})) AS valid;" : "SELECT ST_IsValid(ST_GeomFromText(?)) AS valid;", [
                    $val
                ]);

                foreach ($valid as $v) {
                    if ($v['valid']) {
                        $this->dbhm->preExec("UPDATE `groups` SET postvisibility = ST_GeomFromText(?, {$this->dbhr->SRID()}) WHERE id = ?;", [
                            $val,
                            $this->id
                        ]);

                        $ret = TRUE;
                    }
                }
            } catch(\Exception $e) {
                # Drop through with ret false.
            }
        } else {
            # We override this in order to clear our cache, which would otherwise be out of date.
            parent::setPrivate($att, $val);

            if ($att == 'poly' || $att == 'polyofficial') {
                # Check validity of spatial data
                $ret = FALSE;

                try {
                    $valid = $this->dbhm->preQuery($this->dbhr->isV8() ? "SELECT ST_IsValid(ST_GeomFromText(?, {$this->dbhr->SRID()})) AS valid;" : "SELECT ST_IsValid(ST_GeomFromText(?)) AS valid;", [
                        $val
                    ]);

                    foreach ($valid as $v) {
                        if ($v['valid']) {
                            $this->dbhm->preExec("UPDATE `groups` SET polyindex = ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'), {$this->dbhr->SRID()}) WHERE id = ?;", [
                                $this->id
                            ]);

                            $ret = TRUE;
                        }
                    }
                } catch(\Exception $e) {
                    # Drop through with ret false.
                }
            }
        }

        Group::clearCache($this->id);

        return $ret;
    }

    public function create($shortname, $type) {
        try {
            # Check for duplicate.  Might still occur in a timing window but in that rare case we'll get an exception
            # and catch that, failing the call.
            $groups = $this->dbhm->preQuery("SELECT id FROM `groups` WHERE nameshort = ?;", [ $shortname ]);
            foreach ($groups as $group) {
                return(NULL);
            }

            $rc = $this->dbhm->preExec("INSERT INTO `groups` (nameshort, type, founded, licenserequired, polyindex) VALUES (?, ?, NOW(),?,ST_GeomFromText('POINT(0 0)', {$this->dbhr->SRID()}))", [
                $shortname,
                $type,
                $type != Group::GROUP_FREEGLE ? 0 : 1
            ]);

            $id = $this->dbhm->lastInsertId();

            if ($type == Group::GROUP_FREEGLE) {
                # Also create a shortlink.
                $linkname = str_ireplace('Freegle', '', $shortname);
                $linkname = str_replace('-', '', $linkname);
                $linkname = str_replace('_', '', $linkname);
                $s = new Shortlink($this->dbhr, $this->dbhm);
                $sid = $s->create($linkname, Shortlink::TYPE_GROUP, $id);

                # And a group chat.
                $r = new ChatRoom($this->dbhr, $this->dbhm);
                $r->createGroupChat("$shortname Volunteers", $id, TRUE, TRUE);
                $r->setPrivate('description', "$shortname Volunteers");
            }
        } catch (\Exception $e) {
            error_log("Create group exception " . $e->getMessage());
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'groups', 'group', $this->publicatts);
            $this->log->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => Log::SUBTYPE_CREATED,
                'groupid' => $id,
                'text' => $shortname
            ]);

            return($id);
        } else {
            return(NULL);
        }
    }

    public function getMods() {
        $sql = "SELECT users.id FROM users INNER JOIN memberships ON users.id = memberships.userid AND memberships.groupid = ? AND role IN ('Owner', 'Moderator');";
        $mods = $this->dbhr->preQuery($sql, [ $this->id ]);
        $ret = [];
        foreach ($mods as $mod) {
            $ret[] = $mod['id'];
        }
        return($ret);
    }

    public function getModsEmail() {
        # This is an address used when we are sending to volunteers, or in response to an action by a volunteer.
        if (Utils::pres('contactmail', $this->group)) {
            $ret = $this->group['contactmail'];
        } else {
            $ret = $this->group['nameshort'] . "-volunteers@" . GROUP_DOMAIN;
        }

        return($ret);
    }

    public function getAutoEmail() {
        # This is an address used when we are sending automatic emails for a group.
        if ($this->group['contactmail']) {
            $ret = $this->group['contactmail'];
        } else {
            $ret = $this->group['nameshort'] . "-auto@" . GROUP_DOMAIN;
        }

        return($ret);
    }

    public function getGroupEmail() {
        $ret = $this->group['nameshort'] . '@' . GROUP_DOMAIN;
        return($ret);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM `groups` WHERE id = ?;", [$this->id]);
        if ($rc) {
            $this->log->log([
                'type' => Log::TYPE_GROUP,
                'subtype' => Log::SUBTYPE_DELETED,
                'groupid' => $this->id
            ]);
        }

        return($rc);
    }

    public function findByShortName($name) {
        $groups = $this->dbhr->preQuery("SELECT id FROM `groups` WHERE nameshort LIKE ?;",
            [
                trim($name)
            ]);

        foreach ($groups as $group) {
            return($group['id']);
        }

        return(NULL);
    }

    public function getWorkCounts($mysettings, $groupids) {
        $ret = [];
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        if ($groupids) {
            $groupq = "(" . implode(',', $groupids) . ")";

            $earliestmsg = date("Y-m-d", strtotime(MessageCollection::RECENTPOSTS));
            $eventsqltime = date("Y-m-d H:i:s", time());

            # Exclude messages routed to system, for which there must be a good reason.
            #
            # See also MessageCollection.
            $pendingspamcounts = $this->dbhr->preQuery("SELECT messages_groups.groupid, COUNT(*) AS count, messages_groups.collection, messages.heldby IS NOT NULL AS held FROM messages 
    INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND messages_groups.groupid IN $groupq AND messages_groups.collection IN (?) AND messages_groups.deleted = 0 AND messages.deleted IS NULL AND messages.fromuser IS NOT NULL AND messages_groups.arrival >= '$earliestmsg' AND (messages.lastroute IS NULL OR messages.lastroute != ?) 
    GROUP BY messages_groups.groupid, messages_groups.collection, held;", [
                MessageCollection::PENDING,
                MailRouter::TO_SYSTEM
            ]);

            # No need to check spam_users as those will be auto-removed by the check_spammers job (in earlier times
            # this wasn't the case for all groups).
            $sql = "SELECT memberships.groupid, COUNT(*) AS count, memberships.heldby IS NOT NULL AS held FROM memberships
                    WHERE (reviewrequestedat IS NOT NULL AND (reviewedat IS NULL OR DATE(reviewedat) < CURDATE())) AND groupid IN $groupq
                    GROUP BY memberships.groupid, held;";
            $spammembercounts = $this->dbhr->preQuery($sql, []);

            $pendingeventcounts = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT communityevents.id) AS count FROM communityevents INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid WHERE communityevents_groups.groupid IN $groupq AND communityevents.pending = 1 AND communityevents.deleted = 0 AND end >= ? GROUP BY groupid;", [
                $eventsqltime
            ]);

            $pendingvolunteercounts = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT volunteering.id) AS count FROM volunteering LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid WHERE volunteering_groups.groupid IN $groupq AND volunteering.pending = 1 AND volunteering.deleted = 0 AND volunteering.expired = 0 AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) GROUP BY groupid;", [
                $eventsqltime,
                $eventsqltime
            ]);

            $pendingadmins = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT admins.id) AS count FROM admins WHERE admins.groupid IN $groupq AND admins.complete IS NULL AND admins.pending = 1 AND heldby IS NULL AND admins.created >= '$earliestmsg' GROUP BY groupid;");

            # Related members.
            #
            # Complex query for speed.
            $relatedsql = "SELECT COUNT(*) AS count, groupid FROM (
SELECT user1, memberships.groupid, (SELECT COUNT(*) FROM users_logins WHERE userid = memberships.userid) AS logincount FROM users_related
INNER JOIN memberships ON users_related.user1 = memberships.userid
INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' AND u1.deleted IS NULL 
INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' AND u2.deleted IS NULL 
WHERE
user1 < user2 AND
notified = 0 AND
memberships.groupid IN $groupq 
HAVING logincount > 0
UNION
SELECT user1, memberships.groupid, (SELECT COUNT(*) FROM users_logins WHERE userid = memberships.userid) AS logincount FROM users_related
INNER JOIN memberships ON users_related.user2 = memberships.userid
INNER JOIN users u3 ON users_related.user2 = u3.id AND u3.deleted IS NULL AND u3.systemrole = 'User' AND u3.deleted IS NULL
INNER JOIN users u4 ON users_related.user1 = u4.id AND u4.deleted IS NULL AND u4.systemrole = 'User' AND u4.deleted IS NULL
WHERE
user1 < user2 AND
notified = 0 AND
memberships.groupid IN $groupq 
HAVING logincount > 0 
) t GROUP BY groupid;";
            $relatedmembers = $this->dbhr->preQuery($relatedsql, NULL, FALSE, FALSE);

            # We only want to show edit reviews upto 7 days old - after that assume they're ok.
            #
            # See also MessageCollection.
            $mysqltime = date("Y-m-d", strtotime("Midnight 7 days ago"));
            $editreviewcounts = $this->dbhr->preQuery("SELECT groupid, COUNT(DISTINCT messages_edits.msgid) AS count FROM messages_edits INNER JOIN messages_groups ON messages_edits.msgid = messages_groups.msgid WHERE timestamp > '$mysqltime' AND reviewrequired = 1 AND messages_groups.groupid IN $groupq AND messages_groups.deleted = 0 GROUP BY groupid;");

            # We only want to show happiness upto 31 days old - after that just let it slide.  We're only interested
            # in ones with interesting comments.
            #
            # This code matches the feedback code on the client.
            $mysqltime = date("Y-m-d", strtotime(MessageCollection::RECENTPOSTS));
            $happsql = "SELECT messages_groups.groupid, COUNT(DISTINCT messages_outcomes.id) AS count FROM messages_outcomes 
INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid 
WHERE reviewed = 0 AND timestamp > '$mysqltime' 
AND arrival > '$mysqltime'
AND groupid IN $groupq
AND messages_outcomes.comments IS NOT NULL
              AND messages_outcomes.comments != 'Sorry, this is no longer available.'
              AND messages_outcomes.comments != 'Thanks, this has now been taken.'
              AND messages_outcomes.comments != 'Thanks, I\'m no longer looking for this.' 
              AND messages_outcomes.comments != 'Sorry, this has now been taken.'
              AND messages_outcomes.comments != 'Thanks for the interest, but this has now been taken.'
              AND messages_outcomes.comments != 'Thanks, these have now been taken.'
              AND messages_outcomes.comments != 'Thanks, this has now been received.'
              GROUP BY groupid";
            $happinesscounts = $this->dbhr->preQuery($happsql);
            #error_log($happsql);

            $c = new ChatMessage($this->dbhr, $this->dbhm);
            $reviewcounts = $c->getReviewCountByGroup($me, NULL, FALSE);
            $reviewcountsother = $c->getReviewCountByGroup($me, NULL, TRUE);

            foreach ($groupids as $groupid) {
                # Depending on our group settings we might not want to show this work as primary; "other" work is displayed
                # less prominently in the client.
                #
                # If we have the active flag use that; otherwise assume that the legacy showmessages flag tells us.  Default
                # to active.
                # TODO Retire showmessages entirely and remove from user configs.
                $active = array_key_exists('active', $mysettings[$groupid]) ? $mysettings[$groupid]['active'] : (!array_key_exists('showmessages', $mysettings[$groupid]) || $mysettings[$groupid]['showmessages']);

                $thisone = [
                    'pending' => 0,
                    'pendingother' => 0,
                    'spam' => 0,
                    'pendingmembers' => 0,
                    'pendingmembersother' => 0,
                    'pendingevents' => 0,
                    'pendingvolunteering' => 0,
                    'spammembers' => 0,
                    'spammembersother' => 0,
                    'editreview' => 0,
                    'pendingadmins' => 0,
                    'happiness' => 0,
                    'relatedmembers' => 0,
                    'chatreview' => 0,
                    'chatreviewother' => 0,
                ];

                if ($active) {
                    foreach ($pendingspamcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['collection'] == MessageCollection::PENDING) {
                                if ($count['held']) {
                                    $thisone['pendingother'] = $count['count'];
                                } else {
                                    $thisone['pending'] = $count['count'];
                                }
                            } else {
                                $thisone['spam'] = $count['count'];
                            }
                        }
                    }

                    foreach ($spammembercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            if ($count['held']) {
                                $thisone['spammembersother'] = $count['count'];
                            } else {
                                $thisone['spammembers'] = $count['count'];
                            }
                        }
                    }

                    foreach ($pendingeventcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingevents'] = $count['count'];
                        }
                    }

                    foreach ($pendingvolunteercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingvolunteering'] = $count['count'];
                        }
                    }

                    foreach ($editreviewcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['editreview'] = $count['count'];
                        }
                    }

                    foreach ($pendingadmins as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingadmins'] = $count['count'];
                        }
                    }

                    foreach ($happinesscounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['happiness'] = $count['count'];
                        }
                    }

                    foreach ($relatedmembers as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['relatedmembers'] = $count['count'];
                        }
                    }

                    foreach ($reviewcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['chatreview'] = $count['count'];
                        }
                    }

                    foreach ($reviewcountsother as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['chatreviewother'] = $count['count'];
                        }
                    }
                } else {
                    foreach ($pendingspamcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['pendingother'] = $count['count'];
                        }
                    }

                    foreach ($spammembercounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['spammembersother'] = $count['count'];
                        }
                    }

                    foreach ($reviewcounts as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['chatreviewother'] += $count['count'];
                        }
                    }

                    foreach ($reviewcountsother as $count) {
                        if ($count['groupid'] == $groupid) {
                            $thisone['chatreviewother'] += $count['count'];
                        }
                    }
                }

                $ret[$groupid] = $thisone;
            }
        }

        return($ret);
    }

    public function getPublic($summary = FALSE) {
        $atts = parent::getPublic();

        # Contact mails
        $atts['modsemail'] = $this->getModsEmail();
        $atts['autoemail'] = $this->getAutoEmail();
        $atts['groupemail'] = $this->getGroupEmail();

        # Add in derived properties.
        $atts['namedisplay'] = $atts['namefull'] ? $atts['namefull'] : $atts['nameshort'];
        $settings = json_decode($atts['settings'], true);

        if ($settings) {
            $atts['settings'] = array_replace_recursive($this->defaultSettings, $settings);
        } else {
            $atts['settings'] = $this->defaultSettings;
        }

        $atts['founded'] = Utils::ISODate($this->group['founded']);

        foreach (['affiliationconfirmed'] as $datefield) {
            $atts[$datefield] = Utils::pres($datefield, $atts) ? Utils::ISODate($atts[$datefield]) : NULL;
        }

        # Images.  We pass those ids in to get the paths.  This removes the DB operations for constructing the
        # Attachment, which is valuable for people on many groups.
        if (defined('IMAGE_DOMAIN')) {
            $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
            $b = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);

            $atts['profile'] = $atts['profile'] ? $a->getPath(FALSE, $atts['profile']) : NULL;
            $atts['cover'] = $atts['cover'] ? $b->getPath(FALSE, $atts['cover']) : NULL;
        }

        $atts['url'] = $atts['onhere'] ? ('https://' . USER_SITE . '/explore/' . $atts['nameshort']) : ("https://groups.yahoo.com/neo/groups/" . $atts['nameshort'] . "/info");

        if ($summary) {
            foreach (['settings', 'description', 'welcomemail'] as $att) {
                unset($atts[$att]);
            }
        } else {
            if (Utils::pres('defaultlocation', $atts)) {
                $l = new Location($this->dbhr, $this->dbhm, $atts['defaultlocation']);
                $atts['defaultlocation'] = $l->getPublic();
            }
        }

        $atts['microvolunteeringoptions'] = Utils::pres('microvolunteeringoptions', $atts) ? json_decode($atts['microvolunteeringoptions'], TRUE) : [
            'approvedmessages' => 1,
            'wordmatch' => 1,
            'photorotate' => 1
        ];

        return($atts);
    }

    public function getMembers($limit = 10, $search = NULL, &$ctx = NULL, $searchid = NULL, $collection = MembershipCollection::APPROVED, $groupids = NULL, $yps = NULL, $ydt = NULL, $ops = NULL, $filter = Group::FILTER_NONE) {
        $ret = [];
        $limit = intval($limit);

        $groupids = $groupids ? $groupids : ($this->id ? [ $this-> id ] : NULL);

        if ($search) {
            # Remove wildcards - people put them in, but that's not how it works.
            $search = str_replace('*', '', $search);
        }

        # If we're searching for a notify address, switch to the user it.
        $search = preg_match('/notify-(.*)-(.*)' . USER_DOMAIN . '/', $search, $matches) ? $matches[2] : $search;

        $date = $ctx == NULL ? NULL : $this->dbhr->quote(date("Y-m-d H:i:s", $ctx['Added']));
        $addq = ($ctx == NULL || !Utils::pres('id', $ctx)) ? '' : (" AND (memberships.added < $date OR (memberships.added = $date AND memberships.id < " . $this->dbhr->quote($ctx['id']) . ")) ");
        $groupq = $groupids ? " memberships.groupid IN (" . implode(',', $groupids) . ") " : " 1=1 ";
        $opsq = $ops ? (" AND memberships.ourPostingStatus = " . $this->dbhr->quote($ydt)) : '';
        $modq = '';
        $bounceq = '';
        $filterq = '';
        $uq = '';

        switch ($filter) {
            case Group::FILTER_WITHCOMMENTS:
                $filterq = ' INNER JOIN users_comments ON users_comments.userid = memberships.userid ';
                $filterq = $groupids ? ("$filterq AND users_comments.groupid IN (" . implode(',', $groupids) . ") ") : $filterq;
                break;
            case Group::FILTER_MODERATORS:
                $filterq = '';
                $modq = " AND memberships.role IN ('Owner', 'Moderator') ";
                break;
            case Group::FILTER_BOUNCING:
                $bounceq = ' AND users.bouncing = 1 ';
                $uq = $uq ? $uq : ' INNER JOIN users ON users.id = memberships.userid ';
                break;
            default:
                $filterq = '';
                break;
        }

        # Collection filter.  If we're searching on a specific id then don't put it in.
        $collectionq = '';

        if (!$searchid) {
            if ($collection == MembershipCollection::SPAM) {
                # This collection is handled separately; we use the reviewrequestedat field.
                #
                # This is to avoid moving members into a spam collection and then having to remember whether they
                # came from Pending or Approved.
                #
                # If we have reviewed someone today, don't show them again as it gets annoying.
                $collectionq = " AND reviewrequestedat IS NOT NULL AND (reviewedat IS NULL OR DATE(reviewedat) < CURDATE())";
            } else if ($collection) {
                $collectionq = ' AND memberships.collection = ' . $this->dbhr->quote($collection) . ' ';
            }
        }

        $sqlpref = "SELECT DISTINCT memberships.* FROM memberships 
              INNER JOIN `groups` ON groups.id = memberships.groupid
              $uq
              $filterq";

        $members = [];

        if ($collection == MembershipCollection::SPAM) {
            # Order by userid as we might have the same member subject to review on multiple groups.
            $searchq = $searchid ? (" AND memberships.userid = " . $this->dbhr->quote($searchid) . " ") : '';
            $addq = $ctx == NULL ? '' : (" AND memberships.userid < " . $this->dbhr->quote($ctx['userid']) . " ");
            $sql = "$sqlpref WHERE $groupq $collectionq $addq $searchq $opsq $modq $bounceq";
            $sql .= " ORDER BY memberships.userid DESC LIMIT $limit;";
            $members = $this->dbhr->preQuery($sql);

            # No need to check for spam_users - they will get auto-removed by check_spammers.php.
        } else if ($search) {
            # We're searching.  It turns out to be more efficient to get the userids using the indexes, and then
            # get the rest of the stuff we need.
            $q = $this->dbhr->quote("$search%");
            $bq = $this->dbhr->quote(strrev($search) . "%");
            $p = strpos($search, ' ');
            $namesearch = $p === false ? '' : ("UNION (SELECT id FROM users WHERE firstname LIKE " . $this->dbhr->quote(
                        substr($search, 0, $p) . '%'
                    ) . " AND lastname LIKE " . $this->dbhr->quote(substr($search, $p + 1) . '%')) . ') ';

            $sql = "(SELECT userid AS id FROM users_emails WHERE email LIKE $q) UNION
                (SELECT userid AS id FROM users_emails WHERE backwards LIKE $bq) UNION
                (SELECT id FROM users WHERE id = " . $this->dbhr->quote($search) . ") UNION
                (SELECT id FROM users WHERE fullname LIKE $q) UNION
                (SELECT id FROM users WHERE yahooid LIKE $q)
                $namesearch";
            $userids = $this->dbhr->preQuery($sql);
            $ids = array_column($userids, 'id');

            if (count($ids))
            {
                $sql = "$sqlpref 
                  INNER JOIN users ON users.id = memberships.userid 
                  LEFT JOIN users_emails ON memberships.userid = users_emails.userid 
                  WHERE users.id IN (" . implode(',', $ids) . ") AND 
                  $groupq $collectionq $addq $opsq";

                $sql .= " ORDER BY memberships.added DESC, memberships.id DESC LIMIT $limit;";
                $members = $this->dbhr->preQuery($sql);

                # Also search in banned users.
                if ($groupids && count($groupids)) {
                    $sql = "SELECT userid AS id, userid, groupid, 'Member' AS role, 'Banned' AS collection, NULL AS configid, date AS added, NULL AS settings, 0 AS syncdelete, NULL AS heldby, 0 AS emailfrequency, 0 AS eventsallowed, 0 AS volunteeringallowed, 'PROHIBITED' as ourPostingStatus, NULL as reviewrequestededat, NULL AS reviewreason, NULL AS timestamp FROM users_banned WHERE userid IN (" . implode(',', $ids) . ") AND groupid IN (" . implode(',', $groupids) . ");";
                    $members2 = $this->dbhr->preQuery($sql);
                    $members = array_unique(array_merge($members, $members2));
                }
            }
        } else {
            $searchq = $searchid ? (" AND memberships.userid = " . $this->dbhr->quote($searchid) . " ") : '';
            $sql = "$sqlpref WHERE $groupq $collectionq $addq $searchq $opsq $modq $bounceq";
            $sql .= " ORDER BY memberships.added DESC, memberships.id DESC LIMIT $limit;";
            $members = $this->dbhr->preQuery($sql);
        }

        # Get the infos in a single go.
        $uids = array_filter(array_column($members, 'userid'));
        $infousers = [];

        if (count($uids)) {
            foreach ($uids as $uid) {
                $infousers[$uid] = [
                    'id' => $uid
                ];
            }

            $u = new User($this->dbhr, $this->dbhm);
            $u->getInfos($infousers);
            $u->getPublicLocations($infousers);

            if ($collection == MembershipCollection::SPAM) {
                $latlngs = $u->getLatLngs($infousers, FALSE, FALSE);

                foreach ($latlngs as $userid => $latlng) {
                    $infousers[$userid]['info']['privateposition'] = $latlng;
                }
            }
        }

        if ($groupids && count($groupids) == 1) {
            # Get the bans.
            $banusers = [];

            if (count($uids)) {
                $bans = $this->dbhr->preQuery("SELECT * FROM users_banned WHERE userid IN (" . implode(',', $uids) . ') AND groupid = ?;', [
                    $groupids[0]
                ]);

                foreach ($bans as $ban) {
                    $g = Group::get($this->dbhr, $this->dbhm, $ban['groupid']);
                    $banner = User::get($this->dbhr, $this->dbhm, $ban['byuser']);
                    $banusers[$ban['userid']] = [
                        'bandate' => Utils::ISODate($ban['date']),
                        'bannedby' => $ban['byuser']
                    ];
                }
            }
        }

        # Suspect members might be on multiple groups, so make sure we only return one.
        $uids = [];

        $ctx = [ 'Added' => NULL ];

        foreach ($members as $member) {
            $thisepoch = strtotime($member['added']);

            if ($ctx['Added'] == NULL || $thisepoch < $ctx['Added']) {
                $ctx['Added'] = $thisepoch;
            }

            if ($collection == MembershipCollection::SPAM) {
                $ctx['userid'] = array_key_exists('userid', $ctx) ? min($member['userid'], $ctx['userid']) : $member['userid'];
            } else {
                $ctx['id'] = array_key_exists('id', $ctx) ? min($member['id'], $ctx['id']) : $member['id'];
            }

            if (!Utils::pres($member['userid'], $uids)) {
                $uids[$member['userid']] = TRUE;

                $u = User::get($this->dbhr, $this->dbhm, $member['userid']);
                $thisone = $u->getPublic($groupids, TRUE);
                #error_log("{$member['userid']} has " . count($thisone['comments']));

                # We want to return an id of the membership, because the same user might be pending on two groups, and
                # a userid of the user's id.
                $thisone['userid'] = $thisone['id'];
                $thisone['id'] = $member['id'];
                $thisone['trustlevel'] = $u->getPrivate('trustlevel');
                $thisone['engagement'] = $u->getPrivate('engagement');
                $thisone['collection'] = $member['collection'];

                # We want to return both the email used on this group and any others we have.
                $emails = $u->getEmails();
                $email = NULL;
                $others = [];

                # Groups we host only use a single email.
                $email = $u->getEmailPreferred();
                foreach ($emails as $anemail) {
                    if ($anemail['email'] != $email) {
                        $others[] = $anemail;
                    }
                }

                $thisone['joined'] = Utils::ISODate($member['added']);

                # Defaults match ones in User.php
                #error_log("Settings " . var_export($member, TRUE));
                $thisone['settings'] = $member['settings'] ? json_decode($member['settings'], TRUE) : [
                    'active' => 1,
                    'showchat' => 1,
                    'pushnotify' => 1,
                    'eventsallowed' => 1
                ];

                # Sort so that we have a deterministic order for UT.
                usort($others, function($a, $b) {
                    return(strcmp($a['email'], $b['email']));
                });

                $thisone['settings']['configid'] = $member['configid'];
                $thisone['email'] = $email;
                $thisone['groupid'] = $member['groupid'];
                $thisone['otheremails'] = $others;
                $thisone['role'] = $u->getRoleForGroup($member['groupid'], FALSE);
                $thisone['emailfrequency'] = $member['emailfrequency'];
                $thisone['eventsallowed'] = $member['eventsallowed'];
                $thisone['volunteeringallowed'] = $member['volunteeringallowed'];

                # Our posting status only applies for groups we host.  In that case, the default is moderated.
                $thisone['ourpostingstatus'] = Utils::presdef('ourPostingStatus', $member, Group::POSTING_MODERATED);

                $thisone['heldby'] = $member['heldby'];

                if (Utils::pres('heldby', $thisone)) {
                    $u = User::get($this->dbhr, $this->dbhm, $thisone['heldby']);
                    $thisone['heldby'] = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);
                }

                if ($filter === Group::FILTER_MODERATORS) {
                    # Also add in the time this mod was last active.  This is not the same as when they last moderated
                    # but indicates if they have been on the platform, which is what you want to find mods who have
                    # drifted off.  Getting the correct value is too timeconsuming.
                    $thisone['lastmoderated'] = Utils::ISODate($u->getPrivate('lastaccess'));
                }

                # Pick up the info we fetched above.
                $thisone['info'] = $infousers[$thisone['userid']]['info'];

                if (Utils::pres($thisone['userid'], $banusers)) {
                    $thisone['bandate'] = $banusers[$thisone['userid']]['bandate'];
                    $thisone['bannedby'] = $banusers[$thisone['userid']]['bannedby'];
                }

                $ret[] = $thisone;
            }
        }

        return($ret);
    }

    public function getHappinessMembers($groupids, &$ctx, $filter = NULL, $limit = 10) {
        $ret = [];
        $filterq = '';

        if ($filter) {
            foreach ([ User::HAPPY, User::UNHAPPY, User::FINE] as $val) {
                if ($filter == $val) {
                    if ($val === 'Fine') {
                        $filterq = " AND (messages_outcomes.outcome IS NULL OR messages_outcomes.happiness = '$val') ";
                    } else {
                        $filterq = " AND messages_outcomes.happiness = '$val' ";
                    }
                }
            }
        }

        $groupids = $groupids ? $groupids : ($this->id ? [ $this-> id ] : NULL);
        $groupq2 = $groupids ? " messages_groups.groupid IN (" . implode(',', $groupids) . ") " : " 1=1 ";

        # Only interested in showing recent ones, which makes the query faster.
        $start = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));

        # We want unreviewed first, then most recent.
        $ctxq = $ctx == NULL ? " WHERE messages_outcomes.timestamp > '$start' " :
            (" WHERE
            messages_outcomes.reviewed >= " . intval($ctx['reviewed']) . " AND   
            messages_outcomes.timestamp > '$start' AND 
            (messages_outcomes.timestamp < '" . Utils::safedate($ctx['timestamp']) . "' OR 
                (messages_outcomes.timestamp = '" . Utils::safedate($ctx['timestamp']) . "' AND
                 messages_outcomes.id < " . intval($ctx['id']) . "))");

        $sql = "SELECT messages_outcomes.*, messages.fromuser, messages_groups.groupid, messages.subject FROM messages_outcomes
INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid AND $groupq2
INNER JOIN messages ON messages.id = messages_outcomes.msgid
$ctxq
$filterq
AND messages_outcomes.comments IS NOT NULL
ORDER BY messages_outcomes.reviewed ASC, messages_outcomes.timestamp DESC, messages_outcomes.id DESC LIMIT $limit
";
        $members = $this->dbhr->preQuery($sql, []);

        # Get the users in a single go for speed.
        $uids = array_column($members, 'fromuser');
        $u = new User($this->dbhr, $this->dbhm);
        $users = $u->getPublicsById($uids, NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);

        # Get the preferred emails.
        $u->getPublicEmails($users);

        foreach ($users as $userid => $user) {
            if (Utils::pres('emails', $user)) {
                foreach ($user['emails'] as $email) {
                    if ($email['preferred'] || (!Mail::ourDomain($email['email']) && !Utils::pres('email', $users[$userid]))) {
                        $users[$userid]['email'] = $email['email'];
                    }
                }
            }
        }

        $last = NULL;

        foreach ($members as $member) {
            # Ignore dups.
            if ($last && $member['msgid'] == $last) {
                continue;
            }

            $last = $member['msgid'];

            $ctx = [
                'id' => $member['id'],
                'timestamp' => $member['timestamp'],
                'reviewed' => $member['reviewed']
            ];

            $member['user']  = $users[$member['fromuser']];

            $member['message'] = [
                'id' => $member['msgid'],
                'subject' => $member['subject'],
            ];

            unset($member['msgid']);
            unset($member['subject']);

            $member['timestamp'] = Utils::ISODate($member['timestamp']);
            $ret[] = $member;
        }

        return($ret);
    }

    public function getBanned($groupid, &$ctx) {
        $ctx = $ctx ? $ctx : [];

        if (Utils::pres('date', $ctx)) {
            $members = $this->dbhr->preQuery("SELECT date AS bandate, byuser AS bannedby, groupid, userid FROM users_banned WHERE groupid = ? AND date < ? ORDER BY date DESC;", [
                $groupid,
                $ctx['date']
            ]);
        } else {
            $members = $this->dbhr->preQuery("SELECT date AS bandate, byuser AS bannedby, groupid, userid FROM users_banned WHERE groupid = ? ORDER BY date DESC;", [
                $groupid
            ]);
        }

        $ret = [];

        $u = new User($this->dbhr, $this->dbhm);
        $users = $u->getPublicsById(array_column($members, 'userid'));

        foreach ($members as $member) {
            $thisone = array_merge($users[$member['userid']], $member);
            $thisone['bandate'] = Utils::ISODate($thisone['bandate']);
            $ret[] = $thisone;
            $ctx['date'] = $member['bandate'];
        }

        return $ret;
    }

    public function ourPS($status) {
        # For historical reasons, the ourPostingStatus field has various values, equivalent to those on Yahoo.  But
        # we only support three settings - MODERATED, DEFAULT aka Group Settings, and PROHIBITED aka Can't Post.
        switch ($status) {
            case NULL: $status = NULL; break;
            case Group::POSTING_MODERATED: $status = Group::POSTING_MODERATED; break;
            case Group::POSTING_PROHIBITED: $status = Group::POSTING_PROHIBITED; break;
            default: $status = Group::POSTING_DEFAULT; break;
        }

        return($status);
    }

    public function setSettings($settings)
    {
        $str = json_encode($settings);
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $this->dbhm->preExec("UPDATE `groups` SET settings = ? WHERE id = ?;", [ $str, $this->id ]);
        Group::clearCache($this->id);
        $this->group['settings'] = $str;
        $this->log->log([
            'type' => Log::TYPE_GROUP,
            'subtype' => Log::SUBTYPE_EDIT,
            'groupid' => $this->id,
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $this->getEditLog([
                'settings' => $settings
            ])
        ]);

        return(true);
    }

    public function getSetting($key, $def, $settings = NULL) {
        if ($settings === NULL && Utils::pres('settings', $this->group)) {
            $settings = $this->group['settings'];
        } else {
            $settings = $this->defaultSettings;
        }

        $settings = $this->id ? json_decode($settings, true) : NULL;
        return($settings && array_key_exists($key, $settings) ? $settings[$key] : $def);
    }

    public function getSponsorships() {
        $sql = "SELECT * FROM groups_sponsorship WHERE groupid = ? AND startdate <= NOW() AND enddate >= DATE(NOW()) AND visible = 1 ORDER BY amount DESC, tagline IS NOT NULL, description IS NOT NULL;";
        return $this->dbhr->preQuery($sql, [
            $this->id
        ]);
    }

    public function getConfirmKey() {
        $key = NULL;

        # Don't reset the key each time, otherwise we can have timing windows where the key is reset, thereby
        # invalidating an invitation which is in progress.
        $groups = $this->dbhr->preQuery("SELECT confirmkey FROM `groups` WHERE id = ?;" , [ $this->id ]);
        foreach ($groups as $group) {
            $key = $group['confirmkey'];
        }

        if (!$key) {
            $key = Utils::randstr(32);
            $sql = "UPDATE `groups` SET confirmkey = ? WHERE id = ?;";
            $rc = $this->dbhm->preExec($sql, [ $key, $this->id ]);
            Group::clearCache($this->id);
        }

        return($key);
    }

    public function getName() {
        return($this->group['namefull'] ? $this->group['namefull'] : $this->group['nameshort']);
    }

    public function listByType($type, $support, $polys = FALSE) {
        $typeq = $type ? "type = ?" : '1=1';
        $showq = $support ? '' : 'AND publish = 1 AND listable = 1';
        $suppfields = $support ? ", founded, lastmoderated, lastmodactive, lastautoapprove, activemodcount, backupmodsactive, backupownersactive, onmap, affiliationconfirmed, affiliationconfirmedby": '';
        $polyfields = $polys ? ", CASE WHEN poly IS NULL THEN polyofficial ELSE poly END AS poly, polyofficial" : '';

        $sql = "SELECT groups.id, groups.settings, groups_images.id AS attid, nameshort, region, namefull, lat, lng, altlat, altlng, publish $suppfields $polyfields, mentored, onhere, ontn, onmap, external, profile, tagline, contactmail FROM `groups` LEFT JOIN groups_images ON groups_images.groupid = groups.id WHERE $typeq ORDER BY CASE WHEN namefull IS NOT NULL THEN namefull ELSE nameshort END, groups_images.id DESC;";
        $groups = $this->dbhr->preQuery($sql, [ $type ]);
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);

        $g = new Group($this->dbhr, $this->dbhm);

        if ($support) {
            $start = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
            $autoapproves = $this->dbhr->preQuery("SELECT COUNT(*) AS count, groupid FROM logs WHERE timestamp >= ? AND type = ? AND subtype = ? GROUP BY groupid;", [
                $start,
                Log::TYPE_MESSAGE,
                Log::SUBTYPE_AUTO_APPROVED
            ]);

            $manualapproves = $this->dbhr->preQuery("SELECT COUNT(*) AS count, groupid FROM logs WHERE timestamp >= ? AND type = ? AND subtype = ? GROUP BY groupid;", [
                $start,
                Log::TYPE_MESSAGE,
                Log::SUBTYPE_APPROVED
            ]);
        }

        $lastname = NULL;
        $ret = [];

        foreach ($groups as &$group) {
            if (!$lastname || $lastname != $group['nameshort']) {
                $group['namedisplay'] = $group['namefull'] ? $group['namefull'] : $group['nameshort'];
                $group['profile'] = $group['profile'] ? $a->getPath(FALSE, $group['attid']) : NULL;

                if ($group['contactmail']) {
                    $group['modsmail'] = $group['contactmail'];
                } else {
                    $group['modsmail'] = $group['nameshort'] . "-volunteers@" . GROUP_DOMAIN;
                }

                if ($support) {
                    foreach ($autoapproves as $approve) {
                        if ($approve['groupid'] === $group['id']) {
                            $group['recentautoapproves'] = $approve['count'];
                        }
                    }

                    foreach ($manualapproves as $approve) {
                        if ($approve['groupid'] === $group['id']) {
                            # Exclude the autoapproves, which have an approved log as well as an autoapproved log.
                            $group['recentmanualapproves'] = $approve['count'] - Utils::presdef('recentautoapproves', $group, 0);
                        }
                    }

                    if (Utils::pres('recentautoapproves', $group)) {
                        $total = $group['recentmanualapproves'] + $group['recentautoapproves'];
                        $group['recentautoapprovespercent'] = $total ? (round(100 * $group['recentautoapproves']) / $total) : 0;
                    } else {
                        $group['recentautoapprovespercent'] = 0;
                    }
                }

                $group['nearbygroups'] = $g->getSetting('nearbygroups', $g->defaultSettings['nearbygroups'], $group['settings']);
                $group['showjoin'] = $g->getSetting('showjoin', $g->defaultSettings['showjoin'], $group['settings']);
                unset($group['settings']);

                $ret[] = $group;
            }

            $lastname = $group['nameshort'];
        }

        return($ret);
    }

    public function welcomeReview($gid = NULL, $limit = 10) {
        # Send copy of the welcome mail to mods for review.
        $idq = $gid ? " AND id = $gid " : "";
        $count = 0;

        $groups = $this->dbhr->preQuery("SELECT id FROM `groups` WHERE (welcomereview IS NULL OR DATEDIFF(NOW(), welcomereview) >= 365) AND welcomemail IS NOT NULL $idq LIMIT $limit;");
        foreach ($groups as $group) {
            $g = Group::get($this->dbhr, $this->dbhm, $group['id']);
            $mods = $g->getMods();
            error_log($g->getName());
            foreach ($mods as $mod) {
                $u = new User($this->dbhr, $this->dbhm, $mod);
                if ($u->sendOurMails() && $u->getEmailPreferred()) {
                    error_log("..." . $u->getEmailPreferred());
                    $u->sendWelcome($g->getPrivate('welcomemail'), $group['id'], NULL, NULL, TRUE);
                    $count++;

                    $this->dbhm->preExec("UPDATE `groups` SET welcomereview = NOW() WHERE id = ?;", [
                        $group['id']
                    ]);
                }
            }
        }

        return $count;
    }

    static public function getOpenCount($dbhr, $id) {
        $mysqltime = date("Y-m-d", strtotime(MessageCollection::RECENTPOSTS));
        $counts = $dbhr->preQuery("SELECT COUNT(*) AS count FROM `messages_groups` LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid WHERE arrival >= ? AND groupid = ? AND messages_outcomes.id IS NULL AND messages_groups.deleted = 0;", [
            $mysqltime,
            $id
        ]);

        return $counts[0]['count'];
    }
}
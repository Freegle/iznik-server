<?php
namespace Freegle\Iznik;


require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');

class Newsfeed extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'timestamp', 'added', 'type', 'userid', 'imageid', 'msgid', 'replyto', 'groupid', 'eventid', 'storyid', 'volunteeringid', 'publicityid', 'message', 'position', 'deleted', 'closed', 'html', 'pinned', 'hidden');

    /** @var  $log Log */
    private $log;
    var $feed;

    const DISTANCE = 15000;

    const TYPE_MESSAGE = 'Message';
    const TYPE_COMMUNITY_EVENT = 'CommunityEvent';
    const TYPE_VOLUNTEER_OPPORTUNITY = 'VolunteerOpportunity';
    const TYPE_CENTRAL_PUBLICITY = 'CentralPublicity';
    const TYPE_ALERT = 'Alert';
    const TYPE_STORY = 'Story';
    const TYPE_REFER_TO_WANTED = 'ReferToWanted';
    const TYPE_REFER_TO_OFFER = 'ReferToOffer';
    const TYPE_REFER_TO_TAKEN = 'ReferToTaken';
    const TYPE_REFER_TO_RECEIVED = 'ReferToReceived';
    const TYPE_ATTACH_TO_THREAD = 'AttachToThread';
    const TYPE_ABOUT_ME = 'AboutMe';
    const TYPE_NOTICEBOARD = 'Noticeboard';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->log = new Log($dbhr, $dbhm);

        $this->fetch($dbhr, $dbhm, $id, 'newsfeed', 'feed', $this->publicatts);
    }

    public function create($type, $userid = NULL, $message = NULL, $imageid = NULL, $msgid = NULL, $replyto = NULL, $groupid = NULL, $eventid = NULL, $volunteeringid = NULL, $publicityid = NULL, $storyid = NULL, $lat = NULL, $lng = NULL) {
        $id = NULL;

        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $g = Group::get($this->dbhr, $this->dbhm, $groupid);

        # Check the newsfeed mod status of the poster.  If it is suppressed, then we insert it as hidden (so
        # they can see it) but don't alert.
        $suppressed = $u->getPrivate('newsfeedmodstatus') == User::NEWSFEED_SUPPRESSED;

        $s = new Spam($this->dbhr, $this->dbhm);
        $hidden = ($suppressed || $s->checkReferToSpammer($message)) ? 'NOW()' : 'NULL';

        if ($lat === NULL) {
            # We might have been given a lat/lng separate from the user, e.g. for noticeboards.
            list($lat, $lng, $loc) = $userid ? $u->getLatLng(FALSE) : [ NULL, NULL, NULL ];

            # If we don't know where the user is, use the group location.
            $lat = ($groupid && $lat === NULL) ? $g->getPrivate('lat') : $lat;
            $lng = ($groupid && $lng === NULL) ? $g->getPrivate('lng') : $lng;
        }

#        error_log("Create at $lat, $lng");

        if ($lat || $lng ||
            $type == Newsfeed::TYPE_CENTRAL_PUBLICITY ||
            $type == Newsfeed::TYPE_ALERT ||
            $type == Newsfeed::TYPE_REFER_TO_WANTED ||
            $type == Newsfeed::TYPE_REFER_TO_OFFER ||
            $type == Newsfeed::TYPE_REFER_TO_TAKEN ||
            $type == Newsfeed::TYPE_REFER_TO_RECEIVED
        ) {
            # Only put it in the newsfeed if we have a location, otherwise we wouldn't show it.
            $pos = ($lat || $lng) ? "GeomFromText('POINT($lng $lat)')" : "GeomFromText('POINT(-2.5209 53.9450)')";

            # We've seen cases where we get duplicate POSTS of the same newsfeed item from different sessions, which
            # bypasses the normal duplicate protection. So check.
            $last = $this->dbhm->preQuery("SELECT * FROM newsfeed WHERE userid = ? ORDER BY id DESC LIMIT 1;", [
                $userid
            ]);

            if (!count($last) || $last[0]['replyto'] != $replyto || $last[0]['type'] != $type || $last[0]['message'] != $message) {
                $this->dbhm->preExec("INSERT INTO newsfeed (`type`, userid, imageid, msgid, replyto, groupid, eventid, volunteeringid, publicityid, storyid, message, position, hidden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $pos, $hidden);", [
                    $type,
                    $userid,
                    $imageid,
                    $msgid,
                    $replyto,
                    $groupid,
                    $eventid,
                    $volunteeringid,
                    $publicityid,
                    $storyid,
                    $message
                ]);

                $id = $this->dbhm->lastInsertId();

                if ($id) {
                    $this->fetch($this->dbhm, $this->dbhm, $id, 'newsfeed', 'feed', $this->publicatts);

                    if ($replyto && $hidden == 'NULL') {
                        # Bump the thread all the way up to the thread head, so that it moves up the feed.
                        $bump = $replyto;

                        do {
                            $parent = $this->dbhr->preQuery("SELECT replyto FROM newsfeed WHERE id = ?;", [
                                $bump
                            ]);

                            $this->dbhm->preExec("UPDATE newsfeed SET timestamp = NOW() WHERE id = ?;", [ $bump ]);

                            $bump = count($parent) ? $parent[0]['replyto'] : NULL;
                        } while ($bump);

                        # Now work down from the thread head to find all the people who have contributed to a thread.
                        # We don't necessarily want to notify all users who have commented on a thread - that would mean that you
                        # got pestered for a thread you'd long since lost interest in, and many people won't work out how to unfollow.
                        # So notify anyone who has commented in the last week.
                        $recent = strtotime("midnight 7 days ago");

                        $contributed = [];
                        $ids = [
                            $replyto
                        ];

                        do {
                            $oldcount = count($ids);
                            # error_log("Example threads $oldcount " . json_encode($ids) . " count " . count($ids) . " contributors " . count($contributed));
                            $replies = $this->dbhr->preQuery("SELECT id, userid, timestamp FROM newsfeed WHERE replyto IN (" . implode(',', $ids) . ") OR id IN (" . implode(',', $ids) . ");");

                            # Repeat our widening search.
                            foreach ($replies as $reply) {
                                # See if any of these people have recently engaged.
                                $time = strtotime($reply['timestamp']);

                                #error_log("Consider reply from {$reply['userid']} time $time vs $recent");
                                if ($time >= $recent) {
                                    #error_log("...add");
                                    $contributed[] = $reply['userid'];
                                }

                                $ids[] = $reply['id'];
                            }

                            # We don't want to alert the user who added this.
                            $contributed = array_unique(array_filter($contributed, function ($id) use ($userid) {
                                return $id !== $userid;
                            }));

                            $ids = array_unique($ids);
                        } while (count($ids) !== $oldcount);

                        # Now notify them, pointing at the part of the thread which has been replied to.
                        foreach ($contributed as $contrib) {
                            $n = new Notifications($this->dbhr, $this->dbhm);
                            $n->add($userid, $contrib, Notifications::TYPE_COMMENT_ON_YOUR_POST, $id, $replyto);
                        }

                        # We might have notifications which refer to this thread.  But there's no need to show them
                        # now.
                        $this->dbhm->preExec("UPDATE users_notifications SET seen = 1 WHERE touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?));", [
                            $userid,
                            $replyto,
                            $replyto
                        ]);
                    }
                }
            } else {
                $id = $last[0]['id'];
            }
        }

        return($id);
    }

    private function setThreadHead(&$entries, $threadhead) {
        # Ensure the thread head is rippled down to all replies.
        for ($entindex = 0; $entindex < count($entries); $entindex++) {
            $entries[$entindex]['threadhead'] = $threadhead;

            if (Utils::pres('replies', $entries[$entindex])) {
                $this->setThreadHead($entries[$entindex]['replies'], $threadhead);
            }
        }
    }

    public function getPublic($lovelist = FALSE, $unfollowed = TRUE, $allreplies = FALSE, $anyreplies = TRUE) {
        $atts = parent::getPublic();
        $users = [];

        // Where does this thread start?
        if (Utils::pres('threadhead', $atts)) {
            $threadhead = $atts['threadhead'];
        } else if (Utils::pres('replyto', $atts)) {
            $threadhead = $atts['replyto'];
        } else {
            $threadhead = $atts['id'];
        }

        $atts['threadhead'] = $threadhead;
        $entries = [ $atts ];

        $this->fillIn($entries, $users, TRUE, $allreplies);

        $atts = $entries[0];

        if ($anyreplies && Utils::pres('replies', $atts)) {
            # We may already have some or all of the reply users in users.
            $this->addUsers($users, array_column($atts['replies'], 'userid'));

            foreach ($atts['replies'] as &$reply) {
                if (Utils::pres('userid', $reply)) {
                    $reply['user'] = $users[$reply['userid']];
                }
            }
        }

        if (Utils::pres('replies', $atts)) {
            $this->setThreadHead($atts['replies'], $threadhead);
        }

        if ($lovelist) {
            $atts['lovelist'] = [];
            $loves = $this->dbhr->preQuery("SELECT * FROM newsfeed_likes WHERE newsfeedid = ?;", [
                $this->id
            ]);

            $this->addUsers($users, array_column($loves, 'userid'));

            foreach ($loves as $love) {
                $atts['lovelist'][] = $users[$love['userid']];
            }
        }

        if ($unfollowed) {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me ? $me->getId() : NULL;
            $atts['unfollowed'] = $this->unfollowed($myid, $this->id);
        }

        if (Utils::pres('userid', $atts)) {
            if (!Utils::pres($atts['userid'], $users)) {
                $this->addUsers($users, [ $atts['userid']]);
            }

            $atts['user'] = $users[$atts['userid']];
            unset($atts['userid']);
        }

        return($atts);
    }

    private function addUsers(&$users, $uids) {
        $missing = array_diff($uids, array_keys($users));

        if (count($missing)) {
            $u = User::get($this->dbhr, $this->dbhm);
            $replyusers = $u->getPublicsById($missing, NULL, FALSE, FALSE, FALSE, FAlSE, FALSE, FALSE);
            $u->getPublicLocations($replyusers);
            $u->getActiveCountss($replyusers);

            foreach ($replyusers as $r) {
                $users[$r['id']] = $r;
            }
        }
    }

    private function fillIn(&$entries, &$users, $checkreplies = TRUE, $allreplies = FALSE) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;
        $ids = array_filter(array_column($entries, 'id'));

        # Get all the url previews efficiently.
        $p = new Preview($this->dbhr, $this->dbhm);

        $urls = [];

        for ($entindex = 0; $entindex < count($entries); $entindex++) {
            if (preg_match_all(Utils::URL_PATTERN, $entries[$entindex]['message'], $matches)) {
                foreach ($matches as $val) {
                    foreach ($val as $url) {
                        $urls[] = $url;
                        break 2;
                    }
                }
            }
        }

        $previews = $p->gets($urls);

        if ($ids && count($ids)) {
            $likes = $this->dbhr->preQuery("SELECT newsfeedid, COUNT(*) AS count FROM newsfeed_likes WHERE newsfeedid IN (" . implode(',', $ids) . ") GROUP BY newsfeedid;", NULL, FALSE, FALSE);
            $mylikes = $me ? $this->dbhr->preQuery("SELECT newsfeedid, COUNT(*) AS count FROM newsfeed_likes WHERE newsfeedid IN (" . implode(',', $ids) . ") AND userid = ?;", [
                $me->getId()
            ]) : [];

            $imageids = array_filter(array_column($entries, 'imageid'));
            $images = [];
            if ($imageids && count($imageids)) {
                $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_NEWSFEED);
                $images = $a->getByImageIds($imageids);
            }

            if ($checkreplies) {
                $replies = $this->dbhr->preQuery($allreplies ? "SELECT * FROM newsfeed WHERE replyto IN (" . implode(',', $ids) . ") AND deleted IS NULL ORDER BY id DESC;" : "SELECT * FROM newsfeed WHERE replyto IN (" . implode(',', $ids) . ") AND deleted IS NULL ORDER BY id DESC;", NULL, FALSE);
                $replies = array_reverse($replies);
                $this->fillIn($replies, $users, TRUE, FALSE);
            }

            for ($entindex = 0; $entindex < count($entries); $entindex++) {
                unset($entries[$entindex]['position']);

                if ($entries[$entindex]['type'] != Newsfeed::TYPE_NOTICEBOARD) {
                    # Noticeboards hackily have JSON data in message.
                    $entries[$entindex]['message'] = trim($entries[$entindex]['message']);
                }

                $use = !Utils::presdef('reviewrequired', $entries[$entindex], FALSE) &&
                    (!Utils::presdef('deleted', $entries[$entindex], FALSE) || $me->isModerator());

                #error_log("Use $use for type {$entries[$entindex]['type']} from " . Utils::presdef('reviewrequired', $entries[$entindex], FALSE) . "," . Utils::presdef('deleted', $entries[$entindex], FALSE));

                if ($use) {
                    if (preg_match_all(Utils::URL_PATTERN, $entries[$entindex]['message'], $matches)) {
                        foreach ($matches as $val) {
                            foreach ($val as $url) {
                                $entries[$entindex]['preview'] = Utils::presdef($url, $previews, NULL);
                                break 2;
                            }
                        }
                    }

                    if (Utils::pres('msgid', $entries[$entindex])) {
                        $m = new Message($this->dbhr, $this->dbhm, $entries[$entindex]['msgid']);
                        $entries[$entindex]['refmsg'] = $m->getPublic(FALSE, FALSE);
                    }

                    if (Utils::pres('eventid', $entries[$entindex])) {
                        $e = new CommunityEvent($this->dbhr, $this->dbhm, $entries[$entindex]['eventid']);
                        $use = FALSE;
                        #error_log("Consider event " . $e->getPrivate('pending') . ", " . $e->getPrivate('deleted'));
                        if (!$e->getPrivate('pending') && !$e->getPrivate('deleted')) {
                            $atts = $e->getPublic();

                            # Events must contain a group.
                            if (Utils::pres('groups', $atts) && count($atts['groups'])) {
                                $use = TRUE;
                                $entries[$entindex]['communityevent'] = $atts;
                            }
                        }
                    }

                    if (Utils::pres('volunteeringid', $entries[$entindex])) {
                        $v = new Volunteering($this->dbhr, $this->dbhm, $entries[$entindex]['volunteeringid']);
                        $use = FALSE;
                        #error_log("Consider volunteering " . $v->getPrivate('pending') . ", " . $v->getPrivate('deleted'));
                        if (!$v->getPrivate('pending') && !$v->getPrivate('deleted')) {
                            $use = TRUE;
                            $entries[$entindex]['volunteering'] = $v->getPublic();
                        }
                    }

                    if (Utils::pres('publicityid', $entries[$entindex])) {
                        $pubs = $this->dbhr->preQuery("SELECT postid, data FROM groups_facebook_toshare WHERE id = ?;", [ $entries[$entindex]['publicityid'] ]);

                        if (preg_match('/(.*)_(.*)/', $pubs[0]['postid'], $matches)) {
                            # Create the iframe version of the Facebook plugin.
                            $pageid = $matches[1];
                            $postid = $matches[2];

                            $data = json_decode($pubs[0]['data'], TRUE);

                            $entries[$entindex]['publicity'] = [
                                'id' => $entries[$entindex]['publicityid'],
                                'postid' => $pubs[0]['postid'],
                                'iframe' => '<iframe class="completefull" src="https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2F' . $pageid . '%2Fposts%2F' . $postid . '%2F&width=auto&show_text=true&appId=' . FBGRAFFITIAPP_ID . '&height=500" width="500" height="500" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowTransparency="true"></iframe>',
                                'full_picture' => Utils::presdef('full_picture', $data, NULL),
                                'message' => Utils::presdef('message', $data, NULL),
                                'type' => Utils::presdef('type', $data, NULL)
                            ];
                        }
                    }

                    if (Utils::pres('storyid', $entries[$entindex])) {
                        $s = new Story($this->dbhr, $this->dbhm, $entries[$entindex]['storyid']);
                        $use = FALSE;
                        #error_log("Consider story " . $s->getPrivate('reviewed') . ", " . $s->getPrivate('public'));
                        if ($s->getPrivate('reviewed') && $s->getPrivate('public') && $s->getId()) {
                            $use = TRUE;
                            $entries[$entindex]['story'] = $s->getPublic();
                        }
                    }

                    if (Utils::pres('imageid', $entries[$entindex])) {
                        foreach ($images as $image) {
                            if ($image->getId() == $entries[$entindex]['imageid']) {
                                $entries[$entindex]['image'] = $image->getPublic();
                            }
                        }
                    }

                    $entries[$entindex]['timestamp'] = Utils::ISODate($entries[$entindex]['timestamp']);

                    if (Utils::pres('added', $entries[$entindex])) {
                        $entries[$entindex]['added'] = Utils::ISODate($entries[$entindex]['added']);
                    }

                    $entries[$entindex]['loves'] = 0;
                    $entries[$entindex]['loved'] = FALSE;

                    foreach ($likes as $like) {
                        if ($like['newsfeedid'] == $entries[$entindex]['id']) {
                            $entries[$entindex]['loves'] = $like['count'];
                        }
                    }

                    if ($me) {
                        foreach ($mylikes as $like) {
                            if ($like['newsfeedid'] == $entries[$entindex]['id']) {
                                $entries[$entindex]['loved'] = $like['count'] > 0;
                            }
                        }
                    }

                    $entries[$entindex]['replies'] = [];

                    if ($checkreplies) {
                        $last = NULL;
                        $entries[$entindex]['replies'] = [];

                        # We may already have some or all of the reply users in users.
                        $this->addUsers($users, array_column($replies, 'userid'));

                        foreach ($replies as $index => $reply) {
                            if ($reply['replyto'] == $entries[$entindex]['id']) {
                                $hidden = $reply['hidden'];

                                # Don't use hidden entries unless they are ours.  This means that to a spammer or a
                                # disruptive member it looks like their posts are there but no other user can see them.
                                if (!$hidden || $myid == $reply['userid'] || $me->isModerator()) {
                                    if ($reply['visible'] &&
                                        $last['userid'] == $reply['userid'] &&
                                        $last['type'] == $reply['type'] &&
                                        $last['message'] == $reply['message'] &&
                                        !$last['deleted']
                                    ) {
                                        # Suppress duplicates.
                                        $replies[$index]['visible'] = FALSE;
                                    }

                                    if (!$me || !$me->isModerator()) {
                                        $replies[$index]['hidden'] = NULL;
                                    }

                                    if (Utils::pres('userid', $reply)) {
                                        $replies[$index]['user'] = $users[$reply['userid']];
                                    }

                                    $entries[$entindex]['replies'][] = $replies[$index];

                                    $last = $replies[$index];
                                }
                            }
                        }
                    }
                }

                $entries[$entindex]['visible'] = $use;
            }
        }
    }

    public function getNearbyDistance($userid, $max = 204800) {
        $u = User::get($this->dbhr, $this->dbhm, $userid);

        # We want to calculate a distance which includes at least some other people who have posted a message.
        # Start at fairly close and keep doubling until we reach that, or get too far away.
        list ($lat, $lng, $loc) = $u->getLatLng();
        $dist = 800;
        $limit = 10;

        do {
            $dist *= 2;

            # To use the spatial index we need to have a box.
            # TODO This doesn't work if the box spans the equator.  For us it only does for testing.
            $ne = \GreatCircle::getPositionByDistance($dist, 45, $lat, $lng);
            $sw = \GreatCircle::getPositionByDistance($dist, 225, $lat, $lng);

            $mysqltime = date('Y-m-d', strtotime("30 days ago"));
            $box = "GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))')";

            $sql = "SELECT DISTINCT userid FROM newsfeed FORCE INDEX (position) WHERE MBRContains($box, position) AND replyto IS NULL AND type != '" . Newsfeed::TYPE_ALERT . "' AND timestamp >= '$mysqltime' LIMIT $limit;";
            $others = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
            #error_log("Found " . count($others) . " at $dist from $lat, $lng for $userid using $sql");
        } while ($dist < $max && count($others) < $limit);

        return($dist);
    }

    public function getFeed($userid, $dist = Newsfeed::DISTANCE, $types, &$ctx, $fillin = TRUE) {
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $topitems = [];
        $bottomitems = [];

        if ($userid) {
            # We want the newsfeed items which are close to us.  Use the location in settings, or failing that the
            # last location they've posted from.
            list ($lat, $lng, $loc) = $u->getLatLng();

            # To use the spatial index we need to have a box.
            $ne = \GreatCircle::getPositionByDistance($dist, 45, $lat, $lng);
            $sw = \GreatCircle::getPositionByDistance($dist, 225, $lat, $lng);

            $box = "GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))')";

            # We return most recent first.
            $tq = Utils::pres('timestamp', $ctx) ? ("newsfeed.timestamp < " . $this->dbhr->quote($ctx['timestamp'])) : 'newsfeed.id > 0';
            $first = $dist ? "(MBRContains($box, position) OR `type` IN ('CentralPublicity', 'Alert')) AND $tq" : $tq;
            $typeq = $types ? (" AND `type` IN ('" . implode("','", $types) . "') ") : '';

            # We might have pinned some posts in a previous call.  Don't show them again.
            $pinq = '';

            $pinned = [];

            if (Utils::pres('pinned', $ctx)) {
                $pinq = " AND newsfeed.id NOT IN (";
                $sep = '';

                foreach (explode(',', $ctx['pinned']) as $id) {
                    $pinq .= $sep . intval($id);
                    $pinned[] = intval($id);
                    $sep = ', ';
                }

                $pinq .= ")";
            }

            $sql = "SELECT newsfeed." . implode(',newsfeed.', $this->publicatts) . ", hidden, newsfeed_unfollow.id AS unfollowed FROM newsfeed LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = $userid WHERE $first AND replyto IS NULL AND newsfeed.deleted IS NULL $typeq $pinq ORDER BY pinned DESC, timestamp DESC LIMIT 5;";
            #error_log("Get feed $sql");
            $entries = $this->dbhr->preQuery($sql);
            $last = NULL;

            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $myid = $me ? $me->getId() : NULL;

            # Get the users that we need for filling in more efficiently - find their ids and then get them in
            # a single call.
            $users = [];
            $uids = array_filter(array_column($entries, 'userid'));
            $newsids = array_filter(array_column($entries, 'id'));

            if (count($newsids)) {
                do {
                    # We keep going until we've not picked up any more users.
                    $uidcount = count($uids);

                    $replies = $this->dbhr->preQuery("SELECT id, userid FROM newsfeed WHERE replyto IN (" . implode(',', $newsids) . ");", NULL, FALSE, FALSE);
                    $uids = array_unique(array_merge($uids, array_filter(array_column($replies, 'userid'))));
                    $newsids = array_unique(array_merge($newsids, array_filter(array_column($replies, 'id'))));
                } while (count($uids) !== $uidcount);

                $users = $u->getPublicsById($uids, NULL, FALSE, FALSE, FALSE, FAlSE, FALSE, FALSE);
                $u->getPublicLocations($users);
                $u->getActiveCountss($users);
            }

            $filtered = [];
            
            foreach ($entries as &$entry) {
                $hidden = $entry['hidden'];

                // This entry is the start of the thread.
                $entry['threadhead'] = $entry['id'];

                # Don't use hidden entries unless they are ours.  This means that to a spammer or suppressed user
                # it looks like their posts are there but nobody else sees them.
                if (!$hidden || $myid == $entry['userid'] || $me->isModerator()) {
                    if (!$me->isModerator()) {
                        unset($entry['hidden']);
                    }

                    $filtered[] = $entry;
                }
            }
            
            if ($fillin) {
                $this->fillIn($filtered, $users, TRUE, FALSE);
            }
            
            foreach ($filtered as &$entry) {
                if ($fillin) {
                    # We return invisible entries - they are filtered on the client, and it makes the paging work.
                    if ($entry['visible'] &&
                        $last['userid'] == $entry['userid'] &&
                        $last['type'] == $entry['type'] &&
                        $last['message'] == $entry['message']) {
                        # Suppress duplicates.
                        $entry['visible'] = FALSE;
                    }
                }

                if (Utils::pres('replies', $entry)) {
                    $this->setThreadHead($entry['replies'], $entry['id']);
                }

                if (count($topitems) < 2 && ($entry['pinned'] || $entry['type'] !=  Newsfeed::TYPE_ALERT)) {
                    # We want to return pinned items at the top, and also the first non-alert one, so that
                    # we have interesting user-content at the top.
                    $topitems[] = $entry;
                } else {
                    $bottomitems[] = $entry;
                }

                if ($entry['pinned']) {
                    $pinned[] = $entry['id'];
                }

                $ctx = [
                    'timestamp' => Utils::ISODate($entry['timestamp']),
                    'distance' => $dist
                ];

                $last = $entry;
            }

            $ctx['pinned'] = implode(',', $pinned);
        }

        return([$users, array_merge($topitems, $bottomitems)]);
    }

    public function threadId() {
        return($this->feed['replyto'] ? $this->feed['replyto'] : $this->id);
    }

    public function refer($type) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $myid = $me ? $me->getId() : NULL;

        $referredid = $this->id;
        $userid = $this->feed['userid'];

        # Create a kind of comment and notify the poster.
        $id = $this->create($type, NULL, NULL, NULL, NULL, $this->id);
        $n = new Notifications($this->dbhr, $this->dbhm);
        $n->add($myid, $userid, Notifications::TYPE_COMMENT_ON_YOUR_POST, $this->id, $this->threadId());

        # Hide this post except to the author.
        $rc = $this->dbhm->preExec("UPDATE newsfeed SET hidden = NOW(), hiddenby = ? WHERE id = ?;", [
            $myid,
            $referredid
        ]);
    }

    public function like() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("INSERT IGNORE INTO newsfeed_likes (newsfeedid, userid) VALUES (?,?);", [
                $this->id,
                $me->getId()
            ]);

            # We want to notify the original poster.  The type depends on whether this was the start of a thread or
            # a comment on it.
            $n = new Notifications($this->dbhr, $this->dbhm);
            $n->add($me->getId(), $this->feed['userid'], $this->feed['replyto'] ? Notifications::TYPE_LOVED_COMMENT : Notifications::TYPE_LOVED_POST, $this->id, $this->threadId());
        }
    }

    public function unlike() {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("DELETE FROM newsfeed_likes WHERE newsfeedid = ? AND userid = ?;", [
                $this->id,
                $me->getId()
            ]);
        }
    }

    public function delete($notifstoo = TRUE) {
        $ret = FALSE;

        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("UPDATE newsfeed SET deleted = NOW(), deletedby = ? WHERE id = ?;", [
                $me->getId(),
                $this->id
            ]);

            if ($notifstoo) {
                # Don't want to show notifications to deleted items.
                $this->dbhm->preExec("DELETE FROM users_notifications WHERE newsfeedid = ?;", [
                    $this->id
                ]);
            }

            $ret = TRUE;
        }

        return $ret;
    }

    public function seen($userid) {
        # We don't want to mark an earlier item as seen, otherwise this will cause us to get another
        # digest mail.
        $seen = $this->dbhr->preQuery("SELECT * FROM newsfeed_users WHERE userid = ?;", [
            $userid
        ]);

        if (count($seen) == 0 || $seen[0]['newsfeedid'] < $this->id) {
            $this->dbhm->preExec("REPLACE INTO newsfeed_users (userid, newsfeedid) VALUES (?, ?);", [
                $userid,
                $this->id
            ]);
        }
    }

    public function getUnseen($userid) {
        # We used to call getFeed and process the results, but that had poor performance as we access this
        # unseen count on every page.
        $dist = $this->getNearbyDistance($userid);
        $u = User::get($this->dbhr, $this->dbhm, $userid);

        if ($userid) {
            # Get the last one seen if any.  Getting this makes the query below better indexed.
            $lasts = $this->dbhr->preQuery("SELECT newsfeedid FROM newsfeed_users WHERE userid = ?;", [
                $userid
            ]);

            $last = count($lasts) > 0 ? $lasts[0]['newsfeedid'] : 0;

            # We want the newsfeed items which are close to us.  Use the location in settings, or failing that the
            # last location they've posted from.
            list ($lat, $lng, $loc) = $u->getLatLng();

            # To use the spatial index we need to have a box.
            $ne = \GreatCircle::getPositionByDistance($dist, 45, $lat, $lng);
            $sw = \GreatCircle::getPositionByDistance($dist, 225, $lat, $lng);

            $box = "GeomFromText('POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))')";

            # We return most recent first.
            $first = $dist ? ("(MBRContains($box, position) OR `type` IN ('" . Newsfeed::TYPE_CENTRAL_PUBLICITY . "', '" . Newsfeed::TYPE_ALERT . "')) AND") : '';

            $sql = "SELECT COUNT(DISTINCT(newsfeed.id)) AS count FROM newsfeed LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = $userid LEFT JOIN newsfeed_users ON newsfeed_users.newsfeedid = newsfeed.id AND newsfeed_users.userid = $userid WHERE newsfeed.id > $last AND $first replyto IS NULL AND newsfeed.userid != $userid AND type = " . Newsfeed::TYPE_MESSAGE . " AND hidden IS NULL LIMIT 10;";

            # Don't return too many otherwise it's off-putting.
            $count = min(10, $this->dbhr->preQuery($sql)[0]['count']);
        }

        return($count);
    }

    public function report($reason) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        if ($me) {
            $this->dbhm->preExec("UPDATE newsfeed SET reviewrequired = 1 WHERE id = ?;", [
                $this->id
            ]);

            $this->dbhm->preExec("INSERT INTO newsfeed_reports (userid, newsfeedid, reason) VALUES (?, ?, ?);", [
                $me->getId(),
                $this->id,
                $reason
            ]);

            # Ask someone to take a look.
            $message = \Swift_Message::newInstance()
                ->setSubject($me->getName() . " #" . $me->getId() . "(" . $me->getEmailPreferred() . ") has reported a ChitChat thread")
                ->setFrom([GEEKS_ADDR])
                ->setTo([SUPPORT_ADDR => 'Freegle'])
                ->setBody("They reported this thread\r\n\r\nhttps://" . USER_SITE . "/chitchat/{$this->id}\r\n\r\n...with this message:\r\n\r\n$reason");

            list ($transport, $mailer) = Mail::getMailer();
            $this->sendIt($mailer, $message);
        }
    }

    private function snip(&$msg, $len = 117) {
        if ($msg) {
            $msg = str_replace("\n", ' ', $msg);
            if (strlen($msg) > $len && strpos($msg, "\n") !== FALSE) {
                $msg = substr($msg, 0, strpos(wordwrap($msg, $len + 3), "\n")) . '...';
            } else {
                $msg = substr($msg, 0, $len) . '...';
            }
        }
    }

    public function sendIt($mailer, $message) {
        $mailer->send($message);
    }

    public function digest($userid, $unseen = TRUE) {
        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        # We send a mail with unseen user-generated posts from quite nearby.
        $u = User::get($this->dbhr, $this->dbhm, $userid);
        $count = 0;

        $latlng = $u->getLatLng(FALSE);

        if ($u->sendOurMails() && ($latlng[0] || $latlng[1]) && $u->getSetting('notificationmails', TRUE)) {
            # We have a location for them.
            # Find the last one we saw.  Use master as we might have updated this for a previous group.
            $seens = $this->dbhm->preQuery("SELECT * FROM newsfeed_users WHERE userid = ?;", [
                $userid
            ]);

            $lastseen = 0;
            foreach ($seens as $seen) {
                $lastseen = $seen['newsfeedid'];
            }

            # Get the first few user-posted messages within 10 miles.
            $ctx = NULL;
            list ($users, $feeds) = $this->getFeed($userid, $this->getNearbyDistance($userid, 32187), [ Newsfeed::TYPE_MESSAGE, Newsfeed::TYPE_STORY, Newsfeed::TYPE_ABOUT_ME, Newsfeed::TYPE_NOTICEBOARD ], $ctx, TRUE);
            $textsumm = '';
            $twigitems = [];
            $max = 0;

            $oldest = Utils::ISODate(date("Y-m-d H:i:s", strtotime("14 days ago")));

            foreach ($feeds as &$feed) {
                #error_log("Compare {$feed['userid']} vs $userid, unseen $unseen, feed {$feed['id']} vs $lastseen, timestamp {$feed['timestamp']} vs $oldest");
                if ($feed['userid'] != $userid &&
                    (!$unseen || $feed['id'] > $lastseen) &&
                    $feed['timestamp'] > $oldest &&
                    (Utils::pres('message', $feed) || $feed['type'] == Newsfeed::TYPE_STORY) &&
                    !$feed['deleted']) {
                    $str = $feed['message'];

                    switch ($feed['type']) {
                        case Newsfeed::TYPE_ABOUT_ME: {
                            $str = '"' . $str . '"';
                            break;
                        }
                        case Newsfeed::TYPE_NOTICEBOARD: {
                            $n = json_decode($feed['message'], TRUE);

                            if (Utils::pres('name', $n)) {
                                $str = "I put up a poster for Freegle: \"{$n['name']}\"                           ";
                            }

                            break;
                        }
                        case Newsfeed::TYPE_STORY: {
                            $str = "Here's my Freegle story: " . $feed['story']['headline'] . ' - ' . $feed['story']['story'];
                            break;
                        }
                    }

                    # Strip emoji.
                    $str = preg_replace('/\\\\\\\\u(.*?)\\\\\\\\u/', '', $str);

                    $this->snip($str);
                    $feed['message'] = $str;

                    # Don't include short and dull ones.
                    if (strlen($str) > 40) {
                        $count++;

                        $short = $feed['message'];
                        $this->snip($short, 40);
                        $subj = '"' . $short . '" ' . " ($count conversation" . ($count != 1 ? 's' : '') . " from your neighbours)";
                        $subj = str_replace('""', '"', $subj);

                        $u = User::get($this->dbhr, $this->dbhm, $feed['userid']);
                        $fromname = $u->getName();
                        $feed['fromname'] = $fromname;
                        $feed['timestamp'] = date("D, jS F g:ia", strtotime($feed['timestamp']));

                        $textsumm .= $fromname . " posted '$str'\n";

                        if (Utils::pres('replies', $feed)) {
                            # Just keep the last five replies.
                            $feed['replies'] = array_slice($feed['replies'], -5);
                            #error_log("Got " . count($feed['replies']));

                            foreach ($feed['replies'] as &$reply) {
                                $u2 = User::get($this->dbhr, $this->dbhm, $reply['userid']);
                                $reply['fromname'] = $u2->getName();
                                $reply['timestamp'] = date("D, jS F g:ia", strtotime($reply['timestamp']));
                                $short2 = $reply['message'];
                                $this->snip($short2, 40);
                                $textsumm .= "  {$reply['fromname']}: $short2\n";
                            }
                        }

                        $textsumm .= "\n";

                        $twigitems[] = $feed;
                    }

                    #error_log("Consider max $max, {$feed['id']}, " . max($max, $feed['id']) );
                    $max = max($max, $feed['id']);
                }
            }

            if ($max && $max > $lastseen) {
                # Don't background this update otherwise we might send multiple digests for people who are on
                # multiple groups, if there is a background backlog.
                $this->dbhm->preExec("REPLACE INTO newsfeed_users (userid, newsfeedid) VALUES (?, ?);", [
                    $userid,
                    $max
                ]);
            }

            if ($count > 0) {
                # Got some to send
                $u = new User($this->dbhr, $this->dbhm, $userid);
                $url = $u->loginLink(USER_SITE, $userid, '/newsfeed', 'newsfeeddigest');
                $noemail = 'notificationmailsoff-' . $userid . "@" . USER_DOMAIN;

                $html = $twig->render('newsfeed/digest.html', [
                    'items' => $twigitems,
                    'settings' => $u->loginLink(USER_SITE, $u->getId(), '/settings', User::SRC_NEWSFEED_DIGEST),
                    'email' => $u->getEmailPreferred(),
                    'noemail' => $noemail,
                ]);

                $message = \Swift_Message::newInstance()
                    ->setSubject($subj)
                    ->setFrom([NOREPLY_ADDR => 'Freegle'])
                    ->setReturnPath($u->getBounce())
                    ->setTo([ $u->getEmailPreferred() => $u->getName() ])
                    ->setBody("Recent conversations from nearby freeglers:\r\n\r\n$textsumm\r\n\r\nPlease click here to read them: $url");

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($message, Mail::NEWSFEED, $u->getId());

                error_log("..." . $u->getEmailPreferred() . " send $count");
                list ($transport, $mailer) = Mail::getMailer();
                $this->sendIt($mailer, $message);
            }
        }

        return($count);
    }

    public function modnotif($userid, $timeago = "24 hours ago") {
        $count = 0;

        $loader = new \Twig_Loader_Filesystem(IZNIK_BASE . '/mailtemplates/twig');
        $twig = new \Twig_Environment($loader);

        $mod = User::get($this->dbhr, $this->dbhm, $userid);

        # We might or might not want these.
        if ($mod->getSetting('modnotifnewsfeed', TRUE) && $mod->sendOurMails() && $mod->getEmailPreferred() != MODERATOR_EMAIL) {
            # Find the last one we saw.  Use master as we might have updated this for a previous group.
            $seens = $this->dbhm->preQuery("SELECT * FROM newsfeed_users WHERE userid = ?;", [
                $userid
            ]);

            $lastseen = 0;
            foreach ($seens as $seen) {
                $lastseen = $seen['newsfeedid'];
            }

            # Find the groups that we're an active mod on.
            $feeds = [];
            $groups = $mod->getModeratorships();

            foreach ($groups as $groupid) {
                if ($mod->activeModForGroup($groupid)) {
                    # Find posts relating to this group or in its area.
                    $start = date('Y-m-d', strtotime($timeago));
                    $sql = "SELECT newsfeed.* FROM newsfeed INNER JOIN groups ON (newsfeed.groupid = groups.id OR MBRContains(groups.polyindex, newsfeed.position)) WHERE added >= ? AND newsfeed.id > ? AND groups.id = ? AND deleted IS NULL AND newsfeed.type IN (?, ?, ?) AND newsfeed.replyto IS NULL AND newsfeed.hidden IS NULL AND newsfeed.userid != ? ORDER BY added ASC";
                    $thislot = $this->dbhr->preQuery($sql, [
                        $start,
                        $lastseen,
                        $groupid,
                        Newsfeed::TYPE_MESSAGE,
                        Newsfeed::TYPE_STORY,
                        Newsfeed::TYPE_ABOUT_ME,
                        $userid
                    ]);

                    #error_log("Active mod for $groupid found " . count($thislot) . " from $sql, $start, $lastseen, $groupid");
                    $feeds = array_merge($feeds, $thislot);
                }
            }

            $feeds = array_unique($feeds, SORT_REGULAR);
            $textsumm = '';
            $twigitems = [];

            foreach ($feeds as &$feed) {
                #error_log("Compare {$feed['userid']} vs $userid, unseen $unseen, feed {$feed['id']} vs $lastseen, timestamp {$feed['timestamp']} vs $oldest");
                $count++;

                $str = $feed['message'];

                switch ($feed['type']) {
                    case Newsfeed::TYPE_ABOUT_ME: {
                        $str = '"' . $str . '"';
                        break;
                    }
                    case Newsfeed::TYPE_NOTICEBOARD: {
                        $str = 'put up a poster for Freegle';
                        break;
                    }
                    case Newsfeed::TYPE_STORY: {
                        $str = 'told their Freegle story';
                        break;
                    }
                }

                $this->snip($str);
                $feed['message'] = $str;

                $u = User::get($this->dbhr, $this->dbhm, $feed['userid']);
                $fromname = $u->getName();
                $feed['fromname'] = $fromname;
                $feed['timestamp'] = date("D, jS F g:ia", strtotime($feed['timestamp']));
                $feed['fromloc'] = $u->getPublicLocation()['display'];

                $textsumm .= $fromname . " posted '$str'\n";
                $textsumm .= "\n";
                $twigitems[] = $feed;
            }

            if ($count > 0) {
                # Got some to send
                $url = $mod->loginLink(USER_SITE, $userid, '/chitchat', 'newsfeeddigest');

                $subj = $count . " chitchat post" . ($count != 1 ? 's': '') . " from your members";

                $html = $twig->render('newsfeed/modnotif.html', [
                    'items' => $twigitems,
                    'settings' => $mod->loginLink(MOD_SITE, $mod->getId(), '/modtools/settings', User::SRC_NEWSFEED_DIGEST),
                    'email' => $mod->getEmailPreferred()
                ]);

                $message = \Swift_Message::newInstance()
                    ->setSubject($subj)
                    ->setFrom([NOREPLY_ADDR => 'Freegle'])
                    ->setReturnPath($mod->getBounce())
                    ->setTo([ $mod->getEmailPreferred() => $mod->getName() ])
                    ->setBody("Recent chitchat posts from your members:\r\n\r\n$textsumm\r\n\r\nPlease click here to read them: $url");

                # Add HTML in base-64 as default quoted-printable encoding leads to problems on
                # Outlook.
                $htmlPart = \Swift_MimePart::newInstance();
                $htmlPart->setCharset('utf-8');
                $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
                $htmlPart->setContentType('text/html');
                $htmlPart->setBody($html);
                $message->attach($htmlPart);

                Mail::addHeaders($message, Mail::NEWSFEED_MODNOTIF, $mod->getId());

                error_log("..." . $mod->getEmailPreferred() . " send $count");
                list ($transport, $mailer) = Mail::getMailer();
                $this->sendIt($mailer, $message);
            }
        }

        return($count);
    }

    public function mentions($myid, $query) {
        # Find the root of the thread.
        $threadid = $this->feed['replyto'] ? $this->feed['replyto'] : $this->id;

        # First find the people who have contributed to the thread.
        $userids = $this->dbhr->preQuery("SELECT DISTINCT userid FROM newsfeed WHERE (replyto = ? OR id = ?) AND userid != ?;", [
            $threadid,
            $threadid,
            $myid
        ]);

        $ret = [];

        foreach ($userids as $userid) {
            $u = User::get($this->dbhr, $this->dbhm, $userid['userid']);
            $name = $u->getName();

            if (!$query || strpos($name, $query) === 0) {
                $ret[] = [
                    'id' => $userid['userid'],
                    'displayname' => $u->getName()
                ];
            }
        }

        return($ret);
    }

    public function unfollow($userid, $newsfeedid) {
        $this->dbhm->preExec("REPLACE INTO newsfeed_unfollow (userid, newsfeedid) VALUES (?, ?);", [
            $userid,
            $newsfeedid
        ]);

        # Delete any notifications which refer to this newsfeed item or the thread
        $this->dbhm->preExec("DELETE FROM users_notifications WHERE touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?));", [
            $userid,
            $newsfeedid,
            $newsfeedid
        ]);
    }

    public function unhide($newsfeedid) {
        # Hide this post except to the author.
        $rc = $this->dbhm->preExec("UPDATE newsfeed SET hidden = NULL, hiddenby = NULL WHERE id = ?;", [
            $newsfeedid
        ]);
    }

    public function follow($userid, $newsfeedid) {
        $this->dbhm->preExec("DELETE FROM newsfeed_unfollow WHERE userid = ? AND newsfeedid = ?;", [
            $userid,
            $newsfeedid
        ]);
    }

    public function unfollowed($userid, $newsfeedid) {
        $unfollows = $this->dbhr->preQuery("SELECT id FROM newsfeed_unfollow WHERE userid = ? AND newsfeedid = ?;", [
            $userid,
            $newsfeedid
        ]);

        return(count($unfollows) > 0);
    }

    public function findRecent($userid, $type, $within = "24 hours ago") {
        $mysqltime = date("Y-m-d H:i:s", strtotime($within));
        $ns = $this->dbhm->preQuery("SELECT id FROM newsfeed WHERE timestamp >= ? AND userid = ? AND type = ? AND deleted IS NULL ORDER BY timestamp DESC;", [
            $mysqltime,
            $userid,
            $type
        ]);

        return(count($ns) > 0 ? $ns[0]['id'] : NULL);
    }

    public function deleteRecent($userid, $type, $within = "24 hours ago") {
        $mysqltime = date("Y-m-d H:i:s", strtotime($within));
        #error_log("UPDATE newsfeed SET deleted = NOW() WHERE timestamp >= '$mysqltime' AND userid = $userid AND type = '$type';");
        $this->dbhm->preExec("UPDATE newsfeed SET deleted = NOW() WHERE timestamp >= ? AND userid = ? AND type = ?;", [
            $mysqltime,
            $userid,
            $type
        ]);
    }
}
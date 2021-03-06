<?php

namespace Freegle\Iznik;

class MicroVolunteering
{
    private $dbhr, $dbhm;

    # Sometimes we make changes which affect the validity of previous data for the same tasks.  If so, note this here.
    # 1 - SearchTerm asking for 10 out of the top 1000 search terms.
    # 2 - SearchTerm asking for 10 out of the top 50 search terms.
    # 3 - Use item names instead of search terms.
    # 4 - Use top 300 items rather than top 100
    const VERSION = 4;

    const CHALLENGE_CHECK_MESSAGE = 'CheckMessage';
    const CHALLENGE_SEARCH_TERM = 'SearchTerm';  // No longer used.
    const CHALLENGE_ITEMS = 'Items';
    const CHALLENGE_FACEBOOK_SHARE = 'Facebook';
    const CHALLENGE_PHOTO_ROTATE = 'PhotoRotate';

    const RESULT_APPROVE = 'Approve';
    const RESULT_REJECT = 'Reject';

    const MSGCATEGORY_SHOULDNT_BE_HERE = 'ShouldntBeHere';
    const MSGCATEGORY_COULD_BE_BETTER = 'CouldBeBetter';
    const MSGCATEGORY_NOT_SURE = 'NotSure';

    # The number of people required to assume this is a good thing.  Note that the original poster did, too.
    const APPROVAL_QUORUM = 2;

    # The number of people we'll ask if there are differences of views.
    const DISSENTING_QUORUM = 3;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function list(&$ctx, $groupids, $limit = 10)
    {
        $groupq = implode(',', $groupids);
        $ctxq = $ctx ? (" AND microactions.id < " . intval($ctx['id'])) : '';
        $ctx = $ctx ? $ctx : [];

        $items = $this->dbhr->preQuery(
            "SELECT microactions.* FROM microactions INNER JOIN memberships ON memberships.userid = microactions.userid WHERE memberships.groupid IN ($groupq) $ctxq ORDER BY id DESC LIMIT " . intval(
                $limit
            )
        );

        if (count($items)) {
            $u = new User($this->dbhr, $this->dbhm);
            $a = new Attachment($this->dbhr, $this->dbhm);

            $users = $u->getPublicsById(array_filter(array_column($items, 'userid')), null, false, false, false, false);
            $msgids = array_filter(array_column($items, 'msgid'));
            $msgs = count($msgids) ? $this->dbhr->preQuery(
                "SELECT id, subject FROM messages WHERE id IN (" . implode(',', $msgids) . ")"
            ) : [];
            $imageids = array_filter(array_column($items, 'rotatedimage'));
            $imagemsgs = count($imageids) ? $this->dbhr->preQuery(
                "SELECT msgid, id FROM messages_attachments WHERE id IN (" . implode(',', $imageids) . ")"
            ) : [];

            $itemids = array_filter(array_merge(array_column($items, 'item1'), array_column($items, 'item2')));
            $is = count($itemids) ? $this->dbhr->preQuery(
                "SELECT id, name FROM items WHERE id IN (" . implode(',', $itemids) . ")"
            ) : [];

            for ($itemind = 0; $itemind < count($items); $itemind++) {
                $items[$itemind]['timestamp'] = Utils::ISODate($items[$itemind]['timestamp']);

                $items[$itemind]['user'] = $users[$items[$itemind]['userid']];
                unset($items[$itemind]['userid']);

                if (Utils::pres('msgid', $items[$itemind])) {
                    foreach ($msgs as $msg) {
                        if ($msg['id'] = $items[$itemind]['msgid']) {
                            $items[$itemind]['message'] = $msg;
                            unset($items[$itemind]['msgid']);
                        }
                    }
                }

                if (Utils::pres('item1', $items[$itemind]) && Utils::pres('item2', $items[$itemind])) {
                    foreach ($is as $i) {
                        if (Utils::pres('item1', $items[$itemind]) && gettype(
                                $items[$itemind]['item1']
                            ) != 'object' && $i['id'] == $items[$itemind]['item1']) {
                            $items[$itemind]['item1'] = $i;
                        }

                        if (Utils::pres('item2', $items[$itemind]) && gettype(
                                $items[$itemind]['item2']
                            ) != 'object' && $i['id'] == $items[$itemind]['item2']) {
                            $items[$itemind]['item2'] = $i;
                        }
                    }
                }

                if ($items[$itemind]['rotatedimage']) {
                    $items[$itemind]['rotatedimage'] = [
                        'id' => $items[$itemind]['rotatedimage'],
                        'thumb' => $a->getPath(true, $items[$itemind]['rotatedimage'])
                    ];

                    foreach ($imagemsgs as $imagemsg) {
                        if ($imagemsg['id'] == $items[$itemind]['rotatedimage']['id']) {
                            $items[$itemind]['rotatedimage']['msgid'] = $imagemsg['msgid'];
                        }
                    }
                }

                $ctx['id'] = $items[$itemind]['id'];
            }
        }

        return $items;
    }

    public function challenge($userid, $groupid = null, $types)
    {
        $ret = null;
        $today = date('Y-m-d');

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        if ($u->getPrivate('trustlevel') != User::TRUST_DECLINED) {
            $groupids = [$groupid];

            if (!$groupid) {
                # Get all their groups.
                $groupids = $u->getMembershipGroupIds(false, Group::GROUP_FREEGLE, $userid);
            }

            if ($u->getPrivate('trustlevel') == User::TRUST_MODERATE) {
                # Users with this trust level can review pending messages.
                $msgs = $this->dbhr->preQuery(
                    "SELECT messages_groups.msgid
    FROM messages_groups
    INNER JOIN messages ON messages.id = messages_groups.msgid
    INNER JOIN groups ON groups.id = messages_groups.groupid
    LEFT JOIN microactions ON microactions.msgid = messages_groups.msgid AND microactions.userid = ?    
    WHERE messages_groups.groupid IN (" . implode(',', $groupids) . " ) 
        AND fromuser != ?
        AND microvolunteering = 1
        AND messages.deleted IS NULL
        AND microactions.id IS NULL
        AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1)
    ORDER BY messages_groups.arrival ASC LIMIT 1",
                    [
                        $userid,
                        $userid
                    ]
                );

                foreach ($msgs as $msg) {
                    $ret = [
                        'type' => self::CHALLENGE_CHECK_MESSAGE,
                        'msgid' => $msg['msgid']
                    ];
                }
            }

            if (!$ret) {
                # Find the earliest message:
                # - on approved (in the spatial index)
                # - from the current day (use messages because we're interested in the first post).
                # - on one of these groups
                # - micro-volunteering is enabled on the group
                # - not from us
                # - not had a quorum of opinions
                # - not one we've seen
                # - still open
                # - on a group with this kind of microvolunteering enabled.
                #
                # We include explicitly moderated ones because this gives us data on how well they do.
                if (in_array(self::CHALLENGE_CHECK_MESSAGE, $types) &&
                    count($groupids)) {
                    $msgs = $this->dbhr->preQuery(
                        "SELECT messages_spatial.msgid,
       (SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid) AS reviewcount,
       (SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid AND result = ?) AS approvalcount
    FROM messages_spatial 
    INNER JOIN messages_groups ON messages_spatial.msgid = messages_groups.msgid
    INNER JOIN messages ON messages.id = messages_spatial.msgid
    INNER JOIN groups ON groups.id = messages_groups.groupid
    LEFT JOIN microactions ON microactions.msgid = messages_spatial.msgid AND microactions.userid = ?    
    LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_spatial.msgid
    WHERE messages_groups.groupid IN (" . implode(',', $groupids) . " ) 
        AND DATE(messages.arrival) = CURDATE()
        AND fromuser != ?
        AND microvolunteering = 1
        AND messages_outcomes.id IS NULL
        AND messages.deleted IS NULL
        AND microactions.id IS NULL
        AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1)
    HAVING approvalcount < ? AND reviewcount < ?
    ORDER BY messages_groups.arrival ASC LIMIT 1",
                        [
                            self::RESULT_APPROVE,
                            $userid,
                            $userid,
                            self::APPROVAL_QUORUM,
                            self::DISSENTING_QUORUM
                        ]
                    );

                    foreach ($msgs as $msg) {
                        $ret = [
                            'type' => self::CHALLENGE_CHECK_MESSAGE,
                            'msgid' => $msg['msgid']
                        ];
                    }
                }
            }

            if (!$ret && $u->hasFacebookLogin() && in_array(self::CHALLENGE_FACEBOOK_SHARE, $types)) {
                # Try sharing of Facebook post.
                $posts = $this->dbhr->preQuery(
                    "SELECT groups_facebook_toshare.* FROM groups_facebook_toshare 
    LEFT JOIN microactions ON microactions.facebook_post = groups_facebook_toshare.id AND microactions.userid = ? 
    WHERE DATE(groups_facebook_toshare.date) = CURDATE() AND microactions.id IS NULL ORDER BY date DESC LIMIT 1;",
                    [
                        $userid
                    ]
                );

                foreach ($posts as $post) {
                    $ret = [
                        'type' => self::CHALLENGE_FACEBOOK_SHARE,
                        'facebook' => $post
                    ];
                }
            }

            if (!$ret && in_array(self::CHALLENGE_PHOTO_ROTATE, $types)) {
                # Select 9 distinct random recent photos that we've not reviewed.

                $atts = $this->dbhr->preQuery(
                    "SELECT messages_attachments.id, 
       (SELECT COUNT(*) AS count FROM microactions WHERE rotatedimage = messages_attachments.id) AS reviewcount
    FROM messages_groups 
    INNER JOIN messages_attachments ON messages_attachments.msgid = messages_groups.msgid
    LEFT JOIN microactions ON microactions.rotatedimage = messages_attachments.id AND userid = ?
    INNER JOIN groups ON groups.id = messages_groups.groupid AND microvolunteering = 1 AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.photorotate') = 1)
    WHERE arrival >= ? AND groupid IN (" . implode(',', $groupids) . ") AND microactions.id IS NULL
    HAVING reviewcount < ?
    ORDER BY RAND() LIMIT 9;",
                    [
                        $userid,
                        $today,
                        self::DISSENTING_QUORUM
                    ]
                );

                if (count($atts)) {
                    $photos = [];
                    $a = new Attachment($this->dbhr, $this->dbhm);

                    foreach ($atts as $att) {
                        $photos[] = [
                            'id' => $att['id'],
                            'path' => $a->getPath(true, $att['id'])
                        ];
                    }

                    $ret = [
                        'type' => self::CHALLENGE_PHOTO_ROTATE,
                        'photos' => $photos
                    ];
                }
            }

            if (!$ret && in_array(self::CHALLENGE_SEARCH_TERM, $types)) {
                # Try pairing of popular item names.
                #
                # We choose 10 random distinct popular items, and ask which are related.
                $enabled = $this->dbhr->preQuery(
                    "SELECT memberships.id FROM memberships INNER JOIN groups ON memberships.groupid = groups.id WHERE memberships.userid = ? AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.wordmatch') = 1);",
                    [
                        $userid
                    ]
                );

                if (count($enabled)) {
                    $items = $this->dbhr->preQuery(
                        "SELECT DISTINCT id, term FROM (SELECT id, name AS term FROM items WHERE LENGTH(name) > 2 ORDER BY popularity DESC LIMIT 300) t ORDER BY RAND() LIMIT 10;"
                    );
                    $ret = [
                        'type' => self::CHALLENGE_SEARCH_TERM,
                        'terms' => $items
                    ];
                }
            }
        }

        return $ret;
    }

    public function responseCheckMessage($userid, $msgid, $result, $msgcategory, $comments)
    {
        if ($result == self::RESULT_APPROVE || $result == self::RESULT_REJECT) {
            # Insert might fail if message is deleted - timing window.
            $this->dbhm->preExec(
                "INSERT INTO microactions (userid, msgid, result, msgcategory, comments, version) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = ?, comments = ?, version = ?, msgcategory = ?;",
                [
                    $userid,
                    $msgid,
                    $result,
                    $msgcategory,
                    $comments,
                    self::VERSION,
                    $result,
                    $comments,
                    self::VERSION,
                    $msgcategory
                ]
            );

            if ($result == self::RESULT_REJECT) {
                # Check whether we have enough votes to make this message not visible.
                $votes = $this->dbhr->preQuery(
                    "SELECT COUNT(*) AS count FROM microactions WHERE msgid = ? AND result = ? AND comments IS NOT NULL AND (msgcategory IS NULL OR msgcategory = ?);",
                    [
                        $msgid,
                        self::RESULT_REJECT,
                        self::MSGCATEGORY_SHOULDNT_BE_HERE
                    ]
                );

                if ($votes[0]['count'] >= self::APPROVAL_QUORUM) {
                    $m = new Message($this->dbhr, $this->dbhm, $msgid);
                    $m->sendForReview("Members think there is something wrong with this message.");
                }
            }
        }
    }

    public function responseItems($userid, $item1, $item2)
    {
        try {
            $this->dbhm->preExec(
                "INSERT INTO microactions (userid, item1, item2, version) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE userid = userid, version = ?;",
                [
                    $userid,
                    $item1,
                    $item2,
                    self::VERSION,
                    self::VERSION
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function responseFacebook($userid, $postid, $result)
    {
        try {
            $this->dbhm->preExec(
                "INSERT IGNORE INTO microactions (userid, facebook_post, result, version) VALUES (?, ?, ?, ?);",
                [
                    $userid,
                    $postid,
                    $result,
                    self::VERSION
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function responsePhotoRotate($userid, $photoid, $result, $deg)
    {
        $ret = false;

        try {
            $this->dbhm->preExec(
                "INSERT IGNORE INTO microactions (userid, rotatedimage, result, version) VALUES (?, ?, ?, ?);",
                [
                    $userid,
                    $photoid,
                    $result,
                    self::VERSION
                ]
            );
        } catch (Exception $e) {
        }

        # Check whether we have enough votes to rotate this photo.
        $votes = $this->dbhr->preQuery(
            "SELECT COUNT(*) AS count FROM microactions WHERE rotatedimage = ? AND result = ?;",
            [
                $photoid,
                self::RESULT_REJECT
            ]
        );

        if ($votes[0]['count'] >= self::APPROVAL_QUORUM) {
            # We do.
            $a = new Attachment($this->dbhr, $this->dbhm, $photoid, Attachment::TYPE_MESSAGE);
            $data = $a->getData();
            $i = new Image($data);
            $i->rotate($deg);
            $newdata = $i->getData(100);
            $a->setData($newdata);
            $a->recordRotate();
            $ret = true;
        }

        return $ret;
    }

    public function score($since = "24 hours ago")
    {
        $mysqltime = date("Y-m-d", strtotime($since));
        $users = $this->dbhr->preQuery(
            "SELECT DISTINCT(userid) FROM microactions WHERE timestamp > ?",
            [
                $mysqltime
            ]
        );

        error_log(count($users) . " to scan");
        foreach ($users as $user) {
            # Find their volunteering actions involving messages
            $actions = $this->dbhr->preQuery(
                "SELECT * FROM microactions WHERE timestamp >= ? AND userid = ? AND msgid IS NOT NULL;",
                [
                    $mysqltime,
                    $user['userid']
                ]
            );

            foreach ($actions as $action) {
                $score_positive = 0;
                $score_negative = 0;

                # See if we can find other opinions on this action.
                if ($action['msgid']) {
                    $others = $this->dbhr->preQuery(
                        "SELECT * FROM microactions WHERE  timestamp >= ? AND msgid = ? AND userid != ? AND result IS NOT NULL;",
                        [
                            $mysqltime,
                            $action['msgid'],
                            $user['userid']
                        ]
                    );

                    if (count($others) > 1) {
                        # Found enough others for a quorum.
                        $verdict = [
                            $action['result'] => 1
                        ];

                        foreach ($others as $other) {
                            if (array_key_exists($other['result'], $verdict)) {
                                $verdict[$other['result']]++;
                            } else {
                                $verdict[$other['result']] = 1;
                            }
                        }

                        if (count($verdict)) {
                            arsort($verdict, SORT_NUMERIC);

                            $result = Utils::array_key_first($verdict);

                            if ($result == $action['result']) {
                                error_log("{$action['userid']} on {$action['id']} concurs verdict {$action['result']} vs $result from " . json_encode($verdict));
                                $score_positive = 1;
                            } else {
                                error_log("{$action['userid']} on {$action['id']} differs verdict {$action['result']} vs $result from " . json_encode($verdict));
                                $score_negative = 1;
                            }
                        }
                    }
                }

                # Update the scoring.
                if ($score_positive != $action['score_positive']) {
                    $this->dbhm->preExec("UPDATE microactions SET score_positive = ? WHERE id = ?;", [
                        $score_positive,
                        $action['id']
                    ]);
                }
                
                if ($score_negative != $action['score_negative']) {
                    $this->dbhm->preExec("UPDATE microactions SET score_negative = ? WHERE id = ?;", [
                        $score_negative,
                        $action['id']
                    ]);
                }
            }
        }
    }

    public function getScore($userid) {
        $ret = 0;

        $scores = $this->dbhr->preQuery("SELECT 100 * score_positive / (score_positive + score_negative) AS score FROM `microactions` WHERE userid = ? AND score_positive + score_negative > 0", [
            $userid
        ]);

        foreach ($scores as $score) {
            $ret = $score['score'];
        }

        return $ret;
    }

    public function promote($userid = NULL, $threshold = 90, $days = 7) {
        $count = 0;
        $userq = $userid ? (" AND `userid` = " . intval($userid) . " ") : '';

        # Find people who are currently on the basic level who have high accuracy.
        $users = $this->dbhr->preQuery("SELECT userid, 100 * score_positive / (score_positive + score_negative) AS score FROM microactions INNER JOIN users ON users.id = microactions.userid WHERE users.trustlevel = ? $userq GROUP BY userid HAVING score >= ?;", [
            User::TRUST_BASIC,
            $threshold
        ]);

        foreach ($users as $user) {
            # Check how long have done microvolunteering for.
            $durations = $this->dbhr->preQuery("SELECT DATEDIFF(MAX(timestamp), MIN(timestamp)) AS diff FROM microactions WHERE userid = ?;", [
                $user['userid']
            ]);

            foreach ($durations as $duration) {
                if ($duration['diff'] >= $days) {
                    # More than a week.
                    $this->dbhm->preExec("UPDATE users SET trustlevel = ? WHERE id = ?", [
                        User::TRUST_MODERATE,
                        $user['userid']
                    ]);

                    $count++;
                }
            }
        }

        return $count;
    }
}
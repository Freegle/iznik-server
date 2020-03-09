<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/misc/Stats.php');

# This gives us a summary of what we need to know for this user
class Dashboard {
    private $dbhr;
    private $dbhm;
    private $me;
    private $stats;

    const COMPONENT_RECENT_COUNTS = 'RecentCounts';
    const COMPONENT_POPULAR_POSTS = 'PopularPosts';
    const COMPONENT_USERS_POSTING = 'UsersPosting';
    const COMPONENT_USERS_REPLYING = 'UsersReplying';
    const COMPONENT_MODERATORS_ACTIVE = 'ModeratorsActive';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $me) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->me = $me;
        $this->stats = new Stats($dbhr, $dbhm);
    }

    private function getGroups($systemwide, $allgroups, $groupid, $region, $type, $start) {
        $groupids = [];
        $usecache = NULL;
        $typeq = $type ? " AND `type` = " . $this->dbhr->quote($type) : '';
        $startq = " AND start = " . $this->dbhr->quote($start);
        $userq = $this->me ? (" userid = " . $this->me->getId() . " AND ") : '';

        # Get the possible groups.
        if ($systemwide) {
            $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE publish = 1;");
            foreach ($groups as $group) {
                $groupids[] = $group['id'];
            }

            $usecache = "SELECT * FROM users_dashboard WHERE $userq systemwide = 1 $typeq $startq;";
        } else if ($region) {
            $groups = $this->dbhr->preQuery("SELECT groups.id FROM groups WHERE region LIKE ?;", [ $region ]);
            foreach ($groups as $group) {
                $groupids[] = $group['id'];
            }
        } else if ($groupid) {
            $groupids[] = $groupid;

            $usecache = "SELECT * FROM users_dashboard WHERE $userq groupid = " . intval($groupid) . " $typeq $startq;";
        } else if ($this->me && $allgroups) {
            $groupids1 = $this->me->getModeratorships();
            $groupids = [];

            # For the dashboard we only want to show the active groups.
            foreach ($groupids1 as $groupid) {
                if ($this->me->activeModForGroup($groupid)) {
                    $groupids[] = $groupid;
                }
            }

            $usecache = "SELECT * FROM users_dashboard WHERE userid = " . $this->me->getId() . " $typeq $startq;";
        }

        $groupids = count($groupids) == 0 ? [0] : $groupids;

        if ($type) {
            # Filter by type
            $groups = $this->dbhr->preQuery("SELECT id FROM groups WHERE id IN (" . implode(',', $groupids) . ") AND type = ?;", [ $type ]);
            $groupids = [0];
            foreach ($groups as $group) {
                $groupids[] = $group['id'];
            }
        }

        return [$usecache, $groupids];
    }

    public function get($systemwide, $allgroups, $groupid, $region, $type, $start = '30 days ago', $end = 'today', $force = FALSE, $key = NULL) {
        $ret = NULL;
        list ($usecache, $groupids) = $this->getGroups($systemwide, $allgroups, $groupid, $region, $type, $start);

        if ($usecache && !$force && $end === 'today') {
            $cached = $this->dbhr->preQuery($usecache);

            if (count($cached) > 0) {
                $ret = json_decode($cached[0]['data'], TRUE);
                $ret['cached'] = TRUE;
                $ret['cachedid'] = $cached[0]['id'];
                $ret['cachesql'] = $usecache;
            }
        }

        $new = FALSE;

        if (!$ret) {
            $new = TRUE;
            $ret = $this->stats->getMulti(date ("Y-m-d"), $groupids, $start, $end, $systemwide);
        }

        if ((($new && !$region) || $force) && ($end === 'today')) {
            # Save for next time.  Don't save regions.
            #
            # This will be updated via dashboard.php cron script once a day.
            #
            # Can't use a MySQL unique index on the separate values as some are NULL, and unique doesn't work well.
            #
            # Groupid might not be valid.
            $g = Group::get($this->dbhr, $this->dbhm, $groupid);

            $key = $key ? $key : ("$type-" . ($this->me ? $this->me->getId() : '') . "-$systemwide-$groupid-$start");
            $this->dbhm->preExec("REPLACE INTO users_dashboard (`key`, `type`, userid, systemwide, groupid, start, data) VALUES (?, ?, ?, ?, ?, ?, ?);", [
                $key,
                $type,
                $this->me ? $this->me->getId() : NULL,
                $systemwide,
                $g->getId() == $groupid ? $groupid : NULL,
                $start,
                json_encode($ret)
            ]);
        }

        if ($groupid) {
            if ($this->me && $this->me->isModerator()) {
                # For specific groups we return info about when mods were last active.
                $mods = $this->dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Moderator', 'Owner');", [ $groupid ]);
                $active = [];
                foreach ($mods as $mod) {
                    # A mod counts as active if they perform activity for this group on here, or if we know that they have
                    # approved a message (which might be on Yahoo).
                    $logs = $this->dbhr->preQuery("SELECT MAX(timestamp) AS lastactive FROM logs WHERE groupid = ? AND byuser = ?;", [ $groupid, $mod['userid']] );
                    $lastactive = $logs[0]['lastactive'];

                    if (!$lastactive) {
                        $approved = $this->dbhr->preQuery("SELECT MAX(arrival) AS lastactive FROM messages_groups WHERE groupid = ? AND approvedby = ?;", [ $groupid, $mod['userid']] );
                        $lastactive = $approved[0]['lastactive'];
                    }

                    $u = User::get($this->dbhr, $this->dbhm, $mod['userid']);

                    $active[$mod['userid']] = [
                        'displayname' => $u->getName(),
                        'lastactive' => $lastactive ? ISODate($lastactive) : NULL
                    ];
                }

                usort($active, function($mod1, $mod2) {
                    return(strcmp($mod2['lastactive'], $mod1['lastactive']));
                });

                $ret['modinfo'] = $active;
            }
        }

        # We also want to get the recent outcomes, where we know them.
        $mysqltime = date("Y-m-d", strtotime("Midnight 30 days ago"));
        $outcomes = $this->dbhr->preQuery("SELECT msgtype, messages_outcomes.outcome, COUNT(*) AS count FROM messages_groups INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid WHERE messages_groups.arrival >= ? AND groupid IN (" . implode(',', $groupids) . ") GROUP BY outcome, msgtype;", [
            $mysqltime
        ]);

        foreach ([Message::TYPE_OFFER, Message::TYPE_WANTED] as $type) {
            $ret['Outcomes'][$type] = [];

            foreach ($outcomes as $outcome) {
                if ($outcome['msgtype'] == $type) {
                    $ret['Outcomes'][$type][] = [
                        'outcome' => $outcome['outcome'],
                        'count' => $outcome['count']
                    ];
                }
            }
        }

        # And the total successful outcomes per month.
        $mysqltime = date("Y-m-01", strtotime("13 months ago"));
        $ret['OutcomesPerMonth'] = $this->dbhr->preQuery("SELECT * FROM stats_outcomes WHERE groupid IN (" . implode(',', $groupids) . ");", [
            Stats::OUTCOMES,
            $mysqltime
        ]);

        if ($groupid) {
            # Also get the donations this year.
            $mysqltime = date("Y-m-d H:i:s", strtotime("midnight 1st January this year"));

            $sql = "SELECT SUM(GrossAmount) AS total FROM users_donations INNER JOIN memberships ON users_donations.userid = memberships.userid AND memberships.groupid = ? WHERE users_donations.timestamp > '$mysqltime' AND payer != 'ppgfukpay@paypalgivingfund.org';";
            $donations = $this->dbhr->preQuery($sql, [ $groupid ]);
            $ret['donationsthisyear'] = $donations[0]['total'];

            # ...and this month
            $mysqltime = date("Y-m-d H:i:s", strtotime("midnight first day of this month"));

            $sql = "SELECT SUM(GrossAmount) AS total FROM users_donations INNER JOIN memberships ON users_donations.userid = memberships.userid AND memberships.groupid = ? WHERE users_donations.timestamp > '$mysqltime' AND payer != 'ppgfukpay@paypalgivingfund.org';";
            $donations = $this->dbhr->preQuery($sql, [ $groupid ]);
            $ret['donationsthismonth'] = $donations[0]['total'];
        }

        return($ret);
    }

    private function getCount($sql) {
        $res = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
        return $res[0]['count'];
    }

    public function getComponents($components, $systemwide, $allgroups, $groupid, $region, $type, $start = '30 days ago', $end = 'today', $force = FALSE, $key = NULL) {
        list ($usecache, $groupids) = $this->getGroups($systemwide, $allgroups, $groupid, $region, $type, $start);
        $startq = date("Y-m-d", strtotime($start));

        # End needs to be the next day.
        $endq = date("Y-m-d", strtotime($end) + 24 * 60 * 60);

        $ret = [];

        if (count($groupids)) {
            $groupq = " groupid IN (" . implode(', ', $groupids) . ") ";

            if (in_array(Dashboard::COMPONENT_RECENT_COUNTS, $components)) {
                # Use arrival on messages_groups as an initial filter, because it's better indexed, but we're interested
                # in genuinely new messages so we need to check messages.arrival, which is the first time we saw
                # the message.
                $messsql = "SELECT COUNT(*) AS count FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE messages_groups.arrival >= '$startq' AND messages_groups.arrival <= '$endq' AND $groupq AND messages.arrival >= '$startq' AND messages.arrival <= '$endq'";
                $membsql = "SELECT COUNT(*) AS count FROM memberships WHERE $groupq AND added >= '$startq' AND added <= '$endq'";

                $ret[Dashboard::COMPONENT_RECENT_COUNTS] = [
                    'newmembers' => $this->getCount($membsql),
                    'newmessages' => $this->getCount($messsql),
                    'membsql' => $membsql
                ];
            }

            if (in_array(Dashboard::COMPONENT_POPULAR_POSTS, $components)) {
                $populars = $this->dbhr->preQuery("SELECT COUNT(*) AS views, messages.id, messages.subject FROM messages INNER JOIN messages_likes ON messages_likes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE messages_groups.arrival >= '$startq' AND messages_groups.arrival <= '$endq' AND $groupq AND messages_likes.type = 'View' GROUP BY messages.id HAVING views > 0 ORDER BY views DESC LIMIT 5", NULL, FALSE, FALSE);

                if (count($populars)) {
                    $msgids = array_filter(array_column($populars, 'id'));
                    $replies = $this->dbhr->preQuery("SELECT COUNT(*) AS replies, refmsgid FROM chat_messages WHERE refmsgid IN (" . implode(',', $msgids) . ") GROUP BY refmsgid;", NULL, FALSE, FALSE);

                    foreach ($populars as &$popular) {
                        $popular['replies'] = 0;

                        foreach ($replies as $reply) {
                            if ($reply['refmsgid'] == $popular['id']) {
                                $popular['replies'] = $reply['replies'];
                            }
                        }

                        $popular['url'] = 'https://' . USER_SITE . '/message/' . $popular['id'];
                    }
                }

                $ret[Dashboard::COMPONENT_POPULAR_POSTS] = $populars;
            }

            if (in_array(Dashboard::COMPONENT_USERS_POSTING, $components)) {
                # Use arrival in messages_groups as a pre-filter since it's efficiently indexed as above.
                $postsql = "SELECT COUNT(*) AS count, messages.fromuser FROM messages WHERE id IN (SELECT msgid FROM messages_groups WHERE messages_groups.arrival >= '$startq' AND messages_groups.arrival <= '$endq' AND $groupq) AND messages.arrival >= '$startq' AND messages.arrival <= '$endq' GROUP BY messages.fromuser ORDER BY count DESC LIMIT 5";
                $postings = $this->dbhr->preQuery($postsql, NULL, FALSE, FALSE);
                $ret[Dashboard::COMPONENT_USERS_POSTING] = [];

                if (count($postings)) {
                    $u = new User($this->dbhr, $this->dbhm);
                    $ctx = NULL;
                    $users = $u->getPublicsById(array_filter(array_column($postings, 'fromuser')), NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE);

                    foreach ($postings as $posting) {
                        foreach ($users as $user) {
                            if ($user['id'] == $posting['fromuser']) {
                                $thisone = $user;
                                $thisone['posts'] = $posting['count'];
                                $thisone['postsql'] = $postsql;
                                $ret[Dashboard::COMPONENT_USERS_POSTING][] = $thisone;
                            }
                        }
                    }
                }
            }

            if (in_array(Dashboard::COMPONENT_USERS_REPLYING, $components)) {
                # We look for users who are replying to messages on our groups.
                $chatsql = "SELECT COUNT(*) AS count, chat_messages.userid 
FROM chat_messages 
INNER JOIN messages_groups ON messages_groups.msgid = chat_messages.refmsgid 
WHERE messages_groups.arrival >= '$startq' AND messages_groups.arrival <= '$endq' AND
                $groupq
                AND chat_messages.type = 'Interested' 
GROUP BY chat_messages.userid ORDER BY count DESC LIMIT 5";
                $replies = $this->dbhr->preQuery($chatsql, [
                    ChatMessage::TYPE_INTERESTED
                ], FALSE, FALSE);

                $ret[Dashboard::COMPONENT_USERS_REPLYING] = [];

                if (count($replies)) {
                    $u = new User($this->dbhr, $this->dbhm);
                    $ctx = NULL;
                    $users = $u->getPublicsById(array_filter(array_column($replies, 'userid')), NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE);

                    foreach ($replies as $reply) {
                        foreach ($users as $user) {
                            if ($user['id'] == $reply['userid']) {
                                $thisone = $user;
                                $thisone['replies'] = $reply['count'];
                                $ret[Dashboard::COMPONENT_USERS_REPLYING][] = $thisone;
                            }
                        }
                    }
                }
            }

            if (in_array(Dashboard::COMPONENT_MODERATORS_ACTIVE, $components) && count($groupids) == 1) {
                $mods = $this->dbhr->preQuery("SELECT userid FROM memberships WHERE groupid = ? AND role IN ('Moderator', 'Owner');", [ $groupids[0] ], FALSE, FALSE);

                $logs = $this->dbhr->preQuery("SELECT byuser, MAX(timestamp) AS lastactive FROM logs WHERE groupid = ? AND byuser IN (" . implode(',', array_filter(array_column($mods, 'userid'))) . ") GROUP BY byuser;", [ $groupids[0] ], FALSE, FALSE);
                $u = User::get($this->dbhr, $this->dbhm);

                $users = $u->getPublicsById(array_filter(array_column($logs, 'byuser')), NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE);

                foreach ($users as &$user) {
                    foreach ($logs as $log) {
                        if ($log['byuser'] == $user['id']) {
                            $user['lastactive'] = $log['lastactive'];
                        }
                    }
                }

                usort($users, function($mod1, $mod2) {
                    return(strcmp($mod2['lastactive'], $mod1['lastactive']));
                });

                $ret[Dashboard::COMPONENT_MODERATORS_ACTIVE] = $users;
            }
        }

        return($ret);
    }

    public function tearDown() {

    }
}
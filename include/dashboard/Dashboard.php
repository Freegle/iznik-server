<?php
namespace Freegle\Iznik;



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
    const COMPONENTS_ACTIVITY = 'Activity';
    const COMPONENTS_REPLIES = 'Replies';
    const COMPONENTS_APPROVED_MESSAGE_COUNT = 'ApprovedMessageCount';
    const COMPONENTS_MESSAGE_BREAKDOWN = 'MessageBreakdown';
    const COMPONENTS_WEIGHT = 'Weight';
    const COMPONENTS_OUTCOMES = 'Outcomes';
    const COMPONENTS_DONATIONS = 'Donations';
    const COMPONENTS_ACTIVE_USERS = 'ActiveUsers';
    const COMPONENTS_HAPPINESS = 'Happiness';
    const COMPONENTS_APPROVED_MEMBERS = 'ApprovedMemberCount';

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
                    # A mod counts as active if they perform activity for this group on here.
                    $logs = $this->dbhr->preQuery("SELECT MAX(timestamp) AS lastactive FROM logs WHERE groupid = ? AND byuser = ?;", [ $groupid, $mod['userid']] );
                    $lastactive = $logs[0]['lastactive'];

                    $u = User::get($this->dbhr, $this->dbhm, $mod['userid']);

                    $active[$mod['userid']] = [
                        'displayname' => $u->getName(),
                        'lastactive' => $lastactive ? Utils::ISODate($lastactive) : NULL
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
        $startq = date("Y-m-01", strtotime($start));
        $endq = date("Y-m-01", strtotime($end));
        $ret['OutcomesPerMonth'] = $this->dbhr->preQuery("SELECT * FROM stats_outcomes WHERE groupid IN (" . implode(',', $groupids) . ") AND date >= ? AND date <= ?;", [
            $startq,
            $endq
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

        $ismod = $this->me && $this->me->isModerator();

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
                    'newmessages' => $this->getCount($messsql)
                ];
            }

            if (in_array(Dashboard::COMPONENT_POPULAR_POSTS, $components)) {
                $populars = $this->dbhr->preQuery("SELECT COUNT(*) AS views, messages.id, messages.subject FROM messages INNER JOIN messages_likes ON messages_likes.msgid = messages.id INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.collection = ? WHERE messages_groups.arrival >= '$startq' AND messages_groups.arrival <= '$endq' AND $groupq AND messages_likes.type = 'View' GROUP BY messages.id HAVING views > 0 ORDER BY views DESC LIMIT 5", [
                        MessageCollection::APPROVED
                ]);

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

            if (in_array(Dashboard::COMPONENT_USERS_POSTING, $components) && $ismod) {
                # Use arrival in messages_groups as a pre-filter since it's efficiently indexed as above.
                $postsql = "SELECT COUNT(*) AS count, messages.fromuser FROM messages WHERE id IN (SELECT msgid FROM messages_groups WHERE messages_groups.arrival >= '$startq' AND messages_groups.arrival <= '$endq' AND $groupq) AND messages.arrival >= '$startq' AND messages.arrival <= '$endq' GROUP BY messages.fromuser ORDER BY count DESC LIMIT 5";
                $postings = $this->dbhr->preQuery($postsql, NULL, FALSE, FALSE);
                $ret[Dashboard::COMPONENT_USERS_POSTING] = [];

                if (count($postings)) {
                    $u = new User($this->dbhr, $this->dbhm);
                    $users = $u->getPublicsById(array_filter(array_column($postings, 'fromuser')), NULL, FALSE, FALSE, FALSE, FALSE);

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

            if (in_array(Dashboard::COMPONENT_USERS_REPLYING, $components) && $ismod) {
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
                ]);

                $ret[Dashboard::COMPONENT_USERS_REPLYING] = [];

                if (count($replies)) {
                    $u = new User($this->dbhr, $this->dbhm);
                    $users = $u->getPublicsById(array_filter(array_column($replies, 'userid')), NULL, FALSE, FALSE, FALSE, FALSE);

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

            if (in_array(Dashboard::COMPONENT_MODERATORS_ACTIVE, $components) && $ismod) {
                $modsql = "SELECT userid, groupid FROM memberships WHERE $groupq AND role IN ('Moderator', 'Owner');";
                $mods = $this->dbhr->preQuery($modsql, NULL, FALSE, FALSE);
                $modids = array_filter(array_column($mods, 'userid'));
                $u = User::get($this->dbhr, $this->dbhm);

                $users = $u->getPublicsById($modids, NULL, FALSE, FALSE, FALSE, FALSE);

                foreach ($users as &$user) {
                    foreach ($mods as $mod) {
                        if ($mod['userid'] == $user['id']) {
                            $user['groupid'][] = $mod['groupid'];
                        }
                    }

                    # Say that we were last active when we were last on the platform.  This isn't when we last
                    # moderated but it's a lot quicker to obtain, and this matters for load on the system.
                    $user['lastactive'] = $user['lastaccess'];
                }

                usort($users, function($mod1, $mod2) {
                        return (strcmp($mod2['lastactive'], $mod1['lastactive']));
                });

                $ret[Dashboard::COMPONENT_MODERATORS_ACTIVE] = $users;
            }

            if (in_array(Dashboard::COMPONENTS_ACTIVITY, $components)) {
                $ret = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [Stats::ACTIVITY]);
                $ret[Dashboard::COMPONENT_MODERATORS_ACTIVE] = $ret[Stats::ACTIVITY];
            }

            if (in_array(Dashboard::COMPONENTS_REPLIES, $components)) {
                $ret = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [Stats::REPLIES]);
                $ret[Dashboard::COMPONENTS_REPLIES] = $ret[Stats::REPLIES];
            }

            if (in_array(Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT, $components)) {
                $stats = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [ Stats::APPROVED_MESSAGE_COUNT ]);
                $ret[Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT] = $stats[Stats::APPROVED_MESSAGE_COUNT];
            }

            if (in_array(Dashboard::COMPONENTS_MESSAGE_BREAKDOWN, $components)) {
                $stats = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [ Stats::MESSAGE_BREAKDOWN]);
                $ret[Dashboard::COMPONENTS_MESSAGE_BREAKDOWN] = $stats[Stats::MESSAGE_BREAKDOWN];
            }

            if (in_array(Dashboard::COMPONENTS_WEIGHT, $components)) {
                $stats = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [Stats::WEIGHT]);
                $ret[Dashboard::COMPONENTS_WEIGHT] = $stats[Stats::WEIGHT];
            }

            if (in_array(Dashboard::COMPONENTS_OUTCOMES, $components)) {
                $stats = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [Stats::OUTCOMES]);
                $ret[Dashboard::COMPONENTS_OUTCOMES] = $stats[Stats::OUTCOMES];
            }

            if (in_array(Dashboard::COMPONENTS_DONATIONS, $components)) {
                if ($systemwide) {
                    $ret[Dashboard::COMPONENTS_DONATIONS] = $this->dbhr->preQuery("SELECT SUM(GrossAmount) AS count, DATE(timestamp) AS date FROM users_donations WHERE users_donations.timestamp >= ? AND users_donations.timestamp <= ? AND Payer NOT LIKE 'ppgfukpay@paypalgivingfund.org' GROUP BY date ORDER BY date ASC", [
                            $start,
                            $end
                    ]);
                } else {
                    $groupq = (" groupid IN (" . implode(', ', $groupids) . ") ");

                    $ret[Dashboard::COMPONENTS_DONATIONS] = $this->dbhr->preQuery("SELECT SUM(GrossAmount) AS count, DATE(timestamp) AS date FROM users_donations WHERE userid IN (SELECT DISTINCT userid FROM memberships WHERE $groupq) AND users_donations.timestamp >= ? AND users_donations.timestamp <= ? AND Payer NOT LIKE 'ppgfukpay@paypalgivingfund.org' GROUP BY date ORDER BY date ASC", [
                            $start,
                            $end
                        ]
                    );
                }
            }

            if (in_array(Dashboard::COMPONENTS_ACTIVE_USERS, $components) && $ismod) {
                $stats = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [ Stats::ACTIVE_USERS ]);
                $ret[Dashboard::COMPONENTS_ACTIVE_USERS] = $stats[Stats::ACTIVE_USERS];
            }

            if (in_array(Dashboard::COMPONENTS_APPROVED_MEMBERS, $components) && $ismod) {
                $stats = $this->stats->getMulti($start, $groupids, $start, $end, $systemwide, [ Stats::APPROVED_MEMBER_COUNT ]);
                $ret[Dashboard::COMPONENTS_APPROVED_MEMBERS] = $stats[Stats::APPROVED_MEMBER_COUNT];
            }
        }

        if (in_array(Dashboard::COMPONENTS_HAPPINESS, $components) && $ismod) {
            if ($groupid) {
                $groupq = $groupid ? (" AND messages_groups.groupid IN (" . implode(', ', $groupids) . ") ") : '';
                $sql = "SELECT COUNT(*) AS count, happiness FROM messages_outcomes INNER JOIN messages ON messages.id = messages_outcomes.msgid INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid INNER JOIN memberships ON messages.fromuser = memberships.userid WHERE timestamp >= '$startq' AND timestamp <= '$endq' $groupq AND happiness IS NOT NULL GROUP BY happiness ORDER BY count DESC;";
            } else {
                $sql = "SELECT COUNT(*) AS count, happiness FROM messages_outcomes WHERE timestamp >= '$startq' AND timestamp <= '$endq' AND happiness IS NOT NULL GROUP BY happiness ORDER BY count DESC;";
            }

            $ret[Dashboard::COMPONENTS_HAPPINESS] = $this->dbhr->preQuery($sql, [
                $start,
                $end
            ]);
        }

        return($ret);
    }
}
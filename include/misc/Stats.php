<?php
namespace Freegle\Iznik;



class Stats
{
    /** @var  $dbhr LoggedPDO */
    var $dbhr;
    /** @var  $dbhm LoggedPDO */
    var $dbhm;

    CONST APPROVED_MESSAGE_COUNT = 'ApprovedMessageCount';
    CONST APPROVED_MEMBER_COUNT = 'ApprovedMemberCount';
    CONST SPAM_MESSAGE_COUNT = 'SpamMessageCount';
    CONST SPAM_MEMBER_COUNT = 'SpamMemberCount';
    CONST MESSAGE_BREAKDOWN = 'MessageBreakdown';
    CONST POST_METHOD_BREAKDOWN = 'PostMethodBreakdown';
    CONST OUR_POSTING_BREAKDOWN = 'OurPostingBreakdown';
    CONST SUPPORTQUERIES_COUNT = 'SupportQueries';
    CONST FEEDBACK_HAPPY = 'Happy';
    CONST FEEDBACK_FINE = 'Fine';
    CONST FEEDBACK_UNHAPPY = 'Unhappy';
    CONST SEARCHES = 'Searches';
    CONST ACTIVITY = 'Activity';
    CONST WEIGHT = 'Weight';
    CONST OUTCOMES = 'Outcomes';
    CONST REPLIES = 'Replies';
    CONST ACTIVE_USERS = 'ActiveUsers'; // 30 day active window

    CONST TYPE_COUNT = 1;
    CONST TYPE_BREAKDOWN = 2;

    CONST HEATMAP_USERS = 'Users';
    CONST HEATMAP_MESSAGES = 'Messages';
    CONST HEATMAP_FLOW = 'Flow';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $groupid = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->groupid = $groupid;
    }

    public function setCount($date, $type, $val)
    {
        $this->dbhm->preExec("REPLACE INTO stats (date, groupid, type, count) VALUES (?, ?, ?, ?);",
            [
                $date,
                $this->groupid,
                $type,
                $val
            ]);
    }

    private function setBreakdown($date, $type, $val)
    {
        $this->dbhm->preExec("REPLACE INTO stats (date, groupid, type, breakdown) VALUES (?, ?, ?, ?);",
            [
                $date,
                $this->groupid,
                $type,
                $val
            ]);
    }

    public function generate($date, $type = NULL)
    {
        $activity = 0;

        if ($type === NULL || in_array(Stats::OUTCOMES, $type)) {
            $count = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(messages_outcomes.msgid)) AS count FROM messages_outcomes INNER JOIN messages_groups ON messages_outcomes.msgid = messages_groups.msgid WHERE groupid = ? AND messages_outcomes.timestamp >= ? AND DATE(messages_outcomes.timestamp) = ? AND messages_outcomes.outcome IN (?, ?);", [
                $this->groupid,
                $date,
                $date,
                Message::OUTCOME_TAKEN,
                Message::OUTCOME_RECEIVED
            ])[0]['count'];
            $this->setCount($date, Stats::OUTCOMES, $count);
        }

        if ($type === NULL || in_array(Stats::APPROVED_MESSAGE_COUNT, $type)) {
            # Counts are a specific day
            $count = $this->dbhr->preQuery("SELECT COUNT(DISTINCT(messageid)) AS count FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid WHERE groupid = ? AND messages.arrival >= ? AND DATE(messages.arrival) = ? AND collection = ?;",
                [
                    $this->groupid,
                    $date,
                    $date,
                    MessageCollection::APPROVED
                ])[0]['count'];
            $activity += $count;
            $this->setCount($date, Stats::APPROVED_MESSAGE_COUNT, $count);
        }

        if ($type === NULL || in_array(Stats::APPROVED_MEMBER_COUNT, $type)) {
            $this->setCount($date, Stats::APPROVED_MEMBER_COUNT,
                $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM memberships WHERE groupid = ? AND DATE(added) <= ? AND collection = ?;",
                    [
                        $this->groupid,
                        $date,
                        MembershipCollection::APPROVED
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::SPAM_MESSAGE_COUNT, $type)) {
            $this->setCount($date, Stats::SPAM_MESSAGE_COUNT,
                $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM `logs` WHERE timestamp >= ? AND DATE(timestamp) = ?  AND `groupid` = ? AND logs.type = 'Message' AND subtype = 'ClassifiedSpam';",
                    [
                        $date,
                        $date,
                        $this->groupid
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::SPAM_MEMBER_COUNT, $type)) {
            $this->setCount($date, Stats::SPAM_MEMBER_COUNT,
                $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM `logs` INNER JOIN spam_users ON logs.user = spam_users.userid AND collection = 'Spammer' WHERE groupid = ? AND logs.timestamp >= ? AND date(logs.timestamp) = ? AND logs.type = 'Group' AND `subtype` = 'Left';",
                    [
                        $this->groupid,
                        $date,
                        $date
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::SUPPORTQUERIES_COUNT, $type)) {
            $this->setCount($date, Stats::SUPPORTQUERIES_COUNT,
                $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_rooms WHERE created >= ? AND date(created) = ? AND groupid = ? AND chattype = ?;",
                    [
                        $date,
                        $date,
                        $this->groupid,
                        ChatRoom::TYPE_USER2MOD
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::FEEDBACK_HAPPY, $type)) {
            $this->setCount($date, Stats::FEEDBACK_HAPPY,
                $this->dbhr->preQuery("SELECT COUNT(DISTINCT(messages_outcomes.msgid)) AS count FROM messages_outcomes 
INNER JOIN messages ON messages_outcomes.msgid = messages.id 
INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.groupid = ? 
WHERE messages_outcomes.timestamp >= ? AND DATE(messages_outcomes.timestamp) = ? AND happiness = ?;",
                    [
                        $this->groupid,
                        $date,
                        $date,
                        Stats::FEEDBACK_HAPPY
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::FEEDBACK_FINE, $type)) {
            $this->setCount($date, Stats::FEEDBACK_FINE,
                $this->dbhr->preQuery("SELECT COUNT(DISTINCT(messages_outcomes.msgid)) AS count FROM messages_outcomes 
INNER JOIN messages ON messages_outcomes.msgid = messages.id 
INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.groupid = ? 
WHERE messages_outcomes.timestamp >= ? AND DATE(messages_outcomes.timestamp) = ? AND happiness = ?;",
                    [
                        $this->groupid,
                        $date,
                        $date,
                        Stats::FEEDBACK_FINE
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::FEEDBACK_UNHAPPY, $type)) {
            $this->setCount($date, Stats::FEEDBACK_UNHAPPY,
                $this->dbhr->preQuery("SELECT COUNT(DISTINCT(messages_outcomes.msgid)) AS count FROM messages_outcomes 
INNER JOIN messages ON messages_outcomes.msgid = messages.id 
INNER JOIN messages_groups ON messages_groups.msgid = messages.id AND messages_groups.groupid = ? 
WHERE messages_outcomes.timestamp >= ? AND DATE(messages_outcomes.timestamp) = ? AND happiness = ?;",
                    [
                        $this->groupid,
                        $date,
                        $date,
                        Stats::FEEDBACK_UNHAPPY
                    ])[0]['count']);
        }

        if ($type === NULL || in_array(Stats::POST_METHOD_BREAKDOWN, $type)) {
            # Message breakdowns take the previous 30 days
            $start = date('Y-m-d', strtotime("30 days ago", strtotime($date)));
            $end = date('Y-m-d', strtotime("tomorrow", strtotime($date)));

            $sql = "SELECT sourceheader AS source, COUNT(*) AS count FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid WHERE messages.arrival >= ? AND messages.arrival < ? AND groupid = ? AND collection = 'Approved' AND sourceheader IS NOT NULL GROUP BY sourceheader;";
            $sources = $this->dbhr->preQuery($sql,
                [
                    $start,
                    $end,
                    $this->groupid
                ]);

            $srcs = [];
            foreach ($sources as $src) {
                $srcs[$src['source']] = $src['count'];
            }

            $this->setBreakdown($date, Stats::POST_METHOD_BREAKDOWN, json_encode($srcs));
        }

        if ($type === NULL || in_array(Stats::MESSAGE_BREAKDOWN, $type)) {
            $sql = "SELECT messages.type, COUNT(*) AS count FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid AND collection = 'Approved' WHERE messages.arrival >= ? AND messages.arrival < ? AND groupid = ? AND collection = 'Approved' AND messages.type IS NOT NULL GROUP BY messages.type;";
            $sources = $this->dbhr->preQuery($sql,
                [
                    $start,
                    $end,
                    $this->groupid
                ]);

            $srcs = [];
            foreach ($sources as $src) {
                $srcs[$src['type']] = $src['count'];
            }

            $this->setBreakdown($date, Stats::MESSAGE_BREAKDOWN, json_encode($srcs));
        }

        if ($type === NULL || in_array(Stats::OUR_POSTING_BREAKDOWN, $type)) {
            $sql = "SELECT memberships.ourPostingStatus, COUNT(*) AS count FROM memberships WHERE groupid = ? GROUP BY memberships.ourPostingStatus;";
            $sources = $this->dbhr->preQuery($sql,
                [
                    $this->groupid
                ]);

            $srcs = [];
            foreach ($sources as $src) {
                $srcs[$src['ourPostingStatus']] = $src['count'];
            }

            $this->setBreakdown($date, Stats::OUR_POSTING_BREAKDOWN, json_encode($srcs));
        }

        if ($type === NULL || in_array(Stats::SEARCHES, $type)) {
            # Searches need a bit more work.  We're looking for searches which hit this group.
            $searches = $this->dbhr->preQuery("SELECT * FROM search_history WHERE date >= ? AND DATE(date) = ?;", [
                $date,
                $date
            ]);

            $count = 0;
            foreach ($searches as $search) {
                if ($search['groups']) {
                    $groups = explode(',', $search['groups']);
                    if (in_array($this->groupid, $groups)) {
                        $count++;
                    }
                }
            }
    
            $this->setCount($date, Stats::SEARCHES, $count);
        }

        if ($type === NULL || in_array(Stats::REPLIES, $type)) {
            # Any "Interested In" messages referring to messages on this group.
            #error_log("Generate searches SELECT COUNT(*) as count FROM chat_messages INNER JOIN messages_groups ON chat_messages.refmsgid = messages_groups.msgid WHERE chat_messages.date >= '$date' AND DATE(chat_messages.date) = '$date' AND chat_messages.type = 'Interested' AND groupid = {$this->groupid};");
            $replies = $this->dbhr->preQuery("SELECT COUNT(*) as count FROM chat_messages INNER JOIN messages_groups ON chat_messages.refmsgid = messages_groups.msgid WHERE chat_messages.date >= ? AND DATE(chat_messages.date) = ? AND chat_messages.type = ? AND groupid = ?;", [
                $date,
                $date,
                ChatMessage::TYPE_INTERESTED,
                $this->groupid
            ]);

            foreach ($replies as $reply) {
                $count = $reply['count'];
                $activity += $count;
                $this->setCount($date, Stats::REPLIES, $count);
            }
        }

        if ($type === NULL || in_array(Stats::WEIGHT, $type)) {
            # Weights also require more work.
            #
            # - Get the messages from the date
            # - For those with a suitable outcome
            #   - if we know a weight, then add it
            #   - if we don't know a weight, assume it's the average weight
            #
            # This will tail off a bit towards the current time as items won't be taken for a while.
            #
            # Similar code in getByAuthority.
            $avg = $this->dbhr->preQuery("SELECT SUM(popularity * weight) / SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0")[0]['average'];
            $sql = "SELECT DISTINCT messages_outcomes.msgid, weight, subject FROM messages_outcomes INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid INNER JOIN messages ON messages.id = messages_outcomes.msgid INNER JOIN messages_items ON messages_outcomes.msgid = messages_items.msgid LEFT JOIN items ON items.id = messages_items.itemid WHERE messages_outcomes.timestamp >= ? AND DATE(messages_outcomes.timestamp) = ? AND groupid = ? AND outcome IN ('Taken', 'Received');";
            $msgs = $this->dbhr->preQuery($sql, [
                $date,
                $date,
                $this->groupid
            ]);

            $weight = 0;
            foreach ($msgs as $msg) {
                #error_log("{$msg['msgid']} {$msg['subject']} {$msg['weight']}");
                $weight += $msg['weight'] ? $msg['weight'] : $avg;
            }
            $this->setCount($date, Stats::WEIGHT, $weight);
        }

        if ($type === NULL || in_array(Stats::ACTIVE_USERS, $type)) {
            $start = date('Y-m-d', strtotime("30 days ago", strtotime($date)));
            $end = date('Y-m-d', strtotime("tomorrow", strtotime($date)));
            $sql = "SELECT COUNT(DISTINCT(users_active.userid)) AS count FROM users_active INNER JOIN memberships ON memberships.userid = users_active.userid WHERE users_active.timestamp >= ? AND users_active.timestamp < ? AND groupid = ?;";
            $active = $this->dbhr->preQuery($sql, [
                $start,
                $end,
                $this->groupid,
            ]);

            $this->setCount($date, Stats::ACTIVE_USERS, $active[0]['count']);
        }

        if ($type === NULL || in_array(Stats::ACTIVITY, $type)) {
            $this->setCount($date, Stats::ACTIVITY, $activity);
        }
    }

    public function get($date)
    {
        $stats = $this->dbhr->preQuery("SELECT * FROM stats WHERE date = ? AND groupid = ?;", [ $date, $this->groupid ]);
        $ret = [
            Stats::APPROVED_MESSAGE_COUNT => 0,
            Stats::APPROVED_MEMBER_COUNT => 0,
            Stats::SPAM_MESSAGE_COUNT => 0,
            Stats::SPAM_MEMBER_COUNT => 0,
            Stats::SUPPORTQUERIES_COUNT => 0,
            Stats::FEEDBACK_FINE => 0,
            Stats::FEEDBACK_HAPPY => 0,
            Stats::FEEDBACK_UNHAPPY => 0,
            Stats::SEARCHES => 0,
            Stats::ACTIVITY => 0,
            Stats::WEIGHT => 0,
            Stats::REPLIES => 0,
            Stats::MESSAGE_BREAKDOWN => [],
            Stats::POST_METHOD_BREAKDOWN => [],
            Stats::ACTIVE_USERS => 0
        ];

        foreach ($stats as $stat) {
            switch ($stat['type']) {
                case Stats::APPROVED_MESSAGE_COUNT:
                case Stats::APPROVED_MEMBER_COUNT:
                case Stats::SPAM_MESSAGE_COUNT:
                case Stats::SUPPORTQUERIES_COUNT:
                case Stats::FEEDBACK_FINE:
                case Stats::FEEDBACK_HAPPY:
                case Stats::FEEDBACK_UNHAPPY:
                case Stats::SPAM_MEMBER_COUNT:
                case Stats::SEARCHES:
                case Stats::WEIGHT:
                case Stats::ACTIVITY:
                case Stats::REPLIES:
                case Stats::ACTIVE_USERS:
                    $ret[$stat['type']] = $stat['count'];
                    break;
                case Stats::MESSAGE_BREAKDOWN:
                case Stats::POST_METHOD_BREAKDOWN:
                    $ret[$stat['type']] = json_decode($stat['breakdown'], TRUE);
                    break;
            }
        }

        return ($ret);
    }

    function getMulti($date, $groupids, $startdate = "30 days ago", $enddate = "today", $systemwide = FALSE, $types = NULL) {
        # Get stats across multiple groups.
        $me = Session::whoAmI($this->dbhr, $this->dbhm);

        $ret = [];
        $ret['groupids'] = [];

        foreach ($groupids as $groupid) {
            if ($groupid) {
                $ret['groupids'][] = $groupid;
            }
        }

        $start = date('Y-m-d', strtotime($startdate, strtotime($date)));
        #error_log("Start at $start from $startdate");
        $end = date('Y-m-d', strtotime($enddate, strtotime($date)));
        #error_log("Start at $start from $startdate to $end from $enddate");

        if ($types === NULL) {
            $types = [
                Stats::APPROVED_MESSAGE_COUNT,
                Stats::APPROVED_MEMBER_COUNT,
                Stats::SEARCHES,
                Stats::ACTIVITY,
                Stats::WEIGHT,
                Stats::REPLIES
            ];

            if ($me && ($me->isModerator() || $me->isAdmin())) {
                # Mods can see more info.
                $types = [
                    Stats::APPROVED_MESSAGE_COUNT,
                    Stats::APPROVED_MEMBER_COUNT,
                    Stats::SPAM_MESSAGE_COUNT,
                    Stats::SPAM_MEMBER_COUNT,
                    Stats::SUPPORTQUERIES_COUNT,
                    Stats::FEEDBACK_HAPPY,
                    Stats::FEEDBACK_FINE,
                    Stats::FEEDBACK_UNHAPPY,
                    Stats::SEARCHES,
                    Stats::ACTIVITY,
                    Stats::WEIGHT,
                    Stats::REPLIES
                ];
            }
        }

        foreach ($types as $type) {
            $ret[$type] = [];
            #error_log("Check type $type " . "SELECT SUM(count) AS count, date FROM stats WHERE date >= '$start' AND date < '$end' AND groupid IN (" . implode(',', $groupids) . ") AND type = '$type' GROUP BY date;");

            # For many group values it's more efficient to use an index on date and type, so order the query accordingly.
            $sql = count($groupids) > 10 ? ("SELECT SUM(count) AS count, date FROM stats WHERE date >= ? AND date < ? AND type = ? AND groupid IN (" . implode(',', $groupids) . ") GROUP BY date;") : ("SELECT SUM(count) AS count, date FROM stats WHERE date >= ? AND date < ? AND groupid IN (" . implode(',', $groupids) . ") AND type = ? GROUP BY date;");
            $counts = $this->dbhr->preQuery($sql,
                [
                    # Activity stats only start from when we started tracking searches.
                    $type == Stats::ACTIVITY && strtotime($start) < strtotime('2016-12-21') ? '2016-12-21' : $start,
                    $end,
                    $type
                ]);

            foreach ($counts as $count) {
                $ret[$type][] = [ 'date' => $count['date'], 'count' => $count['count']];
            }
        }

        # Breakdowns we have to parse and sum the individual values.  Start from yesterday as we might not have complete
        # data for today.
        $types = [ Stats::MESSAGE_BREAKDOWN ];

        if (Session::modtools() && $me && ($me->isModerator() || $me->isAdmin())) {
            $types = [
                Stats::MESSAGE_BREAKDOWN
            ];
        }

        foreach ($types as $type) {
            $ret[$type] = [];

            $sql = "SELECT breakdown FROM stats WHERE type = ? AND date >= ? AND date < ? AND groupid IN (" . implode(',', $groupids) . ");";
            #error_log("SELECT breakdown FROM stats WHERE type = '$type' AND date >= '$start' AND date < '$end' AND groupid IN (" . implode(',', $groupids) . ");");

            # We can't use our standard preQuery wrapper, because it runs out of memory on very large queries (it
            # does a fetchall under the covers).
            $sth = $this->dbhr->_db->prepare($sql);
            $sth->execute([
                $type,
                $start,
                $end
            ]);

            while ($breakdown = $sth->fetch()) {
                $b = json_decode($breakdown['breakdown'], TRUE);
                foreach ($b as $key => $val) {
                    $ret[$type][$key] = !array_key_exists($key, $ret[$type]) ? $val : $ret[$type][$key] + $val;
                }
            }
        }

        if (Session::modtools() && $me && ($me->isModerator() || $me->isAdmin())) {
            $sql = "SELECT breakdown FROM stats WHERE type = ? AND date >= ? AND date < ? AND groupid IN (" . implode(',', $groupids) . ") ORDER BY date DESC LIMIT 1;";

            # We can't use our standard preQuery wrapper, because it runs out of memory on very large queries (it
            # does a fetchall under the covers).
            $sth = $this->dbhr->_db->prepare($sql);
            $sth->execute([
                Stats::POST_METHOD_BREAKDOWN,
                $start,
                $end
            ]);

            while ($breakdown = $sth->fetch()) {
                $b = json_decode($breakdown['breakdown'], TRUE);
                foreach ($b as $key => $val) {
                    $ret[Stats::POST_METHOD_BREAKDOWN][$key] = !array_key_exists($key, $ret[$type]) ? $val : $ret[$type][$key] + $val;
                }
            }
        }

        return($ret);
    }

    public function getHeatmap($type = Stats::HEATMAP_MESSAGES, $locname = NULL) {
        # We return counts per postcode.  Postcodes on average cover 15 properties, so there is some anonymity.
        # TODO Would be nice to only look at messages within the last year but that's not indexed well.
        $locnameq = $locname ? " AND locations.name = '$locname' LIMIT 1" : '';
        $mysqltime = date ("Y-m-d", strtotime("Midnight 1 year ago"));
        $sql = NULL;
        $areas = [];

        switch ($type) {
            case Stats::HEATMAP_USERS:
                $sql = "SELECT id, name, lat, lng, count FROM locations INNER JOIN (SELECT lastlocation, COUNT(*) AS count FROM users WHERE lastaccess > '$mysqltime' AND lastlocation IS NOT NULL GROUP BY lastlocation) t ON t.lastlocation = locations.id WHERE lat IS NOT NULL AND lng IS NOT NULL $locnameq;";
                $areas = $this->dbhr->preQuery($sql);
                break;
            case Stats::HEATMAP_MESSAGES:
                $sql = "SELECT id, name, lat, lng, count FROM locations INNER JOIN (SELECT locationid, COUNT(*) AS count FROM messages INNER JOIN locations ON locations.id = messages.locationid WHERE locationid IS NOT NULL AND locations.type = 'Postcode' AND INSTR(locations.name, ' ') GROUP BY locationid) t ON t.locationid = locations.id WHERE lat IS NOT NULL AND lng IS NOT NULL $locnameq;";
                $areas = $this->dbhr->preQuery($sql);
                break;
            case Stats::HEATMAP_FLOW:
                # We want the locations and counts of users who make an Offer which was taken.
                $start = date('Y-m-d', strtotime("365 days ago"));
                $sql = "SELECT locations.id, locations.name, locations.lat, locations.lng, COUNT(*) AS count FROM messages_by 
    INNER JOIN messages ON messages_by.msgid = messages.id
    INNER JOIN users ON users.id = messages.fromuser 
    INNER JOIN locations ON locations.id = users.lastlocation AND locations.type = 'Postcode' 
    WHERE messages_by.timestamp >= ?
    GROUP BY locations.id
    $locnameq";
                
                $donors = $this->dbhr->preQuery($sql, [
                    $start
                ]);

                error_log("Donors  " . var_export($donors, TRUE));

                $locs = [];
                
                foreach ($donors as $donor) {
                    $locs[$donor['id']] = $donor;
                }
                
                # And the locations and counts of users who have received the Offers.
                $sql = "  SELECT locations.id, locations.name, locations.lat, locations.lng, COUNT(*) AS count FROM messages_by
    INNER JOIN messages ON messages_by.msgid = messages.id
    INNER JOIN users ON users.id = messages_by.userid
    INNER JOIN locations ON locations.id = users.lastlocation AND locations.type = 'Postcode'
    WHERE messages_by.timestamp >= ?
    GROUP BY locations.id 
    $locnameq";
                $recipients = $this->dbhr->preQuery($sql, [
                    $start
                ]);

                foreach ($recipients as $recipient) {
                    if (array_key_exists($recipient['id'], $locs)) {
                        $locs[$recipient['id']]['count'] -= $recipient['count'];
                    } else {
                        $locs[$recipient['id']] = $recipient;
                        $locs[$recipient['id']]['count'] = -$locs[$recipient['id']]['count'];
                    }
                }

                # Now we return an array where the count is the net offers in this area.
                $areas = array_values($locs);
                break;
        }

        return($areas);
    }

    private function getFromPostcodeTable($start = "365 days ago", $end = "today") {
        $ret = [];
        $emptyStats = [
            Message::TYPE_OFFER => 0,
            Message::TYPE_WANTED => 0,
            Stats::SEARCHES => 0,
            Stats::WEIGHT => 0,
            Stats::REPLIES => 0,
            Stats::OUTCOMES => 0
        ];

        # Cover the last year.
        $start = date('Y-m-d', strtotime($start));
        $end = date('Y-m-d 23:59:59', strtotime($end));

        # Get the messages which we can identify as being within each of these postcodes.
        foreach([ Message::TYPE_OFFER, Message::TYPE_WANTED] as $type) {
            $stats = $this->dbhm->preQuery("SELECT SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, count(*) as count FROM pc 
                  INNER JOIN messages ON messages.locationid = pc.locationid INNER JOIN locations on messages.locationid = locations.id 
                  WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
                  AND messages.type = ? AND messages.arrival BETWEEN '$start' AND '$end'
                  GROUP BY PartialPostcode order by locations.name;", [
                $type
            ]);

            foreach ($stats as $stat) {
                $pc = $stat['PartialPostcode'];

                if (!array_key_exists($pc, $ret)) {
                    $ret[$pc] = $emptyStats;
                }

                $ret[$pc][$type] += $stat['count'];
            }
        }

        # Get the replies to which we can identify as being within each of these postcodes.
        foreach([ Message::TYPE_OFFER, Message::TYPE_WANTED] as $type) {
            $stats = $this->dbhm->preQuery("SELECT SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count FROM pc 
                  INNER JOIN messages ON messages.locationid = pc.locationid INNER JOIN locations on messages.locationid = locations.id 
                  INNER JOIN chat_messages cm on messages.id = cm.refmsgid AND cm.type = ?
                  WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
                  AND messages.type = ? AND messages.arrival BETWEEN '$start' AND '$end'
                  GROUP BY PartialPostcode order by locations.name;", [
                ChatMessage::TYPE_INTERESTED,
                $type
            ]);

            foreach ($stats as $stat) {
                $pc = $stat['PartialPostcode'];

                if (!array_key_exists($pc, $ret)) {
                    $ret[$pc] = $emptyStats;
                }

                $ret[$pc][Stats::REPLIES] += $stat['count'];
            }
        }

        # Get the count of the fulfilled requests.
        $avg = $this->dbhm->preQuery("SELECT SUM(popularity * weight) / SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0")[0]['average'];
        $avg = floatval($avg) ? $avg : 0;
        $stats = $this->dbhm->preQuery("SELECT SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, 
                  COUNT(*) AS count FROM pc 
                  INNER JOIN messages ON messages.locationid = pc.locationid
                  INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
                  INNER JOIN locations on messages.locationid = locations.id 
                  WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
                  AND messages.arrival BETWEEN '$start' AND '$end'
                  AND outcome IN (?, ?)
                  GROUP BY PartialPostcode order by locations.name;", [
            Message::OUTCOME_TAKEN,
            Message::OUTCOME_RECEIVED
        ]);

        foreach ($stats as $stat) {
            $pc = $stat['PartialPostcode'];

            if (!array_key_exists($pc, $ret)) {
                $ret[$pc] = $emptyStats;
            }

            $ret[$pc][Stats::OUTCOMES] += $stat['count'];
        }

        # Get the weights for the fulfilled requests.
        $avg = $this->dbhm->preQuery("SELECT SUM(popularity * weight) / SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0")[0]['average'];
        $avg = floatval($avg) ? $avg : 0;
        $stats = $this->dbhm->preQuery("SELECT SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, 
                  SUM(COALESCE(weight, $avg)) AS weight FROM pc 
                  INNER JOIN messages ON messages.locationid = pc.locationid
                  INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
                  INNER JOIN messages_items mi on messages.id = mi.msgid   
                  INNER JOIN items i on mi.itemid = i.id
                  INNER JOIN locations on messages.locationid = locations.id 
                  WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
                  AND messages.arrival BETWEEN '$start' AND '$end'
                  AND outcome IN (?, ?)
                  GROUP BY PartialPostcode order by locations.name;", [
            Message::OUTCOME_TAKEN,
            Message::OUTCOME_RECEIVED
        ]);

        foreach ($stats as $stat) {
            $pc = $stat['PartialPostcode'];

            if (!array_key_exists($pc, $ret)) {
                $ret[$pc] = $emptyStats;
            }

            $ret[$pc][Stats::WEIGHT] += $stat['weight'];
        }

        # Get searches.
        $stats = $this->dbhm->preQuery("SELECT SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, count(*) as count FROM pc 
          INNER JOIN search_history ON search_history.locationid = pc.locationid INNER JOIN locations on search_history.locationid = locations.id 
          WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0 AND search_history.date BETWEEN '$start' AND '$end'
          GROUP BY PartialPostcode order by locations.name;");

        foreach ($stats as $stat) {
            $pc = $stat['PartialPostcode'];
            if (!array_key_exists($pc, $ret)) {
                $ret[$pc] = $emptyStats;
            }

            $ret[$pc][Stats::SEARCHES] += $stat['count'];
        }

        $this->dbhm->preExec("DROP TEMPORARY TABLE pc");

        return($ret);
    }

    public function getByAuthority($authorityids, $start = "365 days ago", $end = "today") {
        $this->dbhm->preExec("DROP TEMPORARY TABLE IF EXISTS pc; CREATE TEMPORARY TABLE pc AS (SELECT locationid FROM authorities INNER JOIN `locations_spatial` on authorities.id IN (" . implode(',', $authorityids) . ") AND st_contains(authorities.polygon, locations_spatial.geometry));");

        return($this->getFromPostcodeTable($start, $end));
    }
}
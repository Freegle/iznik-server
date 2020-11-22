<?php
namespace Freegle\Iznik;

class MicroVolunteering
{
    private $dbhr, $dbhm;

    # Sometimes we make changes which affect the validity of previous data for the same tasks.  If so, note this here.
    # 1 - SearchTerm asking for 10 out of the top 1000 search terms.
    # 2 - SearchTerm asking for 10 out of the top 50 search terms.
    # 3 - Use item names instead of search terms.
    const VERSION = 3;

    const CHALLENGE_CHECK_MESSAGE = 'CheckMessage';
    const CHALLENGE_SEARCH_TERM = 'SearchTerm';  // No longer used.
    const CHALLENGE_ITEMS = 'Items';

    const RESULT_APPROVE = 'Approve';
    const RESULT_REJECT = 'Reject';

    # The number of people required to assime this is a good thing.  Note that the original poster did, too.
    const APPROVAL_QUORUM = 2;

    # The number of people we'll ask if there are differences of views.
    const DISSENTING_QUORUM = 3;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function challenge($userid, $groupid = NULL) {
        $ret = NULL;

        $u = User::get($this->dbhr, $this->dbhm, $userid);

        $groupids = [ $groupid ];

        if (!$groupid) {
            # Get all their groups.
            $groupids = $u->getMembershipGroupIds(FALSE, Group::GROUP_FREEGLE, $userid);
        }

        # Find the earliest message:
        # - on approved (in the spatial index)
        # - not explicitly moderated
        # - from the current day (use messages because we're interested in the first post).
        # - on one of these groups
        # - micro-volunteering is enabled on the group
        # - not from us
        # - not had a quorum of opinions
        # - not one we've seen
        # - still open
        if (count($groupids)) {
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
    WHERE groupid IN (" . implode(',', $groupids) . " ) 
        AND DATE(messages.arrival) = CURDATE()
        AND approvedby IS NULL  
        AND fromuser != ?
        AND microvolunteering = 1
        AND messages_outcomes.id IS NULL
        AND messages.deleted IS NULL
        AND microactions.id IS NULL
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

        if (!$ret) {
            # Didn't find a message to approve.  Try pairing of popular item names.
            #
            # We choose 10 random distinct popular items, and ask which are related.
            $items = $this->dbhr->preQuery("SELECT DISTINCT id, term FROM (SELECT id, name AS term FROM items WHERE LENGTH(name) > 2 ORDER BY popularity DESC LIMIT 100) t ORDER BY RAND() LIMIT 10;");
            $ret = [
                'type' => self::CHALLENGE_SEARCH_TERM,
                'terms' => $items
            ];
        }

        return $ret;
    }

    public function responseCheckMessage($userid, $msgid, $result, $comments) {
        if ($result == self::RESULT_APPROVE || $result == self::RESULT_REJECT) {
            # Insert might fail if message is deleted - timing window.
            $this->dbhm->preExec("INSERT INTO microactions (userid, msgid, result, comments, version) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = ?, comments = ?, version = ?;", [
                $userid,
                $msgid,
                $result,
                $comments,
                self::VERSION,
                $result,
                $comments,
                self::VERSION
            ]);
        }
    }

    public function responseItems($userid, $item1, $item2) {
        try {
            $this->dbhm->preExec("INSERT INTO microactions (userid, item1, item2, version) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE userid = userid, version = ?;", [
                $userid,
                $item1,
                $item2,
                self::VERSION,
                self::VERSION
            ]);
        } catch (Exception $e) {}
    }
}
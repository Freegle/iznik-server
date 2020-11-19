<?php
namespace Freegle\Iznik;

class MicroVolunteering
{
    private $dbhr, $dbhm;

    const CHALLENGE_CHECK_MESSAGE = 'CheckMessage';

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

        return $ret;
    }

    public function response($userid, $msgid, $result, $comments) {
        if ($result == self::RESULT_APPROVE || $result == self::RESULT_REJECT) {
            # Insert might fail if message is deleted - timing window.
            $this->dbhm->preExec("INSERT INTO microactions (userid, msgid, result, comments) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = ?, comments = ?;", [
                $userid,
                $msgid,
                $result,
                $comments,
                $result,
                $comments
            ]);
        }
    }
}
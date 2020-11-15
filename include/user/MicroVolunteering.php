<?php
namespace Freegle\Iznik;

class MicroVolunteering
{
    private $dbhr, $dbhm;

    const CHALLENGE_CHECK_MESSAGE = 'CheckMessage';

    const RESULT_APPROVE = 'Approve';
    const RESULT_REJECT = 'Reject';

    const QUORUM = 3;

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
        # - from the current day
        # - on one of these groups
        # - not from us
        # - not had a quorum of opinions.
        if (count($groupids)) {
            $msgs = $this->dbhr->preQuery("SELECT messages_spatial.msgid FROM messages_spatial 
INNER JOIN messages_groups ON messages_spatial.msgid = messages_groups.msgid
INNER JOIN messages ON messages.id = messages_spatial.msgid 
WHERE groupid IN (" . implode(',', $groupids) . " ) 
    AND DATE(messages_groups.arrival) = CURDATE()
    AND approvedby IS NULL  
    AND fromuser != ?
ORDER BY messages_groups.arrival ASC", [
    $userid
            ]);

            foreach ($msgs as $msg) {
                # Check for quorum and not shown to this user.
                $quorum = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM microactions WHERE msgid = ? AND userid != ?;", [
                    $msg['msgid'],
                    $userid
                ]);

                if ($quorum[0]['count'] < self::QUORUM) {
                    $ret = [
                        'type' => self::CHALLENGE_CHECK_MESSAGE,
                        'msgid' => $msg['msgid']
                    ];

                    break;
                }
            }
        }

        return $ret;
    }

    public function response($userid, $msgid, $result) {
        if ($result == self::RESULT_APPROVE || $result == self::RESULT_REJECT) {
            # Insert might fail if message is deleted - timing window.
            $this->dbhm->preExec("REPLACE INTO microactions (userid, msgid, result) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE result = ?;", [
                $userid,
                $msgid,
                $result,
                $result
            ]);
        }
    }
}
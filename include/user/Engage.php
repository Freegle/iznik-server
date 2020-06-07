<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');

class Engage
{
    private $dbhr, $dbhm;

    const FILTER_DONORS = 'Donors';

    const ATTEMPT_MISSING = 'Missing';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }


    public function tearDown() {

    }

    public function findUsers($id = NULL, $filter) {
        $userids = [];

        if ($filter == Engage::FILTER_DONORS) {
            # Find people who have donated in the last year, who have not been active in the last two months.
            $donatedsince = date("Y-m-d", strtotime("3 years ago"));
            $activesince = date("Y-m-d", strtotime("2 months ago"));
            $lastengage = date("Y-m-d", strtotime("1 month ago"));
            $uq = $id ? " AND users_donations.userid = $id " : "";
            $sql = "SELECT DISTINCT users_donations.userid, lastaccess FROM users_donations INNER JOIN users ON users.id = users_donations.userid LEFT JOIN engage ON engage.userid = users.id WHERE users_donations.timestamp >= ? AND users.lastaccess <= ? AND (engage.timestamp IS NULL OR engage.timestamp < ?) $uq;";
            $users = $this->dbhr->preQuery($sql, [
                $donatedsince,
                $activesince,
                $lastengage
            ]);

            $userids = array_column($users, 'userid');
        }

        return $userids;
    }

    public function recordEngage($userid, $attempt) {
        $this->dbhm->preExec("INSERT INTO engage (userid, type, timestamp) VALUES (?, ?, NOW());", [
            $userid,
            $attempt
        ]);
    }

    public function checkSuccess($id = NULL) {
        $since = date("Y-m-d", strtotime("1 month ago"));
        $uq = $id ? " AND engage.userid = $id " : "";
        $sql = "SELECT engage.id, userid, lastaccess FROM engage INNER JOIN users ON users.id = engage.userid WHERE engage.timestamp >= ? AND engage.timestamp <= users.lastaccess AND succeeded IS NULL $uq;";
        $users = $this->dbhr->preQuery($sql, [
            $since
        ], FALSE, FALSE);

        $count = 0;

        foreach ($users as $user) {
            $count++;
            $this->dbhm->preExec("UPDATE engage SET succeeded = ? WHERE id = ?;", [
                $user['lastaccess'],
                $user['id']
            ]);
        }

        return $count;
    }
}

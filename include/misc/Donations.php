<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');

class Donations
{
    const PERIOD_THIS = 'This';
    const PERIOD_SINCE = 'Since';
    const PERIOD_FUTURE = 'Future';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $groupid = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->groupid = $groupid;
    }

    public function get() {
        $target = $this->groupid ? $this->dbhr->preQuery("SELECT fundingtarget FROM groups WHERE id = {$this->groupid};")[0]['fundingtarget'] : DONATION_TARGET;
        $ret = [
            'target' => $target
        ];

        $mysqltime = date("Y-m-d", strtotime('first day of this month'));
        $groupq = $this->groupid ? " INNER JOIN memberships ON users_donations.userid = memberships.userid AND groupid = {$this->groupid} " : '';

        $totals = $this->dbhr->preQuery("SELECT SUM(GrossAmount) AS raised FROM users_donations $groupq WHERE timestamp >= ? AND payer != 'ppgfukpay@paypalgivingfund.org';", [
            $mysqltime
        ]);
        $ret['raised'] = $totals[0]['raised'];
        return($ret);
    }

    public function recordAsk($userid) {
        $this->dbhm->preExec("INSERT INTO users_donations_asks (userid) VALUES (?);", [ $userid ]);
    }

    public function lastAsk($userid) {
        $ret = NULL;

        $asks = $this->dbhr->preQuery("SELECT MAX(timestamp) AS max FROM users_donations_asks WHERE userid = ?;", [
            $userid
        ]);

        foreach ($asks as $ask) {
            $ret = $ask['max'];
        }

        return($ret);
    }

    public function getGiftAid($userid) {
        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE userid = ? AND deleted IS NULL;", [
            $userid
        ], FALSE, FALSE);

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = ISODate($giftaid['timestamp']);
        }

        return count($giftaids) ? $giftaids[0] : NULL;
    }

    public function listGiftAidReview() {
        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE reviewed IS NULL ORDER BY timestamp ASC;", NULL, FALSE, FALSE);

        $uids = array_column($giftaids, 'userid');
        $u = new User($this->dbhr, $this->dbhm);
        $emails = $u->getEmailsById($uids);

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = ISODate($giftaid['timestamp']);
            $giftaid['email'] = presdef($giftaid['userid'], $emails, NULL);
        }

        return $giftaids;

    }
    public function countGiftAidReview() {
        $giftaids = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM giftaid WHERE reviewed IS NULL ORDER BY timestamp ASC;", NULL, FALSE, FALSE);
        return $giftaids[0]['count'];
    }

    public function editGiftAid($id, $period, $fullname, $homeaddress, $reviewed) {
        if ($period) {
            $this->dbhm->preExec("UPDATE giftaid SET period = ? WHERE id = ?;", [
                $period,
                $id
            ]);
        }

        if ($fullname) {
            $this->dbhm->preExec("UPDATE giftaid SET fullname = ? WHERE id = ?;", [
                $fullname,
                $id
            ]);
        }

        if ($homeaddress) {
            $this->dbhm->preExec("UPDATE giftaid SET homeaddress = ? WHERE id = ?;", [
                $homeaddress,
                $id
            ]);
        }

        if ($reviewed) {
            $this->dbhm->preExec("UPDATE giftaid SET reviewed = NOW() WHERE id = ?;", [
                $id
            ]);
        }
    }

    public function setGiftAid($userid, $period, $fullname, $homeaddress) {
        $this->dbhm->preExec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE period = ?, fullname = ?, homeaddress = ?, deleted = NULL;", [
            $userid,
            $period,
            $fullname,
            $homeaddress,
            $period,
            $fullname,
            $homeaddress
        ]);
    }

    public function deleteGiftAid($userid) {
        $this->dbhm->preExec("UPDATE giftaid SET deleted = NOW() WHERE userid = ?", [
            $userid
        ]);
    }
}
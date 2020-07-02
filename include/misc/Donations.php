<?php

require_once(IZNIK_BASE . '/include/utils.php');

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

        return count($giftaids) ? $giftaids[0] : NULL;
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
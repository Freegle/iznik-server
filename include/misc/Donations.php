<?php
namespace Freegle\Iznik;



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

    public function add($eid, $email, $name, $date, $txnid, $gross) {
        $this->dbhm->preExec("INSERT INTO users_donations (userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE userid = ?, timestamp = ?;", [
            $eid,
            $email,
            $name,
            $date,
            $txnid,
            $gross,
            $eid,
            $date
        ]);

        return $this->dbhm->lastInsertId();
    }

    public function delete($id) {
        $this->dbhm->preExec("DELETE FROM users_donations WHERE id = ?;", [
            $id
        ]);
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
        ]);

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = Utils::ISODate($giftaid['timestamp']);
        }

        return count($giftaids) ? $giftaids[0] : NULL;
    }

    public function listGiftAidReview() {
        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE reviewed IS NULL AND deleted IS NULL ORDER BY timestamp ASC;", NULL, FALSE, FALSE);

        $uids = array_column($giftaids, 'userid');
        $u = new User($this->dbhr, $this->dbhm);
        $emails = $u->getEmailsById($uids);

        foreach ($giftaids as &$giftaid) {
            $giftaid['timestamp'] = Utils::ISODate($giftaid['timestamp']);
            $giftaid['email'] = Utils::presdef($giftaid['userid'], $emails, NULL);
        }

        return $giftaids;

    }
    public function countGiftAidReview() {
        $giftaids = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM giftaid WHERE reviewed IS NULL AND deleted IS NULL ORDER BY timestamp ASC;", NULL, FALSE, FALSE);
        return $giftaids[0]['count'];
    }

    public function editGiftAid($id, $period, $fullname, $homeaddress, $postcode, $housenameornumber, $reviewed, $deleted) {
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

        if ($postcode) {
            $this->dbhm->preExec("UPDATE giftaid SET postcode = ? WHERE id = ?;", [
                $postcode,
                $id
            ]);
        }

        if ($housenameornumber) {
            $this->dbhm->preExec("UPDATE giftaid SET housenameornumber = ? WHERE id = ?;", [
                $housenameornumber,
                $id
            ]);
        }

        if ($reviewed) {
            $this->dbhm->preExec("UPDATE giftaid SET reviewed = NOW() WHERE id = ?;", [
                $id
            ]);
        }

        if ($deleted) {
            $this->dbhm->preExec("UPDATE giftaid SET deleted = NOW() WHERE id = ?;", [
                $id
            ]);
        }
    }

    public function setGiftAid($userid, $period, $fullname, $homeaddress) {
        $this->dbhm->preExec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), period = ?, fullname = ?, homeaddress = ?, deleted = NULL;", [
            $userid,
            $period,
            $fullname,
            $homeaddress,
            $period,
            $fullname,
            $homeaddress
        ]);

        return $this->dbhm->lastInsertId();
    }

    public function deleteGiftAid($userid) {
        $this->dbhm->preExec("UPDATE giftaid SET deleted = NOW() WHERE userid = ?", [
            $userid
        ]);
    }

    public function identifyGiftAidedDonations($id = NULl) {
        $idq = $id ? " AND id = $id " : '';
        $found = 0;

        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE reviewed IS NOT NULL $idq;");

        foreach ($giftaids as $giftaid) {
            switch ($giftaid['period']) {
                case Donations::PERIOD_SINCE: {
                    # Earliest we can claim is 6th April 2016.
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= '2016-04-06';", [
                        $giftaid['userid']
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                }

                case Donations::PERIOD_THIS: {
                    # Only donations on the same day.
                    $mysqltime = date("Y-m-d", strtotime($giftaid['timestamp']));
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= '2016-04-06' AND date(timestamp) = ?;", [
                        $giftaid['userid'],
                        $mysqltime
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                }

                case Donations::PERIOD_FUTURE: {
                    # Only donations on or after the day of consent.
                    $mysqltime = date("Y-m-d", strtotime($giftaid['timestamp']));
                    $this->dbhm->preExec("UPDATE users_donations SET giftaidconsent = 1 WHERE userid = ? AND giftaidconsent = 0 AND timestamp >= '2016-04-06' AND date(timestamp) >= ?;", [
                        $giftaid['userid'],
                        $mysqltime
                    ]);

                    $found += $this->dbhm->rowsAffected();
                    break;
                }
            }
        }

        return $found;
    }

    public function identifyGiftAidPostcode($id = NULL) {
        $idq = $id ? " AND id = $id " : '';
        $found = 0;

        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE postcode IS NULL AND deleted IS NULL $idq;");
        $a = new Address($this->dbhr, $this->dbhm);

        foreach ($giftaids as $giftaid) {
            $addresses = $a->listForUser($giftaid['userid']);
            $possible = NULL;

            foreach ($addresses as $address) {
                $pc = $address['postcode']['name'];

                if (stripos($giftaid['homeaddress'], $pc) !== FALSE) {
                    # We've found the postcode in one of their addresses, so we can record it.
                    $possible = $pc;
                    break;
                }
            }

            if (!$possible) {
                # We didn't find it in one of their addresses.  See if we can find a postcode using the government
                # regex;
                if (preg_match('/([Gg][Ii][Rr] 0[Aa]{2})|((([A-Za-z][0-9]{1,2})|(([A-Za-z][A-Ha-hJ-Yj-y][0-9]{1,2})|(([A-Za-z][0-9][A-Za-z])|([A-Za-z][A-Ha-hJ-Yj-y][0-9][A-Za-z]?))))\s?[0-9][A-Za-z]{2})/mi', $giftaid['homeaddress'], $matches)) {
                    $possible = strtoupper($matches[0]);
                }
            }

            if ($possible) {
                $l = new Location($this->dbhr, $this->dbhm);
                $locs = $l->typeahead($possible);

                if (count($locs)) {
                    $found++;
                    $this->dbhm->preExec("UPDATE giftaid SET postcode = ? WHERE id = ?;", [
                        $locs[0]['name'],
                        $giftaid['id']
                    ]);
                }
            }
        }

        return $found;
    }

    public function identifyGiftAidHouse($id = NULL) {
        $idq = $id ? " AND id = $id " : '';
        $found = 0;

        $giftaids = $this->dbhr->preQuery("SELECT * FROM giftaid WHERE housenameornumber IS NULL AND deleted IS NULL $idq;");

        foreach ($giftaids as $giftaid) {
            # Look for a house number, possibly with a letter, e.g. 13a.
            #error_log("Check {$giftaid['homeaddress']}");
            if (preg_match('/^([\d\/\\-]+[a-z]{0,1})[\w\s]/im', $giftaid['homeaddress'], $matches)) {
                $number = trim($matches[0]);
                #error_log("Found $number " . var_export($matches, TRUE));

                $this->dbhm->preExec("UPDATE giftaid SET housenameornumber = ? WHERE id = ?;", [
                    $number,
                    $giftaid['id']
                ]);

                $found++;
            }
        }

        return $found;
    }
}
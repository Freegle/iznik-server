<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$start = '2018-04-06';
$min = 0;
$total = 0;
$soon = 0;
$uids = [];
$delay = 90;

$giftaids = $dbhr->preQuery("SELECT DISTINCT(giftaid.userid) FROM giftaid INNER JOIN users_donations ON users_donations.userid = giftaid.userid WHERE users_donations.timestamp >= '$start';");

foreach ($giftaids as $giftaid) {
    # Find type of declaraction.
    $uid = $giftaid['userid'];
    $u = User::get($dbhr, $dbhm, $uid);
    $email = $u->getEmailPreferred();

    if ($email) {
        $declaration = $dbhr->preQuery("SELECT * FROM giftaid WHERE userid = ?;", [
            $uid
        ])[0];
        $type = $declaration['period'];
        $declaredat = $declaration['timestamp'];

        #error_log("$uid $type $declaredat");

        if ($type == Donations::PERIOD_FUTURE) {
            // We might have donations which were recent enough to be claimable, but where the declaration doesn't cover them
            $donations = $dbhr->preQuery("SELECT * FROM users_donations WHERE userid = ? AND timestamp >= ? AND timestamp < ? AND giftaidconsent = 0 AND GrossAmount > ?;", [
                $uid,
                $start,
                $declaredat,
                $min
            ]);

            foreach ($donations as $donation) {
                $gap = round((strtotime($declaredat) - strtotime($donation['timestamp'])) / 3600 / 24);
                error_log("$uid $email donated {$donation['GrossAmount']} on {$donation['timestamp']} not covered by 'Future' declaration made at $declaredat gap $gap");
                $total += $donation['GrossAmount'];

                if ($gap <= $delay) {
                    $soon += $donation['GrossAmount'];
                }

                $uids[$uid] = TRUE;
            }
        } else if ($type == Donations::PERIOD_THIS) {
            // We might have donations made on other dates which could be claimable.
            $donations = $dbhr->preQuery("SELECT * FROM users_donations WHERE userid = ? AND timestamp >= ? AND DATE(timestamp) != DATE(?) AND giftaidconsent = 0 AND GrossAmount > ?;", [
                $uid,
                $start,
                $declaredat,
                $min
            ]);

            foreach ($donations as $donation) {
                error_log("$uid $email donated {$donation['GrossAmount']} on {$donation['timestamp']} not covered by 'This' declaration made at $declaredat");
                $total += $donation['GrossAmount'];

                if ($gap <= $delay) {
                    $soon += $donation['GrossAmount'];
                }

                $uids[$uid] = TRUE;
            }
        }
    }
}

error_log("\n\nPotential $total from " . count(array_keys($uids)));
error_log("Soon $soon");

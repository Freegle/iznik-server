<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/misc/Donations.php');

# Set up any missing postcodes and houses we can identify.
$d = new Donations($dbhr, $dbhm);
$count = $d->identifyGiftAidPostcode();
$count = $d->identifyGiftAidHouse();

# Mark any donations we can as having gift aid consent.
$d->identifyGiftAidedDonations();

# Find all donations on which we could claim gift aid, but haven't.
$donations = $dbhr->preQuery("SELECT users_donations.*, giftaid.fullname, giftaid.postcode, giftaid.housenameornumber FROM `users_donations` INNER JOIN giftaid ON users_donations.userid = giftaid.userid WHERE giftaidconsent = 1 AND giftaidclaimed IS NULL AND giftaid.deleted IS NULL AND giftaid.reviewed IS NOT NULL AND GrossAmount > 0 ORDER BY users_donations.timestamp ASC;");

fputcsv(STDOUT, [
    'Title',
    'First name or initial',
    'Last name',
    'House name or number',
    'Postcode',
    'Aggregated donations',
    'Sponsored event',
    'Donation date',
    'Amount'
]);

$invalid = 0;
$total = 0;

foreach ($donations as $donation) {
    $p = strpos($donation['fullname'], ' ');

    if ($p === FALSE || !$donation['housenameornumber'] || !$donation['postcode']) {
        # Invalid.  Bounce back for review
        error_log("Invalid donation " . json_encode($donation));
        $dbhm->preExec("UPDATE giftaid SET reviewed = NULL WHERE userid = ?", [
            $donation['userid']
        ]);

        $invalid++;
    } else {
        fputcsv(STDOUT, [
            '',
            substr($donation['fullname'], 0, $p),
            substr($donation['fullname'], $p + 1),
            $donation['housenameornumber'],
            $donation['postcode'],
            '',
            '',
            date("d/m/y", strtotime($donation['timestamp'])),
            $donation['GrossAmount']
        ]);

        $total += $donation['GrossAmount'];

//        $dbhm->preExec("UPDATE users_donations SET giftaidclaimed = NOW() WHERE id = ?;", [
//            $donation['id']
//        ]);
    }
}

error_log("Invalid $invalid total $total");
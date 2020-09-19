<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

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

# Exclude donations from the same user for the same amount on the same day.  Some of these may be legitimate but
# some may be duplicates.
$dups = [];

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
        $date = date("d/m/y", strtotime($donation['timestamp']));
        $key = "{$donation['userid']}-{$donation['GrossAmount']}-$date";

        if (!Utils::pres($key, $dups)) {
            $dups[$key] = TRUE;

            fputcsv(STDOUT, [
                '',
                substr($donation['fullname'], 0, $p),
                substr($donation['fullname'], $p + 1),
                $donation['housenameornumber'] . "\t",  // Add tab to force quoting.
                $donation['postcode'],
                '',
                '',
                $date,
                $donation['GrossAmount']
            ]);

            $total += $donation['GrossAmount'];
        } else {
            error_log("Skip duplicate donation from {$donation['fullname']} #{$donation['userid']} on $date");
        }

        $dbhm->preExec("UPDATE users_donations SET giftaidclaimed = NOW() WHERE id = ?;", [
            $donation['id']
        ]);
    }
}

error_log("Invalid $invalid total $total");
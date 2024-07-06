<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$donations = $dbhr->preQuery(
    "SELECT users_donations.*, giftaid.id AS giftaidid FROM `users_donations` 
    LEFT JOIN giftaid ON users_donations.userid = giftaid.userid 
    WHERE users_donations.timestamp >= '2023-02-08' AND users_donations.timestamp <= '2023-02-27' AND GrossAmount > 0 
    AND source = 'DonateWithPayPal' ORDER BY users_donations.timestamp ASC;"
);

fputcsv(STDOUT, [
    'Freegle User ID',
    'Freegle Email',
    'Freegle Name',
    'PayPal Date',
    'PayPal Email',
    'PayPal Name',
    'PayPal TransactionID',
    'PayPal Amount',
    'GiftAid ID',
    'GiftAid Consent',
    'GiftAid Claimed',
]);

# Exclude donations from the same user for the same amount on the same day.  Some of these may be legitimate but
# some may be duplicates.
$dups = [];

foreach ($donations as $donation) {
    $date = date("d/m/y", strtotime($donation['timestamp']));
    $key = "{$donation['TransactionID']}";

    if (!Utils::pres($key, $dups)) {
        $dups[$key] = true;

        $u = User::get($dbhr, $dbhm, $donation['userid']);

        fputcsv(STDOUT, [
            $donation['userid'],
            $u->getEmailPreferred(),
            $u->getName(),
            $donation['timestamp'],
            $donation['Payer'],
            $donation['PayerDisplayName'],
            $donation['TransactionID'],
            $donation['GrossAmount'],
            $donation['giftaidid'],
            $donation['giftaidconsent'],
            $donation['giftaidclaimed'],
        ]);
    }
}
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$start = '2023-02-08';
$end = '2023-02-27';
$bstart = '2023-01-08';
$bend = '2023-01-27';

$donations = $dbhr->preQuery("SELECT DATE(timestamp) AS date, userid, Payer, GrossAmount, `source`, TransactionType FROM users_donations 
                                                                               WHERE timestamp >= ? AND timestamp < ?
                                                                               AND source = 'DonateWithPayPal'  
                                                                               GROUP BY TransactionID
                                                                               ORDER BY timestamp ASC;", [
    $start,
    $end
]);

$dates = [];
$total = 0;
$totalNewRecurring = 0;
$totalExistingRecurring = 0;
$totalOneOff = 0;

foreach ($donations as $donation) {
    if (!Utils::pres($donation['date'], $dates)) {
        $dates[$donation['date']] = [
            0,
            0,
            0
        ];
    }

    if ($donation['source'] == 'DonateWithPayPal' && ($donation['TransactionType'] == 'recurring_payment' || $donation['TransactionType'] == 'subscr_payment')) {
        $previousSame = $dbhr->preQuery("SELECT COUNT(*) AS count FROM users_donations WHERE (userid = ? OR Payer = ?) AND source = ? AND TransactionType IN (?,?) AND timestamp >= ? AND timestamp < ? AND GrossAmount = ?", [
            $donation['userid'],
            $donation['Payer'],
            'DonateWithPayPal',
            'recurring_payment',
            'subscr_payment',
            $bstart,
            $bend,
            $donation['GrossAmount']
        ]);

        $previousDiff = $dbhr->preQuery("SELECT COUNT(*) AS count, AVG(GrossAmount) AS avg FROM users_donations WHERE (userid = ? OR Payer = ?) AND source = ? AND TransactionType IN (?,?) AND timestamp >= ? AND timestamp < ? AND GrossAmount != ?", [
            $donation['userid'],
            $donation['Payer'],
            'DonateWithPayPal',
            'recurring_payment',
            'subscr_payment',
            $bstart,
            $bend,
            $donation['GrossAmount']
        ]);

        if ($previousSame[0]['count']) {
            # Existing recurring.
            $dates[$donation['date']][1] += $donation['GrossAmount'];
        } else if ($previousDiff[0]['count']) {
            # Modified recurring.  Count the difference.
            #$dates[$donation['date']][0] += $donation['GrossAmount'];
            error_log("Changed to {$donation['GrossAmount']}  from {$previousDiff[0]['avg']}");
            $dates[$donation['date']][0] += $donation['GrossAmount'] - $previousDiff[0]['avg'];
            $dates[$donation['date']][1] += $previousDiff[0]['avg'];
        } else {
            # New recurring.
            $dates[$donation['date']][0] += $donation['GrossAmount'];
        }
    } else {
        # One-off
        $dates[$donation['date']][2] += $donation['GrossAmount'];
    }

    $total += $donation['GrossAmount'];
}

echo "Date,FirstRecurring,ExistingRecurring,OneOff\n";

foreach ($dates as $date => $amount) {
    echo("$date,{$amount[0]}, {$amount[1]}, {$amount[2]}\n");

    $totalNewRecurring += $amount[0];
    $totalExistingRecurring += $amount[1];
    $totalOneOff += $amount[2];
}

$totalNewRecurring = $totalNewRecurring;
$totalOneOff = $totalOneOff;
echo("\n\n£$totalNewRecurring/month new/modified recurring donations\n£$totalOneOff one-off donations\n£$totalExistingRecurring existing recurring\nTotal £$total, restricted £" . ($totalOneOff + $totalNewRecurring) . "\n");
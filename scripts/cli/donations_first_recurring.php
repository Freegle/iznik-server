<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$donations = $dbhr->preQuery("SELECT DATE(timestamp) AS date, userid, GrossAmount, `source`, TransactionType FROM users_donations WHERE timestamp >= '2023-02-08' ORDER BY timestamp ASC;");

$dates = [];
$users = [];
$total = 0;
$totalNewRecurring = 0;
$totalOneOff = 0;

foreach ($donations as $donation) {
    if (!Utils::pres($donation['date'], $dates)) {
        $dates[$donation['date']] = [
            0,
            $donation['GrossAmount']
        ];
    } else {
        $dates[$donation['date']][1] += $donation['GrossAmount'];
    }

    if ($donation['source'] == 'DonateWithPayPal' && $donation['TransactionType'] == 'recurring_payment') {
        if (!Utils::pres($donation['userid'], $users)) {
            $users[$donation['userid']] = TRUE;
            $dates[$donation['date']][0] += $donation['GrossAmount'];
            $dates[$donation['date']][1] -= $donation['GrossAmount'];
        }
    }
}

echo "Date,FirstRecurring,TotalExcludingFirstRecurring\n";

foreach ($dates as $date => $amount) {
    echo("$date,{$amount[0]}, {$amount[1]}\n");

    $totalNewRecurring += $amount[0];
    $totalOneOff += $amount[1];
    $total += $amount[0] * 12 + $amount[1];
}

$total = round($total);
$totalNewRecurring = round($totalNewRecurring);
$totalOneOff = round($totalOneOff);
echo("\n\nEstimated benefit of this campaign over the next year:\n  £$total total, from £$totalNewRecurring/month new recurring donations plus £$totalOneOff one-off donations.\n  " . round(100 * $total / 24000) . "% of £24K target.\n");
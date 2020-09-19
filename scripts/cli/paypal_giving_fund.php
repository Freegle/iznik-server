<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:');

if (count($opts) != 1) {
    echo "Usage: php paypal_giving_fund.php -f <CSV file>)\n";
} else {
    $fn = Utils::presdef('f', $opts, NULL);
    $fh = fopen($fn, 'r');
    $u = new User($dbhr, $dbhm);

    if ($fh) {
        # These donations don't have a transaction ID.  That makes it tricky since we want to rerun this job
        # repeatedly without double-counting.  So we delete donations within the date range of the CSV file
        # before readding them.  That means we need to know what the date range is.
        $donations = [];
        $mindate = NULL;
        $minepoch = PHP_INT_MAX;
            
        while (!feof($fh)) {
            # Format is:
            #
            # date	donorName	donorEmail	program	currencyCode	amount
            $fields = fgetcsv($fh);

            $date = $fields[0];
            $name = $fields[1];
            $email = $fields[2];
            $program = $fields[3];
            $campaign = $fields[4];
            $ebayId = $fields[5];
            $amount = $fields[7];

            # Invent a unique transaction ID because we might rerun on the same data.  This isn't perfect - anonymous
            # donations on the same date from PayPal will lead to the same id, and therefore get undercounted.  But
            # undercounting is probably better than overcounting for soliciting donations...
            $txid = $date . $email . $program . $campaign . $ebayId . $amount;

            error_log("$date email $email amount $amount");

            if ($email) {
                # Not anonymous
                $eid = $u->findByEmail($email);
            }

            $donations[] = [
                'eid' => $eid,
                'email' => $email,
                'name' => $name,
                'date' => $date,
                'txid' => $txid,
                'amount' => $amount,
                'program' => $program,
                'campaign' => $campaign,
                'ebayId' => $ebayId
            ];
            
            $epoch = strtotime($date);

            if ($amount > 0 && $amount < 10000) {
                # Ignore debits, otherwise we'll delete old donations.  This will mean that cancelled donations
                # still get counted, but that isn't a significant amount.
                #
                # Ignore unconvincing high donations - we sometimes see 99999999.99.
                $mindate = (!$minepoch || $epoch < $minepoch) ? $date : $mindate;
                $minepoch = (!$minepoch || $epoch < $minepoch) ? $epoch : $minepoch;
            }
        }

        error_log("CSV covers $mindate");

        # Delete the donations we're about to reprocess.  This is all of them except DonateWithPayPal, which come via IPN hooks rather
        # than this export.
        $dbhm->preExec("DELETE FROM users_donations WHERE timestamp >= ? AND source IN ('PayPalGivingFund', 'eBay', 'Facebook');", [
            $mindate
        ]);
        error_log("Deleted " . $dbhm->rowsAffected());
        
        foreach ($donations as $donation) {
            error_log("Record {$donation['date']} {$donation['email']} {$donation['amount']} source {$donation['program']}");
            switch ($donation['program']) {
                case 'eBay for Charity Seller Donations': $source = 'eBay'; break;
                case 'Facebook donations with PPGF': $source = 'Facebook'; break;
                default: $source = 'PayPalGivingFund'; break;
            }

            $rc = $dbhm->preExec("INSERT INTO users_donations (userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, source) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE userid = ?, timestamp = ?, source = ?, GrossAmount = ?;", [
                $donation['eid'],
                $donation['email'],
                $donation['name'],
                $donation['date'],
                $donation['txid'],
                $donation['amount'],
                $source,
                $donation['eid'],
                $donation['date'],
                $source,
                $donation['amount']
            ]);

//            if ($dbhm->rowsAffected() > 0 && $amount >= 20) {
//                $text = "$name ($email) donated £{$amount}.  Please can you thank them?";
//                $message = \Swift_Message::newInstance()
//                    ->setSubject("$name ({$email}) donated £{$amount} - please send thanks")
//                    ->setFrom(NOREPLY_ADDR)
//                    ->setTo(INFO_ADDR)
//                    ->setCc('log@ehibbert.org.uk')
//                    ->setBody($text);
//
//                list ($transport, $mailer) = Mail::getMailer();
//                $mailer->send($message);
//            }
        }

        $mysqltime = date ("Y-m-d", strtotime("Midnight yesterday"));
        $dons = $dbhr->preQuery("SELECT SUM(GrossAmount) AS total FROM users_donations WHERE DATE(timestamp) = ?;", [
            $mysqltime
        ]);

        error_log("\n\nYesterday $mysqltime: £{$dons[0]['total']}");
    }
}

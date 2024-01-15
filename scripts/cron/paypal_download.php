<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# This is a fallback - donateipn catches them normally.

$lockh = Utils::lockScript(basename(__FILE__));

$u = new User($dbhr, $dbhm);

use PayPal\PayPalAPI\TransactionSearchReq;
use PayPal\PayPalAPI\TransactionSearchRequestType;
use PayPal\Service\PayPalAPIInterfaceServiceService;

$config = array(
    "mode" => "live",
    'log.LogEnabled' => true,
    'log.FileName' => '/tmp/PayPal.log',
    'log.LogLevel' => 'FINE',
    "acct1.UserName" => PAYPAL_USERNAME,
    "acct1.Password" => PAYPAL_PASSWORD,
    "acct1.Signature" => PAYPAL_SIGNATURE
);

try {
    $paypalService = new PayPalAPIInterfaceServiceService($config);
    $start = strtotime("00:00 today");
    $end = strtotime("00:00 tomorrow");
    $limit = strtotime('00:00 30 days ago');

    do {
        error_log("..." . date("Y-m-d H:i:s", $start));

        $found = FALSE;

        try {
            $transactionSearchRequest = new TransactionSearchRequestType();
            $transactionSearchRequest->StartDate = Utils::ISODate(date("Y-m-d H:i:s", $start));
            $transactionSearchRequest->EndDate = Utils::ISODate(date("Y-m-d H:i:s", $end));

            $tranSearchReq = new TransactionSearchReq();
            $tranSearchReq->TransactionSearchRequest = $transactionSearchRequest;
            $transactionSearchResponse = $paypalService->TransactionSearch($tranSearchReq);
            $transactions = json_decode(json_encode($transactionSearchResponse->PaymentTransactions), true);

            if (gettype($transactions) == 'array') {
                foreach ($transactions as $transaction) {
                    # Don't record PPGF donations, as we process those separately.
                    if ($transaction['Payer'] && $transaction['GrossAmount']['value'] > 0 && $transaction['PayerDisplayName'] != 'PayPal Giving Fund UK') {
                        $eid = $u->findByEmail($transaction['Payer']);

                        $dbhm->preExec("INSERT INTO users_donations (userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE userid = ?, timestamp = ?;", [
                            $eid,
                            $transaction['Payer'],
                            $transaction['PayerDisplayName'],
                            date("Y-m-d H:i:s", strtotime($transaction['Timestamp'])),
                            $transaction['TransactionID'],
                            $transaction['GrossAmount']['value'],
                            $eid,
                            date("Y-m-d H:i:s", strtotime($transaction['Timestamp']))
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Failed " . $e->getMessage());
            \Sentry\captureException($e);
        }

        $start -= 24 * 60 * 60;
        $end -= 24 * 60 * 60;
    } while ($start > $limit);

    Utils::unlockScript($lockh);
} catch (\Exception $e) {
    \Sentry\captureException($e);
}

<?php
$scriptstart = microtime(false);
date_default_timezone_set('UTC');
session_start();
$_SESSION['writable'] = TRUE;
define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

try {
    # Get the request from PayPal
    $req = 'cmd=_notify-validate';

    foreach ($_POST as $key => $value) {
        $value = urlencode(stripslashes($value));
        $req .= "&$key=$value";
    }

    error_log("Got business cards " . var_export($_POST, TRUE));
    # Post back to PayPal to validate.
    $header .= "POST /cgi-bin/webscr HTTP/1.0\r\n";
    $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $header .= "Content-Length: " . strlen($req) . "\r\n\r\n";
    $fp = fsockopen('ssl://www.paypal.com', 443, $errno, $errstr, 30);
    error_log("Opened sock");

    # Get the parameters
    $item_name = array_key_exists('item_name1', $_POST) ? $_POST['item_name1'] : '';
    $item_number = array_key_exists('item_number1', $_POST) ? $_POST['item_number1'] : '';
    $payment_status = $_POST['payment_status'];
    $payment_amount = $_POST['mc_gross'];
    $payment_currency = $_POST['mc_currency'];
    $txn_id = $_POST['txn_id'];
    $receiver_email = $_POST['receiver_email'];
    $payer_email = $_POST['payer_email'];
    $custom = $_POST['custom'];

    if (!$fp) {
        error_log("Failed to post request back to paypal");
        mail("log@ehibbert.org.uk", "Error: Payment failed to contact PayPal", $sql . "\r\n\r\n" . var_export($_REQUEST, true) . "\r\n\r\n$res", NULL, '-fnoreply@modtools.org');
    } else {
        error_log("put data");
        fputs($fp, $header . $req);
        error_log("put ok");
        while (!feof($fp)) {
            $res = fgets($fp, 1024);

            if (strcmp(strtolower($res), "verified") == 0) {
                error_log("Verified");
                # Valid payment
                if (($payment_currency == 'GBP') && (intval($payment_amount) >= 1)) {
                    error_log("Valid amount for $custom");
                    # For a valid amount.
                    #
                    # Find the latest request from that user and mark it as paid.
                    $dbhm->preExec("UPDATE users_requests SET paid = 1, amount = ? WHERE userid = ? AND paid = 0 ORDER BY date DESC LIMIT 1;", [
                        $custom,
                        $payment_amount
                    ]);
                } else {
                    mail("log@ehibbert.org.uk", "Payment failed", var_export($_POST, TRUE), NULL, '-fnoreply@modtools.org');
                }
            } else if (strcmp($res, "INVALID") == 0) {
                // log for manual investigation
                mail("log@ehibbert.org.uk", "Error: Payment failed validation", var_export($_REQUEST, true), NULL, '-fnoreply@modtools.org');
            }
        }
        error_log("Close");

        fclose($fp);
    }
} catch (Exception $e) {
    mail("log@ehibbert.org.uk", "Error: Payment exception", var_export($e, TRUE) . "\n\n" . var_export($_REQUEST, true), NULL, '-fnoreply@modtools.org');
    error_log("Exception during purchase " . var_export($e, true));
}
?>

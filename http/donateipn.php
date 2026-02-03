<?php

namespace Freegle\Iznik;

# When people donate to us, PayPal will trigger a call to this script.
#
# As a fallback we also have paypal_download on a cron.

require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$u = new User($dbhr, $dbhm);

use PayPal\IPN\PPIPNMessage;
use PayPal\PayPalAPI\TransactionSearchReq;
use PayPal\PayPalAPI\TransactionSearchRequestType;
use PayPal\Service\PayPalAPIInterfaceServiceService;

$config = array(
    "mode" => "live",
    'log.LogEnabled' => TRUE,
    'log.FileName' => '/tmp/PayPalIPN.log',
    'log.LogLevel' => 'FINE',
    "acct1.UserName" => PAYPAL_USERNAME,
    "acct1.Password" => PAYPAL_PASSWORD,
    "acct1.Signature" => PAYPAL_SIGNATURE
); //

$ipnMessage = new PPIPNMessage(null, $config);
$transaction = $ipnMessage->getRawData();

foreach ($transaction as $key => $value) {
    error_log("IPN: $key => $value");
}

if (Utils::pres('mc_gross', $transaction)) {
    $eid = NULL;

    # Check if this is a PayPal-through-Stripe payment. The custom field contains the Stripe PaymentIntent ID
    # in format: acct_xxx:pi_xxx:hash. We can use this to look up the user ID from Stripe metadata,
    # which handles the case where the PayPal email differs from the user's Freegle account email.
    if (Utils::pres('custom', $transaction)) {
        $custom = $transaction['custom'];

        if (preg_match('/pi_[A-Za-z0-9_]+/', $custom, $matches)) {
            $paymentIntentId = $matches[0];
            error_log("IPN: Found Stripe PaymentIntent ID: $paymentIntentId");

            try {
                \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
                $pi = \Stripe\PaymentIntent::retrieve($paymentIntentId);

                if ($pi->metadata->uid) {
                    $eid = $pi->metadata->uid;
                    error_log("IPN: Found user ID from Stripe metadata: $eid");
                }
            } catch (\Exception $e) {
                error_log("IPN: Failed to retrieve Stripe PaymentIntent: " . $e->getMessage());
            }
        }
    }

    # Fallback to email lookup if we didn't get user ID from Stripe
    if (!$eid) {
        $eid = $u->findByEmail($transaction['payer_email']);
    }

    $first = FALSE;

    if ($eid) {
        $previous = $dbhr->preQuery("SELECT COUNT(*) AS count FROM users_donations WHERE userid = ?", [
            $eid
        ]);

        $first = $previous[0]['count'] == 0;
    }

    $d = new Donations($dbhr, $dbhm);
    $d->add(
        $eid,
        $transaction['payer_email'],
        "{$transaction['first_name']} {$transaction['last_name']}",
        date("Y-m-d H:i:s", strtotime($transaction['payment_date'])),
        $transaction['txn_id'],
        $transaction['mc_gross'],
        Donations::TYPE_PAYPAL,
        $transaction['txn_type']
    );

    $recurring = $transaction['txn_type'] == 'recurring_payment' || $transaction['txn_type'] == 'subscr_payment';

    if ($eid) {
        $giftaid = $d->getGiftAid($eid);

        if (!$giftaid || $giftaid['period'] == Donations::PERIOD_THIS) {
            # Ask them to complete a gift aid form.
            $n = new Notifications($dbhr, $dbhm);
            $n->add(NULL, $eid, Notifications::TYPE_GIFTAID, NULL);
        }
    }

    # Don't ask for thanks for the PayPal Giving Fund transactions.  Do ask for first recurring or larger one-off.
    if ((($recurring && $first) || (!$recurring && $transaction['mc_gross'] >= Donations::MANUAL_THANKS)) && !Donations::isExcludedPayer($transaction['payer_email'])) {
        $text = "{$transaction['first_name']} {$transaction['last_name']} ({$transaction['payer_email']}) donated £{$transaction['mc_gross']} via PayPal Donate.  Please can you thank them?";

        if ($recurring) {
            $text .= "\r\n\r\nNB This is a new monthly donation.  We now send this mail for all new recurring donations (since 2023-02-12 10:00).";
        }

        $message = \Swift_Message::newInstance()
            ->setSubject("{$transaction['payer_email']} donated £{$transaction['mc_gross']} - please send thanks")
            ->setFrom(NOREPLY_ADDR)
            ->setTo(INFO_ADDR)
            ->setCc('log@ehibbert.org.uk')
            ->setBody($text);

        list ($transport, $mailer) = Mail::getMailer();
        Mail::addHeaders($dbhr, $dbhm, $message, Mail::DONATE_IPN);

        $mailer->send($message);
    }
}
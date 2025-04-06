<?php

namespace Freegle\Iznik;

# When people donate to us, Stripe will trigger a call to this script.

require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use PayPal\IPN\PPIPNMessage;
use PayPal\PayPalAPI\TransactionSearchReq;
use PayPal\PayPalAPI\TransactionSearchRequestType;
use PayPal\Service\PayPalAPIInterfaceServiceService;

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
$input = @file_get_contents("php://input");
$event = NULL;

function log($str) {
    file_put_contents('/var/www/stripeipn.out', date("d-m-Y h:i:s") . ':' . $str . "\n", FILE_APPEND);
}

log($input);
log("Input length " . strlen($input));

try {
    $json = json_decode($input, TRUE);

    if ($json) {
        $event = \Stripe\Event::constructFrom($json);
    } else {
        log("Invalid payload");
        throw new \Exception('Invalid payload');
    }
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    exit();
}

try {
    switch ($event->type) {
        case 'charge.succeeded':
            $paymentIntent = $event->data->object; // contains a \Stripe\PaymentIntent
            $amount = $paymentIntent->amount;

            // Amount is in pence.
            $amount = $amount ? ($amount / 100) : 0;

            // Exclude PayPal because we'll get an IPN from that.
            $payment_method_details = $paymentIntent->payment_method_details;
            $payment_method = $payment_method_details ? $payment_method_details->type : NULL;

            log("Charge succeeded for £$amount, method $payment_method");

            if ($amount) {
                if ($payment_method != 'paypal') {
                    $eid = $paymentIntent->metadata->uid;
                    $u = User::get($dbhr, $dbhm, $eid);

                    $first = FALSE;

                    if ($eid) {
                        $previous = $dbhr->preQuery("SELECT COUNT(*) AS count FROM users_donations WHERE userid = ? AND TransactionType IN ('susbcr_payment', 'recurring_payment')", [
                            $eid
                        ]);

                        $first = $previous[0]['count'] == 0;
                    } else {
                        # This can happen if we have a subscription.  In that case see if we have a customer and if
                        # so get the user data from that.
                        log("No user id for donation");

                        if ($paymentIntent->customer) {
                            $customer = \Stripe\Customer::retrieve($paymentIntent->customer);
                            $eid = $customer->metadata->uid;
                            log("User id from customer $eid");
                            $u = User::get($dbhr, $dbhm, $eid);

                            if ($eid && $u->getId() == $eid) {
                                log("User id found");
                            } else {
                                # Try the customer billing mail.
                                $email = $customer->email;
                                $eid = $u->findByEmail($email);
                                log("User id from email $eid");
                            }
                        } else {
                            // We don't know how to link this donation to a user.  Mail details to Geeks for them to
                            // investigate.  Will get added below.
                            log("No customer id for donation");
//                            $message = \Swift_Message::newInstance()
//                                ->setSubject("Stripe donation {$paymentIntent->id} can't be linked to user - needs investigating")
//                                ->setFrom(NOREPLY_ADDR)
//                                ->setTo(INFO_ADDR)
//                                ->setCc(GEEKS_ADDR)
//                                ->setBody($input);
//
//                            list ($transport, $mailer) = Mail::getMailer();
//                            Mail::addHeaders($dbhr, $dbhm, $message, Mail::DONATE_IPN);
//
//                            $mailer->send($message);
                        }
                    }

                    $recurring = $paymentIntent->description == 'Subscription creation';

                    $d = new Donations($dbhr, $dbhm);
                    log("Add donation");
                    $did = $d->add(
                        $eid,
                        $u->getEmailPreferred() ? $u->getEmailPreferred() : '',
                        $u->getName() ? $u->getName() : '',
                        date("Y-m-d H:i:s"),
                        $paymentIntent->id,
                        $amount,
                        Donations::TYPE_STRIPE,
                        $recurring ? 'subscr_payment' : NULL,
                        Donations::TYPE_STRIPE,
                    );
                    log("Added donation id $did");

                    if ($u->getId()) {
                        $giftaid = $d->getGiftAid($u->getId());

                        if (!$giftaid || $giftaid['period'] == Donations::PERIOD_THIS) {
                            # Ask them to complete a gift aid form.
                            $n = new Notifications($dbhr, $dbhm);
                            $n->add(NULL, $u->getId(), Notifications::TYPE_GIFTAID, NULL);
                        }

                        # Don't ask for thanks for the PayPal Giving Fund transactions.  Do ask for first recurring or larger one-off.
                        if ((($recurring && $first) || (!$recurring && $amount >= Donations::MANUAL_THANKS))) {
                            log('Request thanks');
                            $text = $u->getName() . " (" . $u->getEmailPreferred() . ") donated £{$amount} via Stripe.  Please can you thank them?";

                            if ($recurring) {
                                $text .= "\r\n\r\nNB This is a new monthly donation.  We now send this mail for all new recurring donations (since 2023-02-12 10:00).";
                            }

                            $message = \Swift_Message::newInstance()
                                ->setSubject($u->getEmailPreferred() . " donated £{$amount} - please send thanks")
                                ->setFrom(NOREPLY_ADDR)
                                ->setTo(INFO_ADDR)
                                ->setCc('log@ehibbert.org.uk')
                                ->setBody($text);

                            list ($transport, $mailer) = Mail::getMailer();
                            Mail::addHeaders($dbhr, $dbhm, $message, Mail::DONATE_IPN);

                            $mailer->send($message);
                        }
                    }
                } else {
                    log("Ignoring PayPal payment");
                }
            }
            break;
        default:
            echo 'Received unknown event type ' . $event->type;
    }

    http_response_code(200);
} catch (\Exception $e) {
    log("Exception in IPN " . $e->getMessage());
    \Sentry\captureException($e);
    http_response_code(500);
}

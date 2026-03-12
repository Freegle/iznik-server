<?php
namespace Freegle\Iznik;

function stripecreatesubscription() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $me = Session::whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'POST': {
            $ret = ['ret' => 1, 'status' => 'Not logged in'];

            if ($me) {
                $amount = Utils::presint('amount', $_REQUEST, 0);
                $test = Utils::presbool('test', $_REQUEST, FALSE);

                if ($amount < 1 || $amount > 100) {
                    $ret = [
                        'ret' => 2,
                        'status' => 'Invalid amount - must be between 1 and 100'
                    ];
                    break;
                }

                $stripe = new \Stripe\StripeClient($test ? STRIPE_SECRET_KEY_TEST : STRIPE_SECRET_KEY);

                $customer = $stripe->customers->create([
                                                           'email' => $me->getEmailPreferred(),
                                                           'name' => $me->getName(),
                                                           'metadata' => [ 'uid' => $me->getId() ],
                                                       ]);

                $product = $stripe->products->create([
                                                          'name' => 'Freegle Monthly Donation - £' . $amount,
                                                      ]);

                $subscription = $stripe->subscriptions->create([
                                                                   'customer' => $customer->id,
                                                                   'items' => [[
                                                                       'price_data' => [
                                                                           'currency' => 'gbp',
                                                                           'unit_amount' => $amount * 100,
                                                                           'recurring' => [
                                                                               'interval' => 'month',
                                                                           ],
                                                                           'product' => $product->id,
                                                                       ],
                                                                   ]],
                                                                   'payment_behavior' => 'default_incomplete',
                                                                   'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                                                                   'expand' => ['latest_invoice.payment_intent'],
                                                                   'metadata' => [
                                                                       'uid' => $me->getId(),
                                                                       'monthly' => TRUE
                                                                   ],
                                                               ]);

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'subscriptionId' => $subscription->id,
                    'clientSecret' => $subscription->latest_invoice->payment_intent->client_secret,
                ];
            }
            break;
        }
    }

    return($ret);
}

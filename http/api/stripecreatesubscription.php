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
                $stripe = new \Stripe\StripeClient($test ? STRIPE_SECRET_KEY_TEST : STRIPE_SECRET_KEY);

                # TODO Email and uid might change - how to handle?
                $customer = $stripe->customers->create([
                                                           'email' => $me->getEmailPreferred(),
                                                           'name' => $me->getName(),
                                                           'metadata' => [ 'uid' => $me->getId() ],
                                                       ]);

                $price = NULL;

                switch ($amount) {
                    case 1: {
                        $price = 'price_1QPo6pP3oIVajsTkjR41BjuL';
                        break;
                    }
                    case 2: {
                        $price = 'price_1QK244P3oIVajsTkYcUs6kEM';
                        break;
                    }
                    case 5: {
                        $price = 'price_1QPo7cP3oIVajsTkdGnF7kI4';
                        break;
                    }
                    case 10: {
                        $price = 'price_1QJv7GP3oIVajsTkTG7RGAUA';
                        break;
                    }
                    case 15: {
                        $price = 'price_1QK24rP3oIVajsTkwkXPms9B';
                        break;
                    }
                    case 25: {
                        $price = 'price_1QK24VP3oIVajsTk3e57kF5S';
                        break;
                    }
                }

                $subscription = $stripe->subscriptions->create([
                                                                   'customer' => $customer->id,
                                                                   'items' => [[
                                                                       'price' => $price
                                                                   ]],
                                                                   'payment_behavior' => 'default_incomplete',
                                                                   'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                                                                   'expand' => ['latest_invoice.payment_intent'],
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

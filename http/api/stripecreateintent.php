<?php
namespace Freegle\Iznik;

function stripecreateintent() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $me = Session::whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'POST': {
            $amount = Utils::presint('amount', $_REQUEST, 0);
            $paymentType = Utils::presdef('paymenttype', $_REQUEST, 'card');
            $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);

            $intent = $stripe->paymentIntents->create([
                                                'amount' => $amount * 100,
                                                'currency' => 'gbp',
                                                'automatic_payment_methods' => [
                                                    'enabled' => TRUE,
                                                ],
                                                'metadata' => [ 'uid' => $me ? $me->getId() : NULL ],
                                            ]);


            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'intent' => $intent->jsonSerialize()
            ];
            break;
        }
    }

    return($ret);
}

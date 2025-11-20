<?php
namespace Freegle\Iznik;

function stripecreateintent() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 1, 'status' => 'Unknown verb' ];

    $me = Session::whoAmI($dbhr, $dbhm);

    switch ($_REQUEST['type']) {
        case 'POST': {
            $amount = floatval(Utils::presdef('amount', $_REQUEST, 0));
            $test = Utils::presbool('test', $_REQUEST, FALSE);

            $intent = createPaymentIntent($amount, $test, $me);

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

function createPaymentIntent($amount, $isTest, $user) {
    $secretKey = $isTest ? STRIPE_SECRET_KEY_TEST : STRIPE_SECRET_KEY;
    $stripe = new \Stripe\StripeClient($secretKey);

    return $stripe->paymentIntents->create([
        'amount' => $amount * 100,
        'currency' => 'gbp',
        'automatic_payment_methods' => [
            'enabled' => TRUE,
        ],
        'metadata' => [ 'uid' => $user ? $user->getId() : NULL ],
    ]);
}

<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

function parse_signed_request($signed_request) {
    list($encoded_sig, $payload) = explode('.', $signed_request, 2);

    $secret = FBAPP_SECRET; // Use your app secret here

    // decode the data
    $sig = base64_url_decode($encoded_sig);
    $data = json_decode(base64_url_decode($payload), true);

    // confirm the signature
    $expected_sig = hash_hmac('sha256', $payload, $secret, $raw = true);
    if ($sig !== $expected_sig) {
        error_log('Bad Signed JSON signature!');
        return null;
    }

    return $data;
}

function base64_url_decode($input) {
    return base64_decode(strtr($input, '-_', '+/'));
}

try {
    header('Content-Type: application/json');

    $signed_request = $_POST['signed_request'];
    $data = parse_signed_request($signed_request);
    $user_id = $data['user_id'];

    if ($user_id) {
        // Start data deletion
        $status_url = "https://" . USER_SITE . "/facebook/unsubscribe/$user_id"; // URL to track the deletion
        $confirmation_code = $user_id; // unique code for the deletion request

        $data = array(
            'url' => $status_url,
            'confirmation_code' => $confirmation_code
        );

        \Sentry\CaptureMessage("Facebook Unsubscribe: $user_id");
        echo json_encode($data);
    } else {
        echo json_encode(array('error' => 'No user_id supplied'));
    }
} catch (Exception $e) {
    \Sentry\captureException($e);
    error_log("Error: " . $e->getMessage());
    echo json_encode(array('error' => 'An error occurred while processing your request.'));
}
?>
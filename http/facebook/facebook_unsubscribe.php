<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

function parse_signed_request($signed_request) {
    error_log("DEBUG: Received signed_request: " . $signed_request);

    list($encoded_sig, $payload) = explode('.', $signed_request, 2);
    error_log("DEBUG: Encoded signature part: " . $encoded_sig);
    error_log("DEBUG: Payload part: " . $payload);

    $secret = FBAPP_SECRET; // Use your app secret here
    error_log("DEBUG: Using app secret (first 10 chars): " . substr($secret, 0, 10) . "...");

    // decode the data
    $sig = base64_url_decode($encoded_sig);
    $data = json_decode(base64_url_decode($payload), TRUE);

    error_log("DEBUG: Decoded signature length: " . strlen($sig));
    error_log("DEBUG: Decoded signature (hex): " . bin2hex($sig));
    error_log("DEBUG: Decoded payload JSON: " . json_encode($data));

    // confirm the signature
    $expected_sig = hash_hmac('sha256', $payload, $secret, $raw = TRUE);
    error_log("DEBUG: Expected signature length: " . strlen($expected_sig));
    error_log("DEBUG: Expected signature (hex): " . bin2hex($expected_sig));
    error_log("DEBUG: Signatures match: " . ($sig === $expected_sig ? 'YES' : 'NO'));

    if ($sig !== $expected_sig) {
        error_log('Bad Signed JSON signature!');
        error_log("DEBUG: Signature comparison failed - received vs expected");
        error_log("DEBUG: Received:  " . bin2hex($sig));
        error_log("DEBUG: Expected: " . bin2hex($expected_sig));
        return null;
    }

    return $data;
}

function base64_url_decode($input) {
    error_log("DEBUG: base64_url_decode input: " . $input);
    $converted = strtr($input, '-_', '+/');
    error_log("DEBUG: base64_url_decode after strtr: " . $converted);
    $decoded = base64_decode($converted);
    error_log("DEBUG: base64_url_decode result length: " . strlen($decoded));
    return $decoded;
}

try {
    header('Content-Type: application/json');

    error_log("DEBUG: Facebook unsubscribe request received");
    error_log("DEBUG: POST data: " . json_encode($_POST));
    error_log("DEBUG: Request method: " . $_SERVER['REQUEST_METHOD']);
    error_log("DEBUG: Content type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

    if (!isset($_POST['signed_request'])) {
        error_log("ERROR: No signed_request in POST data");
        echo json_encode(array('error' => 'No signed_request parameter'));
        exit;
    }

    $signed_request = $_POST['signed_request'];
    error_log("DEBUG: About to parse signed request");
    $data = parse_signed_request($signed_request);

    if ($data === null) {
        error_log("ERROR: parse_signed_request returned null - signature verification failed");
        echo json_encode(array('error' => 'Invalid signature'));
        exit;
    }

    error_log("DEBUG: Parsed data: " . json_encode($data));
    $user_id = $data['user_id'] ?? null;

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
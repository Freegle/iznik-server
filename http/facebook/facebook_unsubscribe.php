<?php
namespace Freegle\Iznik;

use Sentry\Severity;

/**
 * Facebook Data Deletion Request Callback
 *
 * This endpoint receives data deletion requests from Facebook when a user
 * removes our app or requests their data be deleted.
 *
 * Facebook sends a signed_request parameter containing the user's Facebook ID.
 * We validate the signature, look up the corresponding Freegle user, and
 * mark them for deletion (14-day grace period via limbo()).
 */

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

function parse_signed_request($signed_request) {
    list($encoded_sig, $payload) = explode('.', $signed_request, 2);

    $secret = FBAPP_SECRET;

    // Decode the data
    $sig = base64_url_decode($encoded_sig);
    $data = json_decode(base64_url_decode($payload), TRUE);

    // Confirm the signature
    $expected_sig = hash_hmac('sha256', $payload, $secret, $raw = TRUE);

    if ($sig !== $expected_sig) {
        error_log('Facebook unsubscribe: Bad signed request signature');
        return NULL;
    }

    return $data;
}

function base64_url_decode($input) {
    return base64_decode(strtr($input, '-_', '+/'));
}

try {
    header('Content-Type: application/json');

    if (!isset($_POST['signed_request'])) {
        error_log("Facebook unsubscribe: No signed_request in POST data");
        echo json_encode(array('error' => 'No signed_request parameter'));
        exit;
    }

    $signed_request = $_POST['signed_request'];
    $data = parse_signed_request($signed_request);

    if ($data === NULL) {
        echo json_encode(array('error' => 'Invalid signature'));
        exit;
    }

    $user_id = $data['user_id'] ?? NULL;

    if ($user_id) {
        // Look up the Freegle user by their Facebook login
        $u = User::get($dbhr, $dbhm);
        $freegle_user_id = $u->findByLogin('Facebook', $user_id);

        if ($freegle_user_id) {
            // Found the user - mark them for deletion
            $user = User::get($dbhr, $dbhm, $freegle_user_id);
            $deleted = $user->getPrivate('deleted');

            if (!$deleted) {
                // Put user into limbo (14-day grace period, sends email notification)
                $user->limbo();
                \Sentry\captureMessage("Facebook Unsubscribe: Facebook ID $user_id -> Freegle ID $freegle_user_id - marked for deletion", Severity::info());
            } else {
                \Sentry\captureMessage("Facebook Unsubscribe: Facebook ID $user_id -> Freegle ID $freegle_user_id - already deleted", Severity::info());
            }

            // Return status URL with Freegle user ID
            $status_url = "https://" . USER_SITE . "/facebook/unsubscribe/$freegle_user_id";
            $confirmation_code = $freegle_user_id;

            $data = array(
                'url' => $status_url,
                'confirmation_code' => $confirmation_code
            );

            echo json_encode($data);
        } else {
            // User not found - they may have already been deleted or never existed
            $data = array(
                'url' => "https://" . USER_SITE . "/facebook/unsubscribe",
                'confirmation_code' => $user_id
            );

            \Sentry\captureMessage("Facebook Unsubscribe: Facebook ID $user_id not found", Severity::info());
            echo json_encode($data);
        }
    } else {
        echo json_encode(array('error' => 'No user_id supplied'));
    }
} catch (\Exception $e) {
    \Sentry\captureException($e);
    error_log("Facebook unsubscribe error: " . $e->getMessage());
    echo json_encode(array('error' => 'An error occurred while processing your request.'));
}

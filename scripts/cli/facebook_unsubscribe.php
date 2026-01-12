<?php
namespace Freegle\Iznik;

/**
 * CLI script to process Facebook data deletion requests.
 *
 * Usage: php facebook_unsubscribe.php <facebook_id1> [facebook_id2] [facebook_id3] ...
 *
 * For each Facebook ID:
 * - Looks up the corresponding Freegle user
 * - Puts them into "limbo" (deleted state with 14-day recovery period)
 * - Sends an email notification to the user
 *
 * After 14 days, a background process will permanently delete their data.
 */

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

if ($argc < 2) {
    echo "Usage: php facebook_unsubscribe.php <facebook_id1> [facebook_id2] ...\n";
    echo "\nThis script processes Facebook data deletion requests.\n";
    echo "For each Facebook ID, it will:\n";
    echo "  - Look up the corresponding Freegle user\n";
    echo "  - Mark them for deletion (14-day grace period)\n";
    echo "  - Send an email notification\n";
    exit(1);
}

$facebookIds = array_slice($argv, 1);

echo "Processing " . count($facebookIds) . " Facebook ID(s)...\n\n";

$processed = 0;
$notFound = 0;
$alreadyDeleted = 0;

foreach ($facebookIds as $fbid) {
    $fbid = trim($fbid);

    if (empty($fbid)) {
        continue;
    }

    echo "Facebook ID: $fbid\n";

    // Look up Freegle user by Facebook login
    $u = User::get($dbhr, $dbhm);
    $freegleUserId = $u->findByLogin('Facebook', $fbid);

    if (!$freegleUserId) {
        echo "  -> User not found in Freegle database\n\n";
        $notFound++;
        continue;
    }

    // Get the user
    $user = User::get($dbhr, $dbhm, $freegleUserId);
    $email = $user->getEmailPreferred();
    $deleted = $user->getPrivate('deleted');

    if ($deleted) {
        echo "  -> Freegle ID: $freegleUserId\n";
        echo "  -> Email: " . ($email ?: '(none)') . "\n";
        echo "  -> Already marked for deletion on: $deleted\n\n";
        $alreadyDeleted++;
        continue;
    }

    echo "  -> Freegle ID: $freegleUserId\n";
    echo "  -> Email: " . ($email ?: '(none)') . "\n";

    // Put user into limbo (this sends the email notification)
    $user->limbo();

    echo "  -> Marked for deletion. Email notification sent.\n\n";
    $processed++;
}

echo "Summary:\n";
echo "  Processed: $processed\n";
echo "  Not found: $notFound\n";
echo "  Already deleted: $alreadyDeleted\n";
echo "\nNote: Users have 14 days to recover their account by logging back in.\n";

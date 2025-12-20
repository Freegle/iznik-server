<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Load previously processed users to avoid re-checking.
if (file_exists('/var/www/toddlers.json')) {
    $found = json_decode(file_get_contents('/var/www/toddlers.json'), TRUE);
} else {
    $found = [];
}

error_log("Start at " . date("Y-m-d H:i:s") . " with " . count($found) . " previously processed.");

# Use the Spam class method to find and flag related spammers.
$spam = new Spam($dbhr, $dbhm);
$results = $spam->findRelatedSpammers($found);

error_log("Checked {$results['muted_users_checked']} muted users, {$results['spammers_checked']} spammers.");
error_log("Flagged {$results['users_flagged']} users, muted {$results['users_muted']} users.");

foreach ($results['actions'] as $action) {
    error_log("{$action['type']}: {$action['reason']}");
}

file_put_contents('/var/www/toddlers.json', json_encode($found));

Utils::unlockScript($lockh);
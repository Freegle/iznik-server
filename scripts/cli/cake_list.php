<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id FROM users WHERE systemrole IN (?,?,?) ORDER BY RAND();", [
    User::SYSTEMROLE_MODERATOR,
    User::SYSTEMROLE_SUPPORT,
    User::SYSTEMROLE_ADMIN
]);

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['id']);

    if ($u->getSetting('modcake', FALSE)) {
        echo "{$user['id']} " . $u->getName() . " (" . $u->getEmailPreferred() . ") " . $u->getSetting('modcakenotes', NULL) . "\n";
    }
}
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

// Phone consent was added on this date - anyone consenting after this has agreed to phone sharing
define('PHONE_CONSENT_DATE', '2026-01-26');

$users = $dbhr->preQuery("SELECT DISTINCT users.id FROM users INNER JOIN memberships ON users.id = memberships.userid INNER JOIN `groups` ON groups.id = memberships.groupid WHERE memberships.role IN (?, ?) AND groups.type = 'Freegle' ORDER BY RAND();", [
    User::ROLE_OWNER,
    User::ROLE_MODERATOR
]);

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['id']);

    if ($u->getSetting('modcake', FALSE)) {
        $phoneConsent = '';
        $cakeDate = $u->getSetting('modcakedate', NULL);

        if ($cakeDate && strtotime($cakeDate) >= strtotime(PHONE_CONSENT_DATE)) {
            $phoneConsent = ' [Phone consent given]';
        }

        echo "{$user['id']} " . $u->getName() . " (" . $u->getEmailPreferred() . ") " . $u->getSetting('modcakenotes', NULL) . $phoneConsent . "\n";
    }
}
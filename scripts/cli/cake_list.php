<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT users.id FROM users INNER JOIN memberships ON users.id = memberships.userid INNER JOIN groups ON groups.id = memberships.groupid WHERE memberships.role IN (?, ?) AND groups.type = 'Freegle' ORDER BY RAND();", [
    User::ROLE_OWNER,
    User::ROLE_MODERATOR
]);

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['id']);

    if ($u->getSetting('modcake', FALSE)) {
        echo "{$user['id']} " . $u->getName() . " (" . $u->getEmailPreferred() . ") " . $u->getSetting('modcakenotes', NULL) . "\n";
    }
}
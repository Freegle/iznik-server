<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id, tnuserid FROM users WHERE tnuserid IS NOT NULL;");

foreach ($users as $user) {
    $emails = $dbhr->preQuery("SELECT email FROM users_emails WHERE userid = ? AND email LIKE '%trashnothing%';", [ $user['id'] ]);
    if (!count($emails)) {
        error_log("{$user['tnuserid']} attached to non TN-user {$user['id']}");
        $dbhm->preExec("UPDATE users SET tnuserid = NULL WHERE id = ?;", [ $user['id'] ]);
    }
}
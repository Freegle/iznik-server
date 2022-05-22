<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$emails = $dbhr->preQuery("SELECT * FROM users_emails WHERE canon = '@mediamessagingo2couk' OR canon = '0'");

foreach ($emails as $email) {
    $newCanon = User::canonMail($email['email']);
    $dbhm->preQuery("UPDATE users_emails SET canon = ?, backwards = ? WHERE id = ?;", [
        $newCanon,
        strrev($newCanon),
        $email['id']
    ]);
}
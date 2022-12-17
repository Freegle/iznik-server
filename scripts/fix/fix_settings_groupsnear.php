<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("SELECT id FROM users WHERE settings LIKE '%groupsnear%'");

foreach ($users as $user) {
    $u = User::get($dbhr, $dbhm, $user['id']);
    $origsettings = $u->getPrivate('settings');

    if ($origsettings) {
        $settings = User::pruneSettings($origsettings);

        if ($settings != $origsettings) {
            $dbhm->preExec("UPDATE users SET settings = ? WHERE id = ?;", [
                $settings,
                $user['id']
            ]);

            error_log(strlen($origsettings) . " => " . strlen($settings));
        }
    }
}
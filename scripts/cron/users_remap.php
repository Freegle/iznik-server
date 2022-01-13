<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

try {
    $earliest = date("Y-m-d", strtotime("48 hours ago"));

    $users = $dbhr->preQuery("SELECT id, settings FROM users WHERE users.lastaccess >= ?;", [
        $earliest
    ]);

    $changed = 0;

    foreach ($users as $user) {
        if (Utils::pres('settings', $user)) {
            $settings = json_decode($user['settings'], TRUE);
            $oldname = $settings['mylocation']['name'];

            if (Utils::pres('mylocation', $settings)) {
                $l = new Location($dbhr, $dbhm, $settings['mylocation']['id']);
                $newname = $l->getPrivate('name');

                if ($newname != $oldname) {
                    error_log("User #{$user['id']} $oldname => $newname");
                    $u = new User($dbhr, $dbhm, $user['id']);
                    $settings['mylocation'] = $l->getPublic();
                    $u->setPrivate('settings', json_encode($settings));
                }
            }
        }
    }

    error_log("Changed $changed of " . count($users));
} catch (\Exception $e) {
    \Sentry\captureException($e);
    error_log("Failed " . $e->getMessage());
};


Utils::unlockScript($lockh);
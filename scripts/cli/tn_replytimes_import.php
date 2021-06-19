<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/lib/GreatCircle.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('f:');

$users = json_decode(file_get_contents($opts['f']), TRUE);

error_log("Got " . count($users));

foreach ($users['changes'] as $user) {
    if ($user['fd_user_id']) {
        try {
            $dbhm->preExec("REPLACE INTO users_replytime (userid, replytime, timestamp) VALUES (?, ?, ?);", [
                $user['fd_user_id'],
                $user['reply_time'],
                $user['date']
            ]);
        } catch (\Exception $e) {}

        if ($user['about_me']) {
            try {
                $dbhm->preExec("REPLACE INTO users_aboutme (userid, timestamp, text) VALUES (?, ?, ?);", [
                    $user['fd_user_id'],
                    $user['date'],
                    $user['about_me']
                ]);
            } catch (\Exception $e) {}
        }
    }
}
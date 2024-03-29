<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:m:u:');

if (count($opts) < 2) {
    echo "Usage: php user_facebook_notify.php (-i <id of user to notify>) -m <Message to send> -u <URL to link to>\n";
} else {
    $id = Utils::presdef('i', $opts, NULL);
    $userq = $id ? (" AND userid = " . intval($id)) : '';
    $message = $opts['m'];
    $url = $opts['u'];

    $f = new Facebook($dbhr, $dbhm);

    $users = $dbhr->preQuery("SELECT * FROM users_logins WHERE type = 'Facebook' $userq ORDER BY lastaccess DESC;");
    $count = 0;
    foreach ($users as $user) {
        $f->notify($user['uid'], $message, $url);
        sleep(1);

        $count++;

        if ($count % 1000 === 0) {
            error_log("...$count / " . count($users));
        }
    }

    error_log("Notified $count");
}

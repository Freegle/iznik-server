<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$pushes = $dbhr->preQuery("SELECT users_push_notifications.* FROM `users_push_notifications` INNER JOIN users ON users.id = users_push_notifications.userid WHERE apptype = 'User' and users_push_notifications.type = 'FCMAndroid' AND users.lastaccess >= '2021-06-01' AND users.lastaccess < '2021-12-01'");
$send = TRUE;

foreach ($pushes as $push) {
    error_log("...{$push['subscription']}");
    $dbhm->preExec("UPDATE users_push_notifications SET engageconsidered = NOW() WHERE id = ?;", [
        $push['id']
    ]);

    if ($send) {
        $dbhm->preExec("UPDATE users_push_notifications SET engagesent = NOW() WHERE id = ?;", [
            $push['id']
        ]);

        $p = new PushNotifications($dbhr, $dbhm);
        $p->executeSend($push['userid'], $push['type'], NULL, $push['subscription'], [
            'badge' => 1,
            'count' => 1,
            'chatcount' => 0,
            'notifcount' => 1,
            'title' => 'Merry Christmas!',
            'message' => 'It\'s been a little while since you freegled. Why not offer up something today?',
            'chatids' => [],
            'content-available' => 1,
            'image' => "www/images/user_logo.png",
            'modtools' => FALSE,
            'sound' => 'default',
            'route' => '/give'
        ]);
    }

    $send = !$send;
}

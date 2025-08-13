<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('e:');

$email = $opts['e'];

$u = new User($dbhr, $dbhm);
$uid = $u->findByEmail($email);
error_log("Found user $uid");

if ($uid) {
    $pushes = $dbhr->preQuery("SELECT * FROM users_push_notifications WHERE userid = ? AND apptype = ?;", [
        $uid,
        'User'
    ]);

    foreach ($pushes as $push) {
        error_log("...{$push['subscription']}");
        $p = new PushNotifications($dbhr, $dbhm);
        $p->executeSend($uid, $push['type'], NULL, $push['subscription'], [
            'badge' => 1,
            'count' => 1,
            'chatcount' => 0,
            'notifcount' => 1,
            'title' => 'Merry Christmazs!',
            'message' => 'It\'s been a while since you freegled. Why not offer up something today?',
            'chatids' => [],
            'content-available' => 1,
            'image' => "www/images/user_logo.png",
            'modtools' => FALSE,
            'sound' => 'default',
            'route' => '/give'
        ]);
    }
}
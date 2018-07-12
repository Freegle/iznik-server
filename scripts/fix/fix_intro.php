<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/user/Notifications.php');

$users = $dbhr->preQuery("SELECT DISTINCT users.id FROM `users` INNER JOIN memberships ON users.id = memberships.id INNER JOIN groups ON groups.id = memberships.groupid LEFT JOIN users_notifications ON users_notifications.touser = users.id AND users_notifications.type = 'AboutMe' WHERE lastaccess > '2018-06-12' AND users_notifications.touser IS NULL;");

$n = new Notifications($dbhr, $dbhm);

error_log("Notify " . count($users));

foreach ($users as $user) {
    $n->add(NULL, $user['id'], Notifications::TYPE_ABOUT_ME, NULL, NULL, NULL);
}
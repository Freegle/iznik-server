<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/group/Group.php');

$mysqltime = date ("Y-m-d", strtotime("Midnight 2 days ago"));
$users = $dbhr->preQuery("SELECT * FROM users WHERE lastaccess > '$mysqltime';");
$total = count($users);
$count = 0;

$u = new User($dbhr, $dbhm);

foreach ($users as $user) {
    $u->updateKudos($user['id']);

    $count++;

    if ($count % 10 === 0) {
        error_log("...$count / $total");
    }
}

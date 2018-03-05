<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/dashboard/Dashboard.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$lockh = lockScript(basename(__FILE__));

# Look for any dashboards which need refreshing.  Any which exist need updating now as we are run by cron
# at the start of a new day.  Any which don't exist will be created on demand.
$todos = $dbhr->preQuery("SELECT id, `key`, type, userid, systemwide, groupid, start FROM users_dashboard;");

foreach ($todos as $todo) {
    $me = $todo['userid'] ? (new User($dbhr, $dbhm, $todo['userid'])) : NULL;
    $_SESSION['id'] = $me ? $me->getId() : NULL;
    $d = new Dashboard($dbhr, $dbhm, $me);
    error_log("Update dashboard user {$todo['userid']}, group {$todo['groupid']}, type {$todo['type']}, start {$todo['start']}");
    $d->get($todo['systemwide'], $todo['groupid'] == NULL, $todo['groupid'], NULL, $todo['type'], $todo['start'], TRUE, $todo['key']);
}

unlockScript($lockh);
<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

# Make sure that the added date of a user reflects the earliest added date on their groups.
$users = $dbhr->preQuery("SELECT id, added FROM users;");
$total = count($users);
$count = 0;

foreach ($users as $user) {
    $mins = $dbhr->preQuery("SELECT MIN(added) AS minadd FROM memberships WHERE userid = ?;", [
        $user['id']
    ], FALSE, FALSE);

    foreach ($mins as $min) {
        if ($min['minadd'] && (!$user['added'] || strtotime($min['minadd']) < strtotime($user['added']))) {
            # We have a group membership and either no added info or we now know that the user is older.
            error_log("{$user['id']} Older min membership {$min['minadd']}");
            $dbhm->preExec("UPDATE users SET added = ? WHERE id = ?;", [
                $min['minadd'],
                $user['id']
            ], FALSE);
        } else {
            # No memberships.  Check the first log.
            $logs = $dbhr->preQuery("SELECT MIN(timestamp) AS d FROM logs WHERE user = ?;", [
                $user['id']
            ]);

            foreach ($logs as $log) {
                if ($log['d'] && (!$user['added'] || strtotime($log['d']) < strtotime($user['added']))) {
                    error_log("{$user['id']} Older log {$min['minadd']}");
                    $dbhm->preExec("UPDATE users SET added = ? WHERE id = ?;", [
                        $log['d'],
                        $user['id']
                    ], FALSE);
                }
            }
        }
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...$count / $total");
    }
}
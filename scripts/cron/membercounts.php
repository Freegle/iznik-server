<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$date = date('Y-m-d', strtotime("yesterday"));
$groups = $dbhr->preQuery("SELECT * FROM groups;");
foreach ($groups as $group) {
    $sql = "SELECT COUNT(*) AS count FROM memberships WHERE groupid = ?;";
    $counts = $dbhr->preQuery($sql, [ $group['id'] ]);
    foreach ($counts as $count) {
        error_log("...{$group['nameshort']} = {$count['count']}");
        $sql = "UPDATE groups SET membercount = ? WHERE id = ?;";
        $counts = $dbhr->preExec($sql, [
            $count['count'],
            $group['id']
        ]);
    }

    $sql = "SELECT COUNT(*) AS count FROM memberships WHERE groupid = ? AND role IN ('Owner', 'Moderator');";
    $counts = $dbhr->preQuery($sql, [ $group['id'] ]);
    foreach ($counts as $count) {
        $sql = "UPDATE groups SET modcount = ? WHERE id = ?;";
        $counts = $dbhr->preExec($sql, [
            $count['count'],
            $group['id']
        ]);
    }
}

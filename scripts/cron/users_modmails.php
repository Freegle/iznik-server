<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

# We maintain a count of recent modmails by scanning logs regularly, and pruning old ones.  This means we can
# find the value in a well-indexed way without the disk overhead of having a two-column index on logs.
$mysqltime = date("Y-m-d H:i:s", strtotime("10 minutes ago"));

$logs = $dbhr->preQuery("SELECT * FROM logs WHERE timestamp > ? AND ((type = 'Message' AND subtype IN ('Rejected', 'Deleted', 'Replied')) OR (type = 'User' AND subtype IN ('Mailed', 'Rejected', 'Deleted'))) AND (TEXT IS NULL OR text NOT IN ('Not present on Yahoo','Received later copy of message with same Message-ID'))", [
    $mysqltime
]);

foreach ($logs as $log) {
    $dbhm->preQuery("INSERT IGNORE INTO users_modmails (userid, logid, timestamp, groupid) VALUES (?,?,?,?);", [
        $log['user'],
        $log['id'],
        $log['timestamp'],
        $log['groupid']
    ]);
}

# Prune old ones.
$mysqltime = date("Y-m-d", strtotime("Midnight 30 days ago"));

$logs = $dbhr->preQuery("SELECT id FROM users_modmails WHERE timestamp < ?;", [
    $mysqltime
]);

foreach ($logs as $log) {
    $dbhm->preExec("DELETE FROM users_modmails WHERE id = ?;", [ $log['id'] ], FALSE);
}

<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

$sql = "SELECT COUNT(*) AS count, user, groupid, timestamp FROM `logs` WHERE type = 'Group' AND subtype = 'Left' GROUP BY user, groupid, timestamp HAVING count > 1;";
#error_log("SELECT breakdown FROM stats WHERE type = '$type' AND date >= '$start' AND date < '$end' AND groupid IN (" . implode(',', $groupids) . ");");

# We can't use our standard preQuery wrapper, because it runs out of memory on very large queries (it
# does a fetchall under the covers).
$dbconfig = array (
    'host' => SQLHOST,
    'user' => SQLUSER,
    'pass' => SQLPASSWORD,
    'database' => SQLDB
);

$dsn = "mysql:host={$dbconfig['host']};port={$dbconfig['port_mod']};dbname={$dbconfig['database']};charset=utf8";

$_db = new \PDO($dsn, SQLUSER, SQLPASSWORD, [
    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
]);

$sth = $_db->prepare($sql);
$sth->execute([
]);

$count = 0;

while ($log = $sth->fetch()) {
    $logs2 = $dbhr->preQuery("SELECT * FROM logs WHERE user = ? AND groupid = ? AND timestamp = ?", [
        $log['user'],
        $log['groupid'],
        $log['timestamp']
    ]);

    if (count($logs2) > 1) {
        $save = NULL;

        foreach ($logs2 as $log2) {
            if (!$save) {
                $save = $log2['id'];
            } else {
                #error_log("Save $save delete {$log2['id']}");
                $dbhm->preExec("DELETE FROM logs WHERE id = ?", [
                    $log2['id']
                ]);
            }
        }
    }

    $count++;

    if ($count % 1000 === 0) {
        error_log("...{$log['timestamp']}");
    }
}

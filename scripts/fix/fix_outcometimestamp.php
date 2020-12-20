<?php

# Run on backup server to recover a user from a backup to the live system.  Use with astonishing levels of caution.
require_once dirname(__FILE__) . '/../../include/config.php';
error_log("Load DB");
require_once(IZNIK_BASE . '/include/db.php');
error_log("Loaded");

require_once(IZNIK_BASE . '/include/user/User.php');

$dsn = "mysql:host=localhost;port=3306;dbname=iznik;charset=utf8";

error_log("Connect to backup");
$dbhback = new \PDO($dsn, SQLUSER, SQLPASSWORD);
error_log("Connected");

$sth = $dbhback->prepare("SELECT id, timestamp FROM messages_outcomes");
$sth->execute([
]);

$count = 0;

while ($outcome = $sth->fetch()) {
    $exists = $dbhr->preQuery("SELECT timestamp FROM messages_outcomes WHERE id = ?", [
        $outcome['id']
    ]);

    foreach ($exists as $exist) {
        if ($exist['timestamp'] != $outcome['timestamp']) {
            $dbhr->preExec("UPDATE messages_outcomes SET timestamp = ? WHERE id = ?", [
                $outcome['timestamp'],
                $outcome['id'],
            ]);
        }
    }

    $count++;

    if ($count % 1000 == 0) {
        error_log("...$count");
    }
}

<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$users = $dbhr->preQuery("select * from users where fullname like 'Deleted User%' and yahooid is not null and lastaccess > '2019-04-01';");
foreach ($users as $user) {
    $dbhm->preExec("UPDATE users SET fullname = yahooid WHERE id = ?;", [
        $user['id']
    ]);
}

$users = $dbhr->preQuery("select * from users where fullname like 'Deleted User%' and yahooid is not null;");
foreach ($users as $user) {
    $dbhm->preExec("UPDATE users SET yahooid = NULL WHERE id = ?;", [
        $user['id']
    ]);
}
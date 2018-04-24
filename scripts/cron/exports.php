<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/user/User.php');

$lockh = lockScript(basename(__FILE__));

$exports = $dbhr->preQuery("SELECT * FROM users_exports WHERE completed IS NULL;");

foreach ($exports as $export) {
    error_log("Do export {$export['id']} for {$export['userid']} requested {$export['requested']}");
    $u = new User($dbhr, $dbhm, $export['userid']);
    $u->export($export['id'], $export['tag']);
    error_log("...done");
}

# Zap data for old exports.
$mysqltime = date("Y-m-d H:i:s", strtotime("midnight 7 days ago"));
$dbhm->preExec("UPDATE users_exports SET data = NULL WHERE completed IS NOT NULL AND completed < '$mysqltime';");

unlockScript($lockh);

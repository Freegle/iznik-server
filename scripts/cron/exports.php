<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

error_log("Start exports script");

# When standalone for Travis want this to run forver
for ($i = 0; $i < getenv('STANDALONE') ? 10000 : 10; $i++) {
    try {
        $exports = $dbhr->preQuery("SELECT * FROM users_exports WHERE completed IS NULL ORDER BY id ASC;");

        foreach ($exports as $export) {
            error_log("Do export {$export['id']} for {$export['userid']} requested {$export['requested']}");
            $u = new User($dbhr, $dbhm, $export['userid']);
            $u->export($export['id'], $export['tag']);
            error_log("...done");
        }

        # Zap data for old exports.
        $mysqltime = date("Y-m-d H:i:s", strtotime("midnight 7 days ago"));
        $dbhm->preExec("UPDATE users_exports SET data = NULL WHERE completed IS NOT NULL AND completed < '$mysqltime';");
    } catch (\Exception $e) {
        error_log("Top-level exception " . $e->getMessage() . "\n");

        if (strpos($e->getMessage(), 'Call to a member function prepare() on a non-object (null)') !== FALSE) {
            break;
        }
    }

    sleep(30);
}

error_log("End export script");

unlockScript($lockh);

<?php
# Work out which SMS notifications result in clicks.

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$srcs = $dbhr->preQuery("SELECT * FROM logs_src ORDER BY date ASC;");

foreach ($srcs as $src) {
    #error_log("{$src['date']} user {$src['userid']} session {$src['session']}");
    if ($src['userid']) {
        # They were logged in, so we know who they were.
        #error_log("...logged in");
        $dbhm->preExec("UPDATE users_phones SET lastclicked = ? WHERE userid = ?", [
            $src['date'],
            $src['userid']
        ]);
    } else {
        # They weren't logged in, but we have a session, so we may be able to identify them from the API logs.
        #error_log("...not logged in");
        $logs = $dbhr->preQuery("SELECT * FROM logs_api WHERE session = ? AND userid IS NOT NULL LIMIT 1;", [
            $src['session']
        ]);

        foreach ($logs as $log) {
            #error_log("...but found {$log['userid']} in logs");
            $dbhm->preExec("UPDATE users_phones SET lastclicked = ? WHERE userid = ?", [
                $src['date'],
                $log['userid']
            ]);
        }
    }
}

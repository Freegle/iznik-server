<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('i:');

$id = $opts['i'];

error_log("Find log $id");

if ($id) {
    $logs = $dbhr->preQuery("SELECT * FROM logs_api WHERE id = ?;", [
        $id
    ]);

    session_start();
    foreach ($logs as $log) {
        error_log("Found log");
        if ($log['userid']) {
            error_log("Impersonate {$log['userid']}");
            $_SESSION['id'] = $log['userid'];
        }

        $_REQUEST = json_decode($log['request'], TRUE);
        API::call();
    }
}


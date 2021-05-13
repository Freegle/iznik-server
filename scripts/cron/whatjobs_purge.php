<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

$lockh = Utils::lockScript(basename(__FILE__));

# This script does some of the work of deleting jobs between runs of whatjobs.
$oldest = date('Y-m-d H:i:s', strtotime("13 hours ago"));

# Purge old jobs.
$purged = 0;

do {
    $dbhm->preExec("DELETE FROM jobs WHERE seenat < '$oldest' LIMIT 100;");
    $thispurge = $dbhm->rowsAffected();

    $purged += $thispurge;

    if ($purged % 1000 === 0) {
        error_log(date("Y-m-d H:i:s", time()) . "...$purged purged");
    }
} while ($thispurge);

error_log("Purged $purged");

Utils::unlockScript($lockh);
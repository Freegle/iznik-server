<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

$jobs = $dbhr->preQuery("SELECT id FROM jobs WHERE visible = 0");
$count = 0;
error_log("Got " . count($jobs));

foreach ($jobs as $job) {
    $dbhm->preExec("DELETE FROM jobs WHERE id = ?", [
        $job['id']
    ]);

    $count++;

    if ($count % 100 === 0) {
        error_log(date("Y-m-d H:i:s", time()) . "...$count / " . count($jobs));
    }
}
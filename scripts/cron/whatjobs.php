<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

use Prewk\XmlStringStreamer;
use Prewk\XmlStringStreamer\Stream;
use Prewk\XmlStringStreamer\Parser;

# This is slow, so increase the timeout otherwise we will fail to complete.
ini_set("default_socket_timeout", 1200);
$dbhr->setAttribute(\PDO::ATTR_TIMEOUT, 1200);
$dbhm->setAttribute(\PDO::ATTR_TIMEOUT, 1200);

$lockh = Utils::lockScript(basename(__FILE__));

# Get the oldest date before we start because the script can run for ages.
$oldest = date('Y-m-d H:i:s', strtotime("9 hours ago"));

# Get the jobs files.  We have two feeds from WhatJobs.
system('cd /tmp/; rm feed.xml*; rm feed*.csv; wget -O - ' . WHATJOBS_DUMP . '| gzip -d -c > feed.xml');
system('cd /tmp/; wget -O - ' . WHATJOBS_DUMP2 . '| gzip -d -c > feed2.xml');

# Generate a CSV containing what we want.
$j = new Jobs($dbhr, $dbhm);

try {
    $j->scanToCSV('/tmp/feed.xml', '/tmp/feed.csv', 7, FALSE, 0.0005, 1000, 1);
} catch (\Exception $e) {
    \Sentry\captureException($e);
}

try
{
    $j->scanToCSV('/tmp/feed2.xml', '/tmp/feed2.csv', 7, false, 0.0005, 1000, 2);
} catch (\Exception $e) {
    \Sentry\captureException($e);
}

# Build up a new table with all the jobs we want.  This means all the mod ops happen on a table which isn't
# being accessed for read.  This improves performance because we're not doing row locks which interfere with the
# read, and it also helps with InnoDB semaphore deadlocks (some of which are due to MySQL bugs).
$j->prepareForLoadCSV();
try {
    $j->loadCSV('/tmp/feed.csv');
} catch (\Exception $e) {
    \Sentry\captureException($e);
}
try {
    $j->loadCSV('/tmp/feed2.csv');
} catch (\Exception $e) {
    \Sentry\captureException($e);
}
$j->deleteSpammyJobs();
$j->swapTables();

Utils::unlockScript($lockh);
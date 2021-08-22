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

$lockh = Utils::lockScript(basename(__FILE__));

# Get the oldest date before we start because the script can run for ages.
$oldest = date('Y-m-d H:i:s', strtotime("9 hours ago"));

# Get the jobs file.
system('cd /tmp/; rm feed.xml*; wget -O - ' . WHATJOBS_DUMP . '| gzip -d -c > feed.xml');

# Generate a CSV containing what we want.
$j = new Jobs($dbhr, $dbhm);
$j->scanToCSV('/tmp/feed.xml', '/tmp/feed.csv');

# Build up a new table with all the jobs we want.  This means all the mod ops happen on a table which isn't
# being accessed for read.  This improves performance because we're not doing row locks which interfere with the
# read, and it also helps with InnoDB semaphore deadlocks (some of which are due to MySQL bugs).
$j->loadCSV('/tmp/feed.csv');
$j->deleteSpammyJobs();
$j->swapTables();

Utils::unlockScript($lockh);
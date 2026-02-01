<?php
#
# Backfill canonical_title for all existing jobs.
# This is a one-time fix script. After this, whatjobs.php will populate it at import time.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# First, ensure the column exists.
$cols = $dbhr->preQuery("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'canonical_title'");

if (count($cols) == 0) {
    error_log("Adding canonical_title column to jobs table...");
    $dbhm->preExec("ALTER TABLE jobs ADD COLUMN canonical_title varchar(255) DEFAULT NULL");
    $dbhm->preExec("ALTER TABLE jobs ADD KEY canonical_title (canonical_title)");
    error_log("Column added.");
}

# Backfill in batches.
$batchSize = 1000;
$lastId = 0;
$updated = 0;
$unmapped = 0;

do {
    $jobs = $dbhr->preQuery("SELECT id, title FROM jobs WHERE id > ? AND canonical_title IS NULL ORDER BY id LIMIT $batchSize", [$lastId]);

    if (count($jobs) == 0) {
        break;
    }

    foreach ($jobs as $job) {
        $lastId = $job['id'];
        $canonical = Pollinations::canonicalJobTitle($job['title']);

        if ($canonical) {
            $dbhm->preExec("UPDATE jobs SET canonical_title = ? WHERE id = ?", [$canonical, $job['id']]);
            $updated++;
        } else {
            $unmapped++;
        }
    }

    error_log("Processed up to id $lastId: $updated updated, $unmapped unmapped");
} while (TRUE);

error_log("Done. $updated jobs updated with canonical titles, $unmapped jobs had no canonical mapping.");

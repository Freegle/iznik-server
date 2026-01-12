<?php
#
# Generate AI illustrations for job ads.
# This fetches line drawings from Pollinations.ai for popular job titles.
# Scans for job titles with >10 occurrences and caches images in ai_images table.
#
# Uses batch fetching to detect rate-limiting before saving any images.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Batch size for fetching new images.
const BATCH_SIZE = 5;

# Keep processing until no jobs found or abort requested
do {
    if (file_exists('/tmp/iznik.mail.abort')) {
        error_log("Abort file found, exiting");
        break;
    }

    # Find job titles that:
    # 1. Have more than 10 occurrences in the jobs table
    # 2. Don't already have a cached image in ai_images
    # Order by count descending so we process most common titles first
    $titles = $dbhr->preQuery("
        SELECT j.title, COUNT(*) as cnt
        FROM jobs j
        LEFT JOIN ai_images ai ON ai.name = j.title
        WHERE j.title IS NOT NULL
        AND j.title != ''
        AND j.visible = 1
        AND ai.id IS NULL
        GROUP BY j.title
        ORDER BY cnt DESC
        LIMIT " . BATCH_SIZE . "
    ", []);

    if (count($titles) == 0) {
        # No more job titles to process
        break;
    }

    # Build batch request, skipping items that have failed too many times.
    $batchItems = [];
    foreach ($titles as $row) {
        $title = $row['title'];
        $count = $row['cnt'];
        $itemName = trim($title);

        if (empty($itemName)) {
            continue;
        }

        # Check if this item has failed too many times - skip it.
        if (Pollinations::shouldSkipItem($itemName)) {
            error_log("Skipping job title '$itemName' due to previous failures");
            continue;
        }

        $batchItems[] = [
            'name' => $title,
            'prompt' => Pollinations::buildJobPrompt($itemName),
            'width' => 200,
            'height' => 200,
            'count' => $count,
            'jobid' => NULL
        ];
    }

    if (count($batchItems) == 0) {
        break;
    }

    error_log("Fetching batch of " . count($batchItems) . " job illustrations");
    $batchResult = Pollinations::fetchBatch($batchItems, 120);

    if ($batchResult === FALSE) {
        # Rate-limited - record failures for all items in batch so they eventually get skipped.
        foreach ($batchItems as $item) {
            Pollinations::recordFailure($item['name']);
        }
        error_log("Batch rate-limited, waiting 60 seconds");
        sleep(60);
        continue;
    }

    # Record failures for items that failed.
    foreach ($batchResult['failed'] as $failedName => $dummy) {
        Pollinations::recordFailure($failedName);
    }

    # Save all successful images.
    foreach ($batchResult['results'] as $result) {
        $title = $result['name'];
        $data = $result['data'];
        $hash = $result['hash'];

        $uid = Pollinations::uploadAndCache($title, $data, $hash);

        if ($uid) {
            error_log("Created illustration for job title '$title': $uid");
        }
    }

} while (TRUE);

Utils::unlockScript($lockh);

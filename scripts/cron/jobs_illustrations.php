<?php
#
# Generate AI illustrations for canonical job categories.
# Iterates over the ~200 canonical job titles defined in Pollinations::CANONICAL_JOBS.
# Each canonical title has a pre-mapped object for image generation - no GPT calls needed.
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

# Keep processing until all canonical jobs have images or abort requested.
do {
    if (file_exists('/tmp/iznik.mail.abort')) {
        error_log("Abort file found, exiting");
        break;
    }

    # Find canonical job titles that don't already have a cached image.
    $allCanonical = array_keys(Pollinations::CANONICAL_JOBS);
    $placeholders = implode(',', array_fill(0, count($allCanonical), '?'));
    $existing = $dbhr->preQuery(
        "SELECT name FROM ai_images WHERE name IN ($placeholders)",
        array_values($allCanonical)
    );

    $existingNames = array_column($existing, 'name');
    $missing = array_diff($allCanonical, $existingNames);

    if (count($missing) == 0) {
        # All canonical jobs have images.
        break;
    }

    error_log(count($missing) . " canonical job titles still need images");

    # Take next batch.
    $batch = array_slice($missing, 0, BATCH_SIZE);
    $batchItems = [];

    foreach ($batch as $canonicalTitle) {
        # Check if this item has failed too many times - skip it.
        if (Pollinations::shouldSkipItem($canonicalTitle)) {
            error_log("Skipping canonical title '$canonicalTitle' due to previous failures");
            continue;
        }

        $object = Pollinations::CANONICAL_JOBS[$canonicalTitle];

        error_log("Canonical job '$canonicalTitle' => object '$object'");

        $batchItems[] = [
            'name' => $canonicalTitle,
            'prompt' => Pollinations::buildJobPrompt($object),
            'width' => 200,
            'height' => 200,
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
            error_log("Created illustration for canonical job '$title': $uid");
        }
    }

} while (TRUE);

Utils::unlockScript($lockh);

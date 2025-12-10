<?php
#
# Generate AI illustrations for job ads.
# This fetches line drawings from Pollinations.ai for popular job titles.
# Scans for job titles with >10 occurrences and caches images in ai_images table.
#
# Structured to process messages for preference - only fetches one job image per loop iteration
# to avoid blocking message processing for hours.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Keep processing until no jobs found or abort requested
do {
    if (file_exists('/tmp/iznik.mail.abort')) {
        error_log("Abort file found, exiting");
        break;
    }

    # Find a job title that:
    # 1. Has more than 10 occurrences in the jobs table
    # 2. Doesn't already have a cached image in ai_images
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
        HAVING cnt > 10
        ORDER BY cnt DESC
        LIMIT 1
    ");

    if (count($titles) == 0) {
        # No more job titles to process
        break;
    }

    $title = $titles[0]['title'];
    $count = $titles[0]['cnt'];

    # Clean up the title for use in prompt
    $itemName = trim($title);

    if (empty($itemName)) {
        continue;
    }

    # Prompt injection defense (defense in depth):
    # Strip common prompt injection keywords from user input.
    # Note: No prompt injection defense is foolproof, but risk here is low (worst case: odd image).
    $itemName = str_replace('CRITICAL:', '', $itemName);
    $itemName = str_replace('Draw only', '', $itemName);

    # Build the Pollinations.ai URL - use job-appropriate prompt
    $prompt = urlencode(
        "simple cute cartoon " . $itemName . " white line drawing on solid dark forest green background, " .
        "minimalist icon style, absolutely no text, no words, no letters, no numbers, no labels, " .
        "no writing, no captions, no signs, no speech bubbles, no border, filling the entire frame"
    );
    $url = "https://image.pollinations.ai/prompt/{$prompt}?width=200&height=200&nologo=true&seed=1";

    error_log("Fetching illustration for job title '$title' (count: $count)");

    # Fetch the image with 2 minute timeout
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120
        ]
    ]);

    $data = @file_get_contents($url, FALSE, $ctx);

    if ($data && strlen($data) > 0) {
        # Upload to tusd
        $t = new Tus();
        $tusUrl = $t->upload(NULL, 'image/jpeg', $data);

        if ($tusUrl) {
            $uid = 'freegletusd-' . basename($tusUrl);

            # Cache in ai_images table
            $dbhm->preExec("INSERT INTO ai_images (name, externaluid) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE externaluid = VALUES(externaluid), created = NOW()", [
                $title,
                $uid
            ]);

            error_log("Created illustration for job title '$title': $uid");
        } else {
            error_log("Failed to upload illustration for job title '$title'");
            sleep(5);
        }
    } else {
        error_log("Failed to fetch illustration for job title '$title'");
        sleep(5);
    }

    # Only process one job image per iteration - this allows messages to be processed between
    # job image fetches, so we don't block message processing for hours
} while (TRUE);

Utils::unlockScript($lockh);

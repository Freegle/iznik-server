<?php
#
# Regenerate AI illustrations for job ads using the two-step approach:
#   1. Ask GPT to map job title -> iconic inanimate object/tool
#   2. Generate an image of that object (same prompt as message illustrations)
#
# This replaces images generated with the old prompt which often contained people.
# When multiple job titles map to the same object, the image is fetched once and
# reused for all of them.
#
# Usage:
#   php fix_job_illustrations_regen.php [--limit N] [--dry-run]
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Parse command line arguments.
$limit = NULL;
$dryRun = FALSE;

foreach ($argv as $i => $arg) {
    if ($arg === '--dry-run') {
        $dryRun = TRUE;
    } elseif ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
    }
}

# Check for OpenAI API key (needed for objectForJob).
if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    error_log("ERROR: OPENAI_API_KEY not defined in config.");
    exit(1);
}

# Find all ai_images entries that correspond to job titles.
$sql = "SELECT DISTINCT ai.id, ai.name, ai.externaluid
        FROM ai_images ai
        INNER JOIN jobs j ON j.title = ai.name AND j.visible = 1
        ORDER BY ai.id ASC";
if ($limit) {
    $sql .= " LIMIT $limit";
}

$images = $dbhr->preQuery($sql);
$total = count($images);
echo "Found $total job images to regenerate.\n\n";

if ($dryRun) {
    foreach ($images as $img) {
        echo "  [{$img['id']}] {$img['name']}\n";
    }
    exit(0);
}

$tusBase = rtrim(TUS_UPLOADER, '/') . '/';
$regenerated = 0;
$reused = 0;
$failed = 0;

# Cache of object name -> image data+hash, so we fetch each object only once.
$objectCache = [];

foreach ($images as $idx => $image) {
    $id = $image['id'];
    $name = $image['name'];
    $oldUid = $image['externaluid'];
    $num = $idx + 1;

    if (file_exists('/tmp/iznik.mail.abort')) {
        echo "Abort file found, stopping.\n";
        break;
    }

    echo "[$num/$total] $name: ";

    # Step 1: Map job title to object via LLM.
    $object = Pollinations::objectForJob($name);

    if (!$object) {
        echo "FAILED (LLM mapping)\n";
        $failed++;
        continue;
    }

    echo "$object -> ";

    # Step 2: Get image data - either from cache or fetch new.
    # We bypass Pollinations::fetchImage() because its duplicate hash detection
    # would flag different job titles that map to the same object as rate-limited.
    if (isset($objectCache[$object])) {
        # Reuse previously fetched image for this object.
        $data = $objectCache[$object]['data'];
        $hash = $objectCache[$object]['hash'];
        echo "(reused) -> ";
    } else {
        # Fetch raw image directly from Pollinations.
        $prompt = Pollinations::buildJobPrompt($object);
        $url = "https://image.pollinations.ai/prompt/" . urlencode($prompt) .
               "?width=200&height=200&nologo=true&seed=1";
        if (defined('POLLINATIONS_API_KEY') && POLLINATIONS_API_KEY) {
            $url .= "&key=" . urlencode(POLLINATIONS_API_KEY);
        }

        $ctx = stream_context_create(['http' => ['timeout' => 120]]);
        $data = @file_get_contents($url, FALSE, $ctx);

        if (!$data || strlen($data) < 1000) {
            echo "FAILED (image fetch)\n";
            $failed++;
            sleep(5);
            continue;
        }

        $hash = Pollinations::getImageHash($data);
        $objectCache[$object] = ['data' => $data, 'hash' => $hash];

        # Small delay between Pollinations requests.
        sleep(3);
    }

    # Upload and cache under this job title.
    $uid = Pollinations::uploadAndCache($name, $data, $hash);

    if (!$uid) {
        echo "FAILED (upload)\n";
        $failed++;
        continue;
    }

    echo "OK ($uid)\n";
    $regenerated++;
}

echo "\n=== COMPLETE ===\n";
echo "Regenerated: $regenerated\n";
echo "Failed: $failed\n";
echo "Total: $total\n";

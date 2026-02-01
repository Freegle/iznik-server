<?php
#
# Regenerate AI illustrations for all canonical job categories.
# Uses the pre-mapped objects from Pollinations::CANONICAL_JOBS - no GPT calls needed.
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

$allCanonical = Pollinations::CANONICAL_JOBS;
$total = count($allCanonical);

if ($limit) {
    $allCanonical = array_slice($allCanonical, 0, $limit, TRUE);
}

echo "Regenerating " . count($allCanonical) . " canonical job images (of $total total).\n\n";

if ($dryRun) {
    foreach ($allCanonical as $title => $object) {
        echo "  $title => $object\n";
    }
    exit(0);
}

$regenerated = 0;
$failed = 0;
$idx = 0;

foreach ($allCanonical as $canonicalTitle => $object) {
    $idx++;

    if (file_exists('/tmp/iznik.mail.abort')) {
        echo "Abort file found, stopping.\n";
        break;
    }

    echo "[$idx/" . count($allCanonical) . "] $canonicalTitle ($object): ";

    # Fetch image directly from Pollinations.
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

    # Upload and cache under the canonical title.
    $uid = Pollinations::uploadAndCache($canonicalTitle, $data, $hash);

    if (!$uid) {
        echo "FAILED (upload)\n";
        $failed++;
        continue;
    }

    echo "OK ($uid)\n";
    $regenerated++;

    # Small delay between requests.
    sleep(3);
}

echo "\n=== COMPLETE ===\n";
echo "Regenerated: $regenerated\n";
echo "Failed: $failed\n";
echo "Total: " . count($allCanonical) . "\n";

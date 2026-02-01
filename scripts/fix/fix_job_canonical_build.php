<?php
#
# One-off script to help build/review the canonical job mapping.
# Pulls all distinct job titles from DB and attempts to map them via canonicalJobTitle().
# Outputs statistics and lists unmapped titles for review.
#
# Usage:
#   php fix_job_canonical_build.php [--unmapped-only] [--min-count N]
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Parse command line arguments.
$unmappedOnly = FALSE;
$minCount = 1;

foreach ($argv as $i => $arg) {
    if ($arg === '--unmapped-only') {
        $unmappedOnly = TRUE;
    } elseif ($arg === '--min-count' && isset($argv[$i + 1])) {
        $minCount = (int)$argv[$i + 1];
    }
}

# Get all distinct job titles with counts.
$titles = $dbhr->preQuery("
    SELECT title, COUNT(*) as cnt
    FROM jobs
    WHERE visible = 1 AND title IS NOT NULL AND title != ''
    GROUP BY title
    ORDER BY cnt DESC
");

$totalTitles = count($titles);
$mapped = 0;
$unmapped = 0;
$mappedJobs = 0;
$unmappedJobs = 0;
$canonicalCounts = [];
$unmappedList = [];

foreach ($titles as $row) {
    $title = $row['title'];
    $count = $row['cnt'];

    $canonical = Pollinations::canonicalJobTitle($title);

    if ($canonical) {
        $mapped++;
        $mappedJobs += $count;
        if (!isset($canonicalCounts[$canonical])) {
            $canonicalCounts[$canonical] = 0;
        }
        $canonicalCounts[$canonical] += $count;
    } else {
        $unmapped++;
        $unmappedJobs += $count;
        if ($count >= $minCount) {
            $unmappedList[] = ['title' => $title, 'count' => $count];
        }
    }
}

$totalJobs = $mappedJobs + $unmappedJobs;

echo "=== CANONICAL JOB MAPPING STATISTICS ===\n\n";
echo "Distinct titles: $totalTitles\n";
echo "Mapped:   $mapped titles ($mappedJobs jobs, " . round(100 * $mappedJobs / $totalJobs, 1) . "%)\n";
echo "Unmapped: $unmapped titles ($unmappedJobs jobs, " . round(100 * $unmappedJobs / $totalJobs, 1) . "%)\n";
echo "Canonical categories used: " . count($canonicalCounts) . " of " . count(Pollinations::CANONICAL_JOBS) . "\n\n";

if (!$unmappedOnly) {
    echo "=== TOP CANONICAL CATEGORIES ===\n\n";
    arsort($canonicalCounts);
    foreach (array_slice($canonicalCounts, 0, 30, TRUE) as $canonical => $count) {
        echo sprintf("  %6d  %s\n", $count, $canonical);
    }
    echo "\n";
}

if (count($unmappedList) > 0) {
    echo "=== UNMAPPED TITLES (count >= $minCount) ===\n\n";
    foreach ($unmappedList as $item) {
        echo sprintf("  %6d  %s\n", $item['count'], $item['title']);
    }
}

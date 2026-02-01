<?php
#
# Clean up old ai_images entries that were created per raw job title (before canonical mapping).
# These old entries often contain images of people which we no longer want.
#
# Only deletes entries where the name is NOT a canonical job title and is NOT used for
# message illustrations (which have different naming patterns).
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$dryRun = in_array('--dry-run', $argv ?? []);

if ($dryRun) {
    error_log("DRY RUN - no deletions will be made");
}

# Get all canonical job titles - these are the ones we want to KEEP.
$canonicalTitles = array_keys(Pollinations::CANONICAL_JOBS);
$keepSet = array_flip($canonicalTitles);

# Get all ai_images entries.
$images = $dbhr->preQuery("SELECT id, name FROM ai_images ORDER BY id");

$deleted = 0;
$kept = 0;

foreach ($images as $img) {
    $name = $img['name'];

    if (isset($keepSet[$name])) {
        # This is a canonical job title - keep it.
        $kept++;
        continue;
    }

    # Check if this looks like a job title (vs a message illustration).
    # Job titles from WhatJobs are typically mixed case like "Nurse Clinician" or "HGV Driver".
    # Message illustrations use different naming patterns (message subjects, item names).
    #
    # We check if this name matches any current job title in the database.
    $isJobTitle = $dbhr->preQuery("SELECT 1 FROM jobs WHERE title = ? LIMIT 1", [$name]);

    if (count($isJobTitle) > 0) {
        # This is an old per-title job image - delete it.
        if ($dryRun) {
            error_log("Would delete ai_image id={$img['id']} name='$name'");
        } else {
            $dbhm->preExec("DELETE FROM ai_images WHERE id = ?", [$img['id']]);
            error_log("Deleted ai_image id={$img['id']} name='$name'");
        }
        $deleted++;
    } else {
        # Not a job title - keep (probably a message illustration).
        $kept++;
    }
}

error_log("Done. " . ($dryRun ? "Would delete" : "Deleted") . " $deleted old job images, kept $kept.");

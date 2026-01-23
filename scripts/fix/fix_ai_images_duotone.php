<?php
/**
 * Migration script to apply duotone filter to existing AI-generated images.
 *
 * This script:
 * 1. Queries the ai_images table for all existing AI images
 * 2. Fetches each image from TUS storage
 * 3. Applies the duotoneGreen() filter
 * 4. Re-uploads to TUS
 * 5. Updates the externaluid in ai_images and messages_attachments tables
 *
 * Usage: php fix_ai_images_duotone.php [--dry-run] [--limit=N]
 *
 * Options:
 *   --dry-run   Show what would be done without making changes
 *   --limit=N   Process only N images (useful for testing)
 */

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once IZNIK_BASE . '/include/db.php';
require_once IZNIK_BASE . '/include/misc/Image.php';
require_once IZNIK_BASE . '/include/misc/Tus.php';

global $dbhr, $dbhm;

$opts = getopt('', ['dry-run', 'limit:']);
$dryRun = isset($opts['dry-run']);
$limit = isset($opts['limit']) ? intval($opts['limit']) : NULL;

echo "AI Images Duotone Migration\n";
echo "===========================\n";
if ($dryRun) {
    echo "DRY RUN MODE - no changes will be made\n";
}
echo "\n";

# Get the TUS upload server URL from config.
$tusUploader = defined('TUS_UPLOADER') ? TUS_UPLOADER : NULL;
if (!$tusUploader) {
    echo "ERROR: TUS_UPLOADER not configured\n";
    exit(1);
}

# Query all AI images.
$sql = "SELECT id, name, externaluid, imagehash FROM ai_images WHERE externaluid LIKE 'freegletusd-%'";
if ($limit) {
    $sql .= " LIMIT $limit";
}

$images = $dbhr->preQuery($sql);
$total = count($images);
echo "Found $total AI images to process\n\n";

$processed = 0;
$errors = 0;
$skipped = 0;

foreach ($images as $image) {
    $id = $image['id'];
    $name = $image['name'];
    $oldUid = $image['externaluid'];

    echo "Processing: $name (ID: $id)\n";

    # Extract TUS filename from externaluid.
    $tusFile = str_replace('freegletusd-', '', $oldUid);
    $imageUrl = rtrim($tusUploader, '/') . '/' . $tusFile;

    # Fetch the image.
    $ctx = stream_context_create([
        'http' => ['timeout' => 30]
    ]);
    $data = @file_get_contents($imageUrl, FALSE, $ctx);

    if (!$data) {
        echo "  ERROR: Failed to fetch image from $imageUrl\n";
        $errors++;
        continue;
    }

    echo "  Fetched " . strlen($data) . " bytes\n";

    # Apply duotone filter.
    $img = new Image($data);
    if (!$img->img) {
        echo "  ERROR: Failed to load image\n";
        $errors++;
        continue;
    }

    $img->duotoneGreen();
    $newData = $img->getData(90);

    if (!$newData) {
        echo "  ERROR: Failed to process image\n";
        $errors++;
        continue;
    }

    echo "  Applied duotone filter, new size: " . strlen($newData) . " bytes\n";

    if ($dryRun) {
        echo "  DRY RUN: Would upload and update database\n";
        $processed++;
        continue;
    }

    # Upload to TUS.
    $tus = new Tus();
    $newTusUrl = $tus->upload(NULL, 'image/jpeg', $newData);

    if (!$newTusUrl) {
        echo "  ERROR: Failed to upload to TUS\n";
        $errors++;
        continue;
    }

    $newUid = 'freegletusd-' . basename($newTusUrl);
    echo "  Uploaded to TUS: $newUid\n";

    # Update ai_images table.
    $dbhm->preExec(
        "UPDATE ai_images SET externaluid = ? WHERE id = ?",
        [$newUid, $id]
    );

    # Update messages_attachments table.
    $updated = $dbhm->preExec(
        "UPDATE messages_attachments SET externaluid = ? WHERE externaluid = ?",
        [$newUid, $oldUid]
    );
    $attachmentsUpdated = $dbhm->rowsAffected();

    echo "  Updated ai_images and $attachmentsUpdated message attachments\n";

    $processed++;
}

echo "\n";
echo "===========================\n";
echo "Summary:\n";
echo "  Total images: $total\n";
echo "  Processed: $processed\n";
echo "  Errors: $errors\n";
echo "  Skipped: $skipped\n";
if ($dryRun) {
    echo "\nThis was a dry run. Run without --dry-run to apply changes.\n";
}

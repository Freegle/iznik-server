<?php
#
# Scan AI-generated images for ones that contain people using GPT-4o-mini vision.
# Uses parallel API calls (curl_multi) to check multiple images simultaneously.
#
# Usage:
#   php fix_ai_images_people.php [--auto] [--limit N] [--dry-run]
#   php fix_ai_images_people.php --regenerate "Item name"
#   php fix_ai_images_people.php --auto-fix [--limit N] [--max-retries N]
#   php fix_ai_images_people.php --fill-missing [--limit N] [--max-retries N] [--days N]
#
# Options:
#   --auto              Automatically delete images with people (no pause, no regenerate)
#   --auto-fix          Full auto: detect, regenerate, verify, reattach if clean
#   --fill-missing      Generate images for posts without attachments (last N days)
#   --days N            Days to look back for --fill-missing (default 90)
#   --max-retries N     Max regeneration attempts per item (default 3)
#   --limit N           Only process N images/messages
#   --dry-run           Don't make any changes, just report
#   --reset             Delete progress file and start from scratch
#   --regenerate "name" Force regeneration of a specific item and review it
#
# When a person is detected, the script pauses and shows:
#   - The image URL (open in browser to verify)
#   - Options: [d]elete, [s]kip, [q]uit
#
# Performance: Checks 5 images in parallel per batch, significantly faster
# than sequential checking. Adjust PARALLEL_BATCH_SIZE constant if needed.
#
# Requires OPENAI_API_KEY to be defined in config.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Parse command line arguments.
$limit = NULL;
$dryRun = FALSE;
$autoMode = FALSE;
$autoFixMode = FALSE;
$fillMissing = FALSE;
$maxRetries = 3;
$days = 90;
$resetProgress = FALSE;
$regenerateItem = NULL;

foreach ($argv as $i => $arg) {
    if ($arg === '--dry-run') {
        $dryRun = TRUE;
    } elseif ($arg === '--auto') {
        $autoMode = TRUE;
    } elseif ($arg === '--auto-fix') {
        $autoFixMode = TRUE;
    } elseif ($arg === '--fill-missing') {
        $fillMissing = TRUE;
    } elseif ($arg === '--max-retries' && isset($argv[$i + 1])) {
        $maxRetries = (int)$argv[$i + 1];
    } elseif ($arg === '--days' && isset($argv[$i + 1])) {
        $days = (int)$argv[$i + 1];
    } elseif ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
    } elseif ($arg === '--reset') {
        $resetProgress = TRUE;
    } elseif ($arg === '--regenerate' && isset($argv[$i + 1])) {
        $regenerateItem = $argv[$i + 1];
    }
}

# Check for OpenAI API key.
if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    error_log("ERROR: OPENAI_API_KEY not defined in config. Add it to your iznik.conf file:");
    error_log("  define('OPENAI_API_KEY', 'sk-...');");
    exit(1);
}

# Handle --regenerate mode: force regeneration of a specific item.
if ($regenerateItem) {
    $tusBase = rtrim(TUS_UPLOADER, '/') . '/';
    $msgids = [];

    echo "=== REGENERATE MODE ===\n";
    echo "Item: $regenerateItem\n\n";

    # Find existing cached image and messages using it.
    $existing = $dbhr->preQuery("SELECT id, externaluid FROM ai_images WHERE name = ?", [$regenerateItem]);
    if (count($existing) > 0) {
        $oldUid = $existing[0]['externaluid'];

        # Find messages using this image so we can reattach later.
        if ($oldUid) {
            $msgRows = $dbhr->preQuery(
                "SELECT msgid FROM messages_attachments WHERE externaluid = ? AND JSON_EXTRACT(externalmods, '$.ai') = TRUE",
                [$oldUid]
            );
            foreach ($msgRows as $row) {
                if ($row['msgid']) {
                    $msgids[] = $row['msgid'];
                }
            }
            echo "Found " . count($msgids) . " message(s) using this image.\n";

            # Delete the attachments.
            $dbhm->preExec(
                "DELETE FROM messages_attachments WHERE externaluid = ? AND JSON_EXTRACT(externalmods, '$.ai') = TRUE",
                [$oldUid]
            );
        }

        # Delete the cached image.
        echo "Deleting existing cached image...\n";
        $dbhm->preExec("DELETE FROM ai_images WHERE name = ?", [$regenerateItem]);
    }

    # Regenerate the image.
    echo "Generating new image with current prompt...\n\n";
    $result = regenerateImage($dbhr, $dbhm, $regenerateItem, $tusBase);

    if ($result) {
        echo "New image URL:\n";
        echo "  {$result['url']}\n\n";

        # Also show via IMAGE_DELIVERY if available.
        if (defined('IMAGE_DELIVERY') && IMAGE_DELIVERY) {
            $viewUrl = IMAGE_DELIVERY . "?url=" . urlencode($result['url']) . "&w=512&output=jpg";
            echo "View URL:\n";
            echo "  $viewUrl\n\n";
        }

        echo "Does this image look correct? [y/n/r=regenerate again]: ";
        $handle = fopen("php://stdin", "r");
        $choice = strtolower(trim(fgets($handle)));

        while ($choice === 'r') {
            echo "\nRegenerating again...\n";
            $dbhm->preExec("DELETE FROM ai_images WHERE name = ?", [$regenerateItem]);
            $result = regenerateImage($dbhr, $dbhm, $regenerateItem, $tusBase);
            if ($result) {
                echo "New image URL:\n";
                echo "  {$result['url']}\n\n";
                if (defined('IMAGE_DELIVERY') && IMAGE_DELIVERY) {
                    $viewUrl = IMAGE_DELIVERY . "?url=" . urlencode($result['url']) . "&w=512&output=jpg";
                    echo "View URL:\n";
                    echo "  $viewUrl\n\n";
                }
                echo "Does this image look correct? [y/n/r=regenerate again]: ";
                $choice = strtolower(trim(fgets($handle)));
            } else {
                echo "Regeneration failed.\n";
                break;
            }
        }

        if ($choice === 'y') {
            # Reattach to messages that were using the old image.
            if (count($msgids) > 0) {
                attachImageToMessages($dbhm, $result['uid'], $msgids);
                echo "-> Approved and reattached to " . count($msgids) . " message(s).\n";
            } else {
                echo "-> Approved. Image cached for future use.\n";
            }
        } else {
            echo "-> Not approved. Run --regenerate \"$regenerateItem\" to try again.\n";
        }
    } else {
        echo "ERROR: Regeneration failed.\n";
        exit(1);
    }

    exit(0);
}

# Handle --fill-missing mode: generate images for posts without attachments.
if ($fillMissing) {
    $tusBase = rtrim(TUS_UPLOADER, '/') . '/';

    echo "=== FILL MISSING MODE ===\n";
    echo "Looking back $days days for messages without attachments...\n\n";

    # Find messages from last N days without attachments, not AI-declined.
    $since = date('Y-m-d H:i:s', strtotime("-$days days"));
    $sql = "SELECT DISTINCT m.id as msgid, m.subject
            FROM messages m
            INNER JOIN messages_groups mg ON mg.msgid = m.id
            LEFT JOIN messages_attachments ma ON ma.msgid = m.id
            LEFT JOIN messages_ai_declined maid ON maid.msgid = m.id
            WHERE mg.arrival >= ?
            AND mg.collection = 'Approved'
            AND ma.id IS NULL
            AND maid.msgid IS NULL
            AND m.subject IS NOT NULL
            AND m.subject != ''
            ORDER BY mg.arrival DESC";
    if ($limit) {
        $sql .= " LIMIT $limit";
    }
    $messages = $dbhr->preQuery($sql, [$since]);

    echo "Found " . count($messages) . " messages without attachments.\n\n";

    if ($dryRun) {
        foreach ($messages as $msg) {
            $itemName = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i', '', $msg['subject']);
            $itemName = preg_replace('/\s*\([^)]+\)\s*$/', '', $itemName);
            $itemName = trim($itemName);
            if (!empty($itemName)) {
                echo "[{$msg['msgid']}] $itemName: DRY RUN\n";
            }
        }
        exit(0);
    }

    $generated = 0;
    $failed = 0;

    # First pass: collect all messages and their cached image info.
    $withCache = [];
    $needsGeneration = [];

    foreach ($messages as $msg) {
        $msgid = $msg['msgid'];
        $subject = $msg['subject'];

        # Extract item name from subject.
        $itemName = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i', '', $subject);
        $itemName = preg_replace('/\s*\([^)]+\)\s*$/', '', $itemName);
        $itemName = trim($itemName);

        if (empty($itemName)) {
            continue;
        }

        # Check if we have a cached image for this item.
        $cached = $dbhr->preQuery("SELECT externaluid FROM ai_images WHERE name = ?", [$itemName]);

        if (count($cached) > 0 && $cached[0]['externaluid']) {
            $uid = $cached[0]['externaluid'];
            $tusFile = str_replace('freegletusd-', '', $uid);
            $imageUrl = $tusBase . $tusFile . '/';
            $withCache[] = [
                'msgid' => $msgid,
                'itemName' => $itemName,
                'uid' => $uid,
                'url' => $imageUrl,
                'id' => $msgid  # Use msgid as id for parallel check
            ];
        } else {
            $needsGeneration[] = [
                'msgid' => $msgid,
                'itemName' => $itemName
            ];
        }
    }

    echo "Messages with cached images: " . count($withCache) . "\n";
    echo "Messages needing generation: " . count($needsGeneration) . "\n\n";

    # Check cached images in parallel batches.
    if (count($withCache) > 0) {
        echo "=== Checking cached images in parallel batches ===\n";

        for ($i = 0; $i < count($withCache); $i += PARALLEL_BATCH_SIZE) {
            $batch = array_slice($withCache, $i, PARALLEL_BATCH_SIZE);
            $batchStart = $i + 1;
            $batchEnd = min($i + PARALLEL_BATCH_SIZE, count($withCache));

            echo "Checking batch [$batchStart-$batchEnd/" . count($withCache) . "]... ";
            $t1 = microtime(TRUE);
            $results = checkImagesForPeopleParallel($batch);
            $t2 = microtime(TRUE);
            echo round(($t2-$t1)*1000) . "ms\n";

            foreach ($batch as $item) {
                $msgid = $item['msgid'];
                $itemName = $item['itemName'];
                $uid = $item['uid'];
                $checkResult = $results[$msgid] ?? NULL;

                if ($checkResult === FALSE) {
                    # Clean - attach it.
                    $dbhm->preExec(
                        "INSERT INTO messages_attachments (msgid, externaluid, externalmods, contenttype) VALUES (?, ?, ?, ?)",
                        [$msgid, $uid, json_encode(['ai' => TRUE, 'peopleChecked' => TRUE]), 'image/jpeg']
                    );
                    echo "  [$msgid] $itemName: Used cached image (clean).\n";
                    $generated++;
                } elseif ($checkResult === TRUE) {
                    # Has people - need to regenerate.
                    echo "  [$msgid] $itemName: Cached image has people, queued for regeneration.\n";
                    $dbhm->preExec("DELETE FROM ai_images WHERE name = ?", [$itemName]);
                    $needsGeneration[] = [
                        'msgid' => $msgid,
                        'itemName' => $itemName
                    ];
                } else {
                    echo "  [$msgid] $itemName: API error, queued for regeneration.\n";
                    $needsGeneration[] = [
                        'msgid' => $msgid,
                        'itemName' => $itemName
                    ];
                }
            }
        }
        echo "\n";
    }

    # Process messages needing generation (sequential to avoid rate limiting).
    if (count($needsGeneration) > 0) {
        echo "=== Generating new images ===\n";

        foreach ($needsGeneration as $msg) {
            $msgid = $msg['msgid'];
            $itemName = $msg['itemName'];

            echo "[$msgid] $itemName: ";

            $isClean = FALSE;
            for ($retry = 1; $retry <= $maxRetries; $retry++) {
                echo "Generating (attempt $retry/$maxRetries)... ";
                $result = regenerateImage($dbhr, $dbhm, $itemName, $tusBase);

                if (!$result) {
                    echo "FAILED\n";
                    break;
                }

                # Check for people.
                $checkResult = checkImageForPeople($result['url']);

                if ($checkResult === FALSE) {
                    # Clean - attach it.
                    $dbhm->preExec(
                        "INSERT INTO messages_attachments (msgid, externaluid, externalmods, contenttype) VALUES (?, ?, ?, ?)",
                        [$msgid, $result['uid'], json_encode(['ai' => TRUE, 'peopleChecked' => TRUE]), 'image/jpeg']
                    );
                    echo "CLEAN - attached.\n";
                    $isClean = TRUE;
                    $generated++;
                    break;
                } elseif ($checkResult === TRUE) {
                    echo "HAS PEOPLE";
                    if ($retry < $maxRetries) {
                        echo ", retrying...\n";
                        $dbhm->preExec("DELETE FROM ai_images WHERE name = ?", [$itemName]);
                    } else {
                        echo "\n";
                    }
                } else {
                    echo "API ERROR\n";
                    break;
                }
            }

            if (!$isClean) {
                # Mark as AI-declined so we don't keep trying.
                $dbhm->preExec("INSERT IGNORE INTO messages_ai_declined (msgid) VALUES (?)", [$msgid]);
                echo "  -> Marked as AI-declined.\n";
                $failed++;
            }
        }
    }

    echo "\n=== FILL MISSING COMPLETE ===\n";
    echo "Generated: $generated\n";
    echo "Failed (marked as declined): $failed\n";

    exit(0);
}

# Progress tracking file.
$PROGRESS_FILE = '/tmp/ai_images_people_progress.json';

# Batch size for parallel API calls.
const PARALLEL_BATCH_SIZE = 5;

# Handle --reset option.
if ($resetProgress) {
    if (file_exists($PROGRESS_FILE)) {
        @unlink($PROGRESS_FILE);
        echo "Progress file deleted. Starting from scratch.\n";
    } else {
        echo "No progress file found.\n";
    }
}

# Load progress (last processed ID).
$lastId = 0;
if (file_exists($PROGRESS_FILE)) {
    $progress = json_decode(file_get_contents($PROGRESS_FILE), TRUE);
    $lastId = $progress['last_id'] ?? 0;
    error_log("Resuming from ID $lastId");
}

# Get AI images that haven't been people-checked yet, ordered by most frequently used.
# Skip images where ALL attachments already have peopleChecked = true.
# Also get attachment IDs for fast updates.
$sql = "SELECT ai.id, ai.name, ai.externaluid,
               COUNT(ma.id) as usage_count,
               SUM(CASE WHEN JSON_EXTRACT(ma.externalmods, '$.peopleChecked') = TRUE THEN 1 ELSE 0 END) as checked_count,
               GROUP_CONCAT(ma.id) as attachment_ids
        FROM ai_images ai
        LEFT JOIN messages_attachments ma ON ma.externaluid = ai.externaluid
        WHERE ai.id > ?
        GROUP BY ai.id, ai.name, ai.externaluid
        HAVING checked_count = 0 OR checked_count IS NULL OR usage_count = 0
        ORDER BY usage_count DESC, ai.id ASC";
if ($limit) {
    $sql .= " LIMIT $limit";
}
$images = $dbhr->preQuery($sql, [$lastId]);

error_log("Found " . count($images) . " AI images to scan" . ($lastId > 0 ? " (after ID $lastId)" : ""));
if ($autoFixMode) {
    error_log("Mode: AUTO-FIX (detect -> regenerate -> verify -> reattach if clean, max $maxRetries retries)");
} elseif ($autoMode) {
    error_log("Mode: AUTO (will delete without prompting)");
} else {
    error_log("Mode: INTERACTIVE (will pause on detection)");
}
error_log("");

$deleted = 0;
$skipped = 0;
$fixed = 0;
$unfixable = 0;
$processed = 0;
$total = count($images);

# Process images in batches for parallel API calls.
$tusBase = rtrim(TUS_UPLOADER, '/') . '/';
$handle = fopen("php://stdin", "r");
$quit = FALSE;

for ($i = 0; $i < count($images) && !$quit; $i += PARALLEL_BATCH_SIZE) {
    $batch = array_slice($images, $i, PARALLEL_BATCH_SIZE);
    $toCheck = [];

    # Build batch of images to check.
    foreach ($batch as $image) {
        $id = $image['id'];
        $name = $image['name'];
        $uid = $image['externaluid'];

        if (!$uid || strpos($uid, 'freegletusd-') === FALSE) {
            continue;
        }

        # Construct the image URL.
        $tusFile = str_replace('freegletusd-', '', $uid);
        $imageUrl = $tusBase . $tusFile . '/';

        # Build a viewable URL for the user.
        $viewUrl = $imageUrl;
        if (defined('IMAGE_DELIVERY') && IMAGE_DELIVERY) {
            $viewUrl = IMAGE_DELIVERY . "?url=" . urlencode($imageUrl) . "&w=512&output=jpg";
        }

        $toCheck[] = [
            'id' => $id,
            'name' => $name,
            'url' => $imageUrl,
            'viewUrl' => $viewUrl,
            'usageCount' => $image['usage_count'] ?? 0,
            'attachment_ids' => $image['attachment_ids'] ?? ''
        ];
    }

    if (empty($toCheck)) {
        continue;
    }

    $batchSize = count($toCheck);
    $batchStart = $processed + 1;
    $batchEnd = $processed + $batchSize;

    if ($dryRun) {
        foreach ($toCheck as $img) {
            $processed++;
            echo "[$processed/$total] DRY RUN: {$img['name']}\n";
            echo "  URL: {$img['viewUrl']}\n";
        }
        continue;
    }

    echo "Checking batch of $batchSize images [$batchStart-$batchEnd/$total]...\n";
    $t1 = microtime(TRUE);
    $results = checkImagesForPeopleParallel($toCheck);
    $t2 = microtime(TRUE);
    echo "  Batch API call: " . round(($t2-$t1)*1000) . "ms\n";

    # Process results.
    foreach ($toCheck as $img) {
        $id = $img['id'];
        $name = $img['name'];
        $result = $results[$id] ?? NULL;

        $processed++;

        if ($result === NULL) {
            echo "  [$processed/$total] $name: API ERROR (skipping)\n";
            continue;
        }

        if ($result === TRUE) {
            echo "  [$processed/$total] $name: PEOPLE DETECTED!\n";
            echo "\n";
            echo "    Item:  $name\n";
            echo "    ID:    $id\n";
            echo "    Used:  {$img['usageCount']} messages\n";
            echo "\n";
            echo "    {$img['viewUrl']}\n";
            echo "\n";

            if ($autoFixMode) {
                # Auto-fix mode: delete, regenerate, verify, reattach if clean.
                $msgids = deleteAiImage($dbhr, $dbhm, $id, $name, $img['attachment_ids']);
                $deleted++;

                $isClean = FALSE;
                for ($retry = 1; $retry <= $maxRetries; $retry++) {
                    echo "    -> Regenerating (attempt $retry/$maxRetries)...\n";
                    $result = regenerateImage($dbhr, $dbhm, $name, $tusBase);

                    if (!$result) {
                        echo "    -> Regeneration failed.\n";
                        break;
                    }

                    # Check the new image for people.
                    $checkResult = checkImageForPeople($result['url']);

                    if ($checkResult === FALSE) {
                        # Clean - no people detected.
                        $isClean = TRUE;
                        echo "    -> New image is clean. Reattaching...\n";

                        # Show the new image URL for verification.
                        if (defined('IMAGE_DELIVERY') && IMAGE_DELIVERY) {
                            $viewUrl = IMAGE_DELIVERY . "?url=" . urlencode($result['url']) . "&w=512&output=jpg";
                            echo "    -> New image: $viewUrl\n";
                        } else {
                            echo "    -> New image: {$result['url']}\n";
                        }

                        attachImageToMessages($dbhm, $result['uid'], $msgids);
                        $fixed++;
                        break;
                    } elseif ($checkResult === TRUE) {
                        echo "    -> New image still has people.\n";
                        # Delete and try again.
                        $dbhm->preExec("DELETE FROM ai_images WHERE name = ?", [$name]);
                    } else {
                        echo "    -> API error checking new image.\n";
                        break;
                    }
                }

                if (!$isClean) {
                    echo "    -> Could not generate clean image after $maxRetries attempts. Left without image.\n";
                    # Mark these messages as AI-declined so we don't keep trying.
                    foreach ($msgids as $msgid) {
                        $dbhm->preExec("INSERT IGNORE INTO messages_ai_declined (msgid) VALUES (?)", [$msgid]);
                    }
                    if (count($msgids) > 0) {
                        echo "    -> Marked " . count($msgids) . " message(s) as AI-declined.\n";
                    }
                    $unfixable++;
                }
                echo "\n";

            } elseif ($autoMode) {
                # Auto-delete mode - just delete, cron will regenerate later.
                deleteAiImage($dbhr, $dbhm, $id, $name, $img['attachment_ids']);
                $deleted++;
                echo "    -> Auto-deleted\n\n";
            } else {
                # Interactive mode - prompt user.
                echo "    Options:\n";
                echo "      [d] Delete this AI image (will regenerate with new prompt)\n";
                echo "      [s] Skip (keep this image)\n";
                echo "      [q] Quit\n";
                echo "\n";
                echo "    Choice [d/s/q]: ";

                $choice = strtolower(trim(fgets($handle)));

                if ($choice === 'd') {
                    $msgids = deleteAiImage($dbhr, $dbhm, $id, $name, $img['attachment_ids']);
                    $deleted++;
                    echo "    -> Deleted. Regenerating...\n";

                    # Regenerate immediately.
                    $result = regenerateImage($dbhr, $dbhm, $name, $tusBase);
                    if ($result) {
                        echo "\n    New image URL:\n";
                        echo "    {$result['url']}\n\n";
                        echo "    Approve? [y/n/r=regenerate again]: ";
                        $approveChoice = strtolower(trim(fgets($handle)));

                        while ($approveChoice === 'r') {
                            echo "    Regenerating again...\n";
                            $dbhm->preExec("DELETE FROM ai_images WHERE name = ?", [$name]);
                            $result = regenerateImage($dbhr, $dbhm, $name, $tusBase);
                            if ($result) {
                                echo "\n    New image URL:\n";
                                echo "    {$result['url']}\n\n";
                                echo "    Approve? [y/n/r=regenerate again]: ";
                                $approveChoice = strtolower(trim(fgets($handle)));
                            } else {
                                echo "    -> Regeneration failed.\n\n";
                                break;
                            }
                        }

                        if ($approveChoice === 'y' && $result) {
                            # Reattach to the messages that were using the old image.
                            attachImageToMessages($dbhm, $result['uid'], $msgids);
                            echo "    -> Approved and reattached.\n\n";
                        } else {
                            echo "    -> Not approved. Run --regenerate \"$name\" to try again.\n\n";
                        }
                    } else {
                        echo "    -> Regeneration failed. Will retry on next cron run.\n\n";
                    }
                } elseif ($choice === 'q') {
                    echo "    -> Quitting. Progress saved.\n";
                    file_put_contents($PROGRESS_FILE, json_encode(['last_id' => $id, 'timestamp' => date('c')]));
                    $quit = TRUE;
                    break;
                } else {
                    echo "    -> Skipped.\n\n";
                    $skipped++;
                }
            }
        } else {
            echo "  [$processed/$total] $name: OK";
            # Mark as checked so we skip it on future runs.
            $t3 = microtime(TRUE);
            markAsChecked($dbhm, $img['attachment_ids']);
            $t4 = microtime(TRUE);
            echo " [mark:" . round(($t4-$t3)*1000) . "ms]\n";
        }

        # Save progress after each image.
        file_put_contents($PROGRESS_FILE, json_encode(['last_id' => $id, 'timestamp' => date('c')]));
    }
}

# Output summary.
echo "\n";
echo "=== SCAN COMPLETE ===\n";
echo "Total scanned: $processed\n";
echo "Deleted: $deleted\n";
if ($autoFixMode) {
    echo "Fixed (clean regeneration): $fixed\n";
    echo "Unfixable (left without image): $unfixable\n";
}
echo "Skipped: $skipped\n";

if ($processed == $total) {
    echo "\nAll images processed. Removing progress file.\n";
    @unlink($PROGRESS_FILE);
}

/**
 * Delete an AI image and return the message IDs that were using it.
 *
 * @param object $dbhr Database handle for reads.
 * @param object $dbhm Database handle for modifications.
 * @param int $id The ai_images row ID.
 * @param string $name The item name.
 * @param string $attachmentIds Comma-separated attachment IDs for fast deletion.
 * @return array List of msgids that were using this image.
 */
function deleteAiImage($dbhr, $dbhm, $id, $name, $attachmentIds) {
    $msgids = [];

    # Get the msgids before deleting so we can reattach later.
    if (!empty($attachmentIds)) {
        $rows = $dbhr->preQuery("SELECT msgid FROM messages_attachments WHERE id IN ($attachmentIds)");
        foreach ($rows as $row) {
            if ($row['msgid']) {
                $msgids[] = $row['msgid'];
            }
        }
    }

    # Delete from ai_images cache - this allows regeneration.
    $dbhm->preExec("DELETE FROM ai_images WHERE id = ?", [$id]);

    # Delete any message attachments using this AI image (by primary key for speed).
    if (!empty($attachmentIds)) {
        $dbhm->preExec("DELETE FROM messages_attachments WHERE id IN ($attachmentIds)");
        $count = $dbhm->rowsAffected();
        if ($count > 0) {
            error_log("  Removed from $count message(s)");
        }
    }

    return $msgids;
}

/**
 * Attach an AI image to a list of messages.
 *
 * @param object $dbhm Database handle for modifications.
 * @param string $externaluid The externaluid of the new image.
 * @param array $msgids List of message IDs to attach to.
 */
function attachImageToMessages($dbhm, $externaluid, $msgids) {
    if (empty($msgids) || empty($externaluid)) {
        return;
    }

    foreach ($msgids as $msgid) {
        $dbhm->preExec(
            "INSERT INTO messages_attachments (msgid, externaluid, externalmods, contenttype) VALUES (?, ?, ?, ?)",
            [$msgid, $externaluid, json_encode(['ai' => TRUE, 'peopleChecked' => TRUE]), 'image/jpeg']
        );
    }

    error_log("  Reattached to " . count($msgids) . " message(s)");
}

/**
 * Check multiple images for people in parallel using curl_multi.
 *
 * @param array $images Array of ['id' => ..., 'url' => ..., 'name' => ..., 'attachment_ids' => ...]
 * @return array Results keyed by image id: ['id' => bool|null, ...]
 */
function checkImagesForPeopleParallel($images) {
    $apiKey = OPENAI_API_KEY;
    $results = [];
    $handles = [];
    $mh = curl_multi_init();

    foreach ($images as $image) {
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Does this image contain any people, human figures, hands, arms, legs, or body parts? Answer only YES or NO.'
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $image['url'],
                                'detail' => 'low'
                            ]
                        ]
                    ]
                ]
            ],
            'max_tokens' => 10
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$image['id']] = $ch;
    }

    # Execute all requests in parallel.
    $running = NULL;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    # Collect results.
    foreach ($images as $image) {
        $ch = $handles[$image['id']];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 429) {
            error_log("  API Error for {$image['name']}: HTTP 429 (rate limited)");
            $results[$image['id']] = 'rate_limited';
        } elseif ($httpCode !== 200 || !$response) {
            error_log("  API Error for {$image['name']}: HTTP $httpCode");
            $results[$image['id']] = NULL;
        } else {
            $data = json_decode($response, TRUE);
            if (!isset($data['choices'][0]['message']['content'])) {
                error_log("  API Error for {$image['name']}: Unexpected response format");
                $results[$image['id']] = NULL;
            } else {
                $answer = strtoupper(trim($data['choices'][0]['message']['content']));
                $results[$image['id']] = strpos($answer, 'YES') !== FALSE;
            }
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

/**
 * Check if an image contains people using GPT-4o-mini vision.
 *
 * @param string $imageUrl URL of the image to check.
 * @return bool|null TRUE if people detected, FALSE if not, NULL on error.
 */
function checkImageForPeople($imageUrl) {
    $apiKey = OPENAI_API_KEY;

    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Does this image contain any people, human figures, hands, arms, legs, or body parts? Answer only YES or NO.'
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $imageUrl,
                            'detail' => 'low'
                        ]
                    ]
                ]
            ]
        ],
        'max_tokens' => 10
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_POST => TRUE,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 429) {
        error_log("  API Error: HTTP 429 (rate limited)");
        return 'rate_limited';
    }

    if ($httpCode !== 200 || !$response) {
        error_log("  API Error: HTTP $httpCode - " . substr($response, 0, 200));
        return NULL;
    }

    $data = json_decode($response, TRUE);

    if (!isset($data['choices'][0]['message']['content'])) {
        error_log("  API Error: Unexpected response format");
        return NULL;
    }

    $answer = strtoupper(trim($data['choices'][0]['message']['content']));

    return strpos($answer, 'YES') !== FALSE;
}

/**
 * Mark attachments as people-checked by their IDs (fast primary key lookup).
 *
 * @param object $dbhm Database handle for modifications.
 * @param string $attachmentIds Comma-separated attachment IDs from GROUP_CONCAT.
 */
function markAsChecked($dbhm, $attachmentIds) {
    if (empty($attachmentIds)) {
        return;
    }
    # IDs are from GROUP_CONCAT, so already comma-separated and safe integers.
    $dbhm->preExec(
        "UPDATE messages_attachments
         SET externalmods = JSON_SET(COALESCE(externalmods, '{}'), '$.peopleChecked', TRUE)
         WHERE id IN ($attachmentIds)"
    );
}

/**
 * Regenerate an AI image for an item using Pollinations.
 *
 * @param object $dbhr Database handle for reads.
 * @param object $dbhm Database handle for modifications.
 * @param string $itemName The item name to regenerate.
 * @param string $tusBase The TUS base URL.
 * @return string|false The new image URL on success, FALSE on failure.
 */
function regenerateImage($dbhr, $dbhm, $itemName, $tusBase) {
    # Build the prompt and fetch from Pollinations.
    $prompt = Pollinations::buildMessagePrompt($itemName);
    $data = Pollinations::fetchImage($itemName, $prompt, 640, 480, 120);

    if (!$data) {
        return FALSE;
    }

    # Upload to TUS and cache (this also applies the duotone filter).
    $hash = Pollinations::getImageHash($data);
    $uid = Pollinations::uploadAndCache($itemName, $data, $hash);

    if (!$uid) {
        return FALSE;
    }

    # Construct the viewable URL.
    $tusFile = str_replace('freegletusd-', '', $uid);
    $url = $tusBase . $tusFile . '/';

    return ['url' => $url, 'uid' => $uid];
}

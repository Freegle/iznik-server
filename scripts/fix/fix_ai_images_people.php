<?php
#
# Scan AI-generated images for ones that contain people using GPT-4o-mini vision.
# Stops on first detection for manual review.
#
# Usage:
#   php fix_ai_images_people.php [--auto] [--limit N] [--dry-run]
#
# Options:
#   --auto     Automatically delete images with people (no pause)
#   --limit N  Only process N images
#   --dry-run  Don't make any changes, just report
#
# When a person is detected, the script pauses and shows:
#   - The image URL (open in browser to verify)
#   - Options: [d]elete, [s]kip, [q]uit
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

foreach ($argv as $i => $arg) {
    if ($arg === '--dry-run') {
        $dryRun = TRUE;
    } elseif ($arg === '--auto') {
        $autoMode = TRUE;
    } elseif ($arg === '--limit' && isset($argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
    }
}

# Check for OpenAI API key.
if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    error_log("ERROR: OPENAI_API_KEY not defined in config. Add it to your iznik.conf file:");
    error_log("  define('OPENAI_API_KEY', 'sk-...');");
    exit(1);
}

# Progress tracking file.
$PROGRESS_FILE = '/tmp/ai_images_people_progress.json';

# Load progress (last processed ID).
$lastId = 0;
if (file_exists($PROGRESS_FILE)) {
    $progress = json_decode(file_get_contents($PROGRESS_FILE), TRUE);
    $lastId = $progress['last_id'] ?? 0;
    error_log("Resuming from ID $lastId");
}

# Get all AI images.
$sql = "SELECT id, name, externaluid FROM ai_images WHERE id > ? ORDER BY id ASC";
if ($limit) {
    $sql .= " LIMIT $limit";
}
$images = $dbhr->preQuery($sql, [$lastId]);

error_log("Found " . count($images) . " AI images to scan" . ($lastId > 0 ? " (after ID $lastId)" : ""));
error_log("Mode: " . ($autoMode ? "AUTO (will delete without prompting)" : "INTERACTIVE (will pause on detection)"));
error_log("");

$deleted = 0;
$skipped = 0;
$processed = 0;
$total = count($images);

foreach ($images as $image) {
    $id = $image['id'];
    $name = $image['name'];
    $uid = $image['externaluid'];

    if (!$uid || strpos($uid, 'freegletusd-') === FALSE) {
        continue;
    }

    # Construct the image URL.
    $tusFile = str_replace('freegletusd-', '', $uid);
    $imageUrl = TUS_UPLOADER . $tusFile . '/';

    # Build a viewable URL for the user.
    $viewUrl = $imageUrl;
    if (defined('IMAGE_DELIVERY') && IMAGE_DELIVERY) {
        $viewUrl = IMAGE_DELIVERY . "?url=" . urlencode($imageUrl) . "&w=512&output=jpg";
    }

    $processed++;
    echo "[$processed/$total] Checking: $name ... ";

    if ($dryRun) {
        echo "DRY RUN\n";
        echo "  URL: $viewUrl\n";
        continue;
    }

    # Call GPT-4o-mini vision API.
    $result = checkImageForPeople($imageUrl);

    if ($result === NULL) {
        echo "API ERROR (skipping)\n";
        continue;
    }

    if ($result === TRUE) {
        echo "PEOPLE DETECTED!\n";
        echo "\n";
        echo "  Image: $viewUrl\n";
        echo "  Item:  $name\n";
        echo "  ID:    $id\n";
        echo "\n";

        if ($autoMode) {
            # Auto-delete mode.
            deleteAiImage($dbhm, $id, $name, $uid);
            $deleted++;
            echo "  -> Auto-deleted\n\n";
        } else {
            # Interactive mode - prompt user.
            echo "  Options:\n";
            echo "    [d] Delete this AI image (will regenerate with new prompt)\n";
            echo "    [s] Skip (keep this image)\n";
            echo "    [q] Quit\n";
            echo "\n";
            echo "  Choice [d/s/q]: ";

            $handle = fopen("php://stdin", "r");
            $choice = strtolower(trim(fgets($handle)));

            if ($choice === 'd') {
                deleteAiImage($dbhm, $id, $name, $uid);
                $deleted++;
                echo "  -> Deleted. Messages using this will get new AI images.\n\n";
            } elseif ($choice === 'q') {
                echo "  -> Quitting. Progress saved.\n";
                # Save progress before quitting.
                file_put_contents($PROGRESS_FILE, json_encode(['last_id' => $id, 'timestamp' => date('c')]));
                break;
            } else {
                echo "  -> Skipped.\n\n";
                $skipped++;
            }
        }
    } else {
        echo "OK\n";
    }

    # Save progress after each image.
    file_put_contents($PROGRESS_FILE, json_encode(['last_id' => $id, 'timestamp' => date('c')]));

    # Small delay to avoid rate limiting.
    usleep(200000); # 200ms
}

# Output summary.
echo "\n";
echo "=== SCAN COMPLETE ===\n";
echo "Total scanned: $processed\n";
echo "Deleted: $deleted\n";
echo "Skipped: $skipped\n";

if ($processed == $total) {
    echo "\nAll images processed. Removing progress file.\n";
    @unlink($PROGRESS_FILE);
}

/**
 * Delete an AI image and mark messages using it for regeneration.
 *
 * @param object $dbhm Database handle for modifications.
 * @param int $id The ai_images row ID.
 * @param string $name The item name.
 * @param string $uid The externaluid.
 */
function deleteAiImage($dbhm, $id, $name, $uid) {
    # Delete from ai_images cache - this allows regeneration.
    $dbhm->preExec("DELETE FROM ai_images WHERE id = ?", [$id]);

    # Delete any message attachments using this AI image.
    # The messages_illustrations cron will regenerate them with the new prompt.
    $deleted = $dbhm->preExec(
        "DELETE FROM messages_attachments WHERE externaluid = ? AND JSON_EXTRACT(externalmods, '$.ai') = TRUE",
        [$uid]
    );

    $count = $dbhm->rowsAffected();
    if ($count > 0) {
        error_log("  Removed from $count message(s)");
    }
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

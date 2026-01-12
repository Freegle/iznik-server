<?php

namespace Freegle\Iznik;

/**
 * Helper class for fetching AI-generated images from Pollinations.ai.
 * Tracks image hashes to detect rate-limiting (same image returned for different prompts).
 * Uses both in-memory and file-based caching for persistence across processes.
 */
class Pollinations {
    # Track hashes we've seen in this process, keyed by hash => prompt.
    private static $seenHashes = [];

    # File-based cache location.
    const CACHE_FILE = '/tmp/pollinations_hashes.json';

    # Cache expiry in seconds (24 hours).
    const CACHE_EXPIRY = 86400;

    # File-based cache for failed items.
    const FAILED_CACHE_FILE = '/tmp/pollinations_failed.json';

    # Max failures before permanently skipping an item.
    const MAX_FAILURES = 3;

    # Failed items cache expiry (1 day - give items a chance to work later).
    const FAILED_CACHE_EXPIRY = 86400;

    /**
     * Load the file-based hash cache.
     * @return array Hash => [prompt, timestamp] mapping.
     */
    private static function loadFileCache() {
        if (!file_exists(self::CACHE_FILE)) {
            return [];
        }

        $data = @file_get_contents(self::CACHE_FILE);
        if (!$data) {
            return [];
        }

        $cache = @json_decode($data, TRUE);
        if (!is_array($cache)) {
            return [];
        }

        # Remove expired entries.
        $now = time();
        $cache = array_filter($cache, function($entry) use ($now) {
            return isset($entry['timestamp']) && ($now - $entry['timestamp']) < self::CACHE_EXPIRY;
        });

        return $cache;
    }

    /**
     * Save the file-based hash cache.
     * @param array $cache Hash => [prompt, timestamp] mapping.
     */
    private static function saveFileCache($cache) {
        # Use file locking to prevent race conditions.
        $fp = @fopen(self::CACHE_FILE, 'c');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($cache));
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
    }

    /**
     * Check if a hash exists in the file cache for a different prompt.
     * @param string $hash Image hash.
     * @param string $prompt Current prompt.
     * @return string|false The existing prompt if duplicate found, FALSE otherwise.
     */
    private static function checkFileCache($hash, $prompt) {
        $cache = self::loadFileCache();

        if (isset($cache[$hash]) && $cache[$hash]['prompt'] !== $prompt) {
            return $cache[$hash]['prompt'];
        }

        return FALSE;
    }

    /**
     * Add a hash to the file cache.
     * @param string $hash Image hash.
     * @param string $prompt The prompt that generated this image.
     */
    private static function addToFileCache($hash, $prompt) {
        $cache = self::loadFileCache();

        $cache[$hash] = [
            'prompt' => $prompt,
            'timestamp' => time()
        ];

        self::saveFileCache($cache);
    }

    /**
     * Load the failed items cache.
     * @return array itemName => ['count' => int, 'timestamp' => int]
     */
    private static function loadFailedCache() {
        if (!file_exists(self::FAILED_CACHE_FILE)) {
            return [];
        }

        $data = @file_get_contents(self::FAILED_CACHE_FILE);
        if (!$data) {
            return [];
        }

        $cache = @json_decode($data, TRUE);
        if (!is_array($cache)) {
            return [];
        }

        # Remove expired entries.
        $now = time();
        $cache = array_filter($cache, function($entry) use ($now) {
            return isset($entry['timestamp']) && ($now - $entry['timestamp']) < self::FAILED_CACHE_EXPIRY;
        });

        return $cache;
    }

    /**
     * Save the failed items cache.
     * @param array $cache itemName => ['count' => int, 'timestamp' => int]
     */
    private static function saveFailedCache($cache) {
        $fp = @fopen(self::FAILED_CACHE_FILE, 'c');
        if (!$fp) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($cache));
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
    }

    /**
     * Record a failure for an item. Returns TRUE if item should be skipped (too many failures).
     * @param string $itemName The item name that failed.
     * @return bool TRUE if item has exceeded max failures and should be skipped.
     */
    public static function recordFailure($itemName) {
        $cache = self::loadFailedCache();

        if (!isset($cache[$itemName])) {
            $cache[$itemName] = ['count' => 0, 'timestamp' => time()];
        }

        $cache[$itemName]['count']++;
        $cache[$itemName]['timestamp'] = time();

        self::saveFailedCache($cache);

        $shouldSkip = $cache[$itemName]['count'] >= self::MAX_FAILURES;
        if ($shouldSkip) {
            error_log("Item '$itemName' has failed " . $cache[$itemName]['count'] . " times, will skip for 1 day");
        }

        return $shouldSkip;
    }

    /**
     * Check if an item should be skipped due to previous failures.
     * @param string $itemName The item name to check.
     * @return bool TRUE if item should be skipped.
     */
    public static function shouldSkipItem($itemName) {
        $cache = self::loadFailedCache();

        if (!isset($cache[$itemName])) {
            return FALSE;
        }

        return $cache[$itemName]['count'] >= self::MAX_FAILURES;
    }

    /**
     * Clear the failed items cache (mainly for testing/maintenance).
     */
    public static function clearFailedCache() {
        if (file_exists(self::FAILED_CACHE_FILE)) {
            @unlink(self::FAILED_CACHE_FILE);
        }
    }

    /**
     * Fetch an image from Pollinations.ai for the given prompt.
     * Returns image data on success, or FALSE if rate-limited/failed.
     *
     * @param string $prompt The item name/description to generate an image for.
     * @param string $fullPrompt The full prompt string to send to Pollinations.
     * @param int $width Image width.
     * @param int $height Image height.
     * @param int $timeout Timeout in seconds.
     * @return string|false Image data on success, FALSE on failure or rate-limiting.
     */
    public static function fetchImage($prompt, $fullPrompt, $width = 640, $height = 480, $timeout = 120) {
        global $dbhr;

        $url = "https://image.pollinations.ai/prompt/" . urlencode($fullPrompt) .
               "?width={$width}&height={$height}&nologo=true&seed=1";

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout
            ]
        ]);

        $data = @file_get_contents($url, FALSE, $ctx);

        # Check for HTTP 429 rate limiting.
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d+\.?\d*\s+429/', $header)) {
                    error_log("Pollinations rate limited (HTTP 429) for: " . $prompt);
                    return FALSE;
                }
            }
        }

        if (!$data || strlen($data) == 0) {
            error_log("Pollinations failed to return data for: " . $prompt);
            return FALSE;
        }

        # Compute hash of received image.
        $hash = md5($data);

        # Check 1: In-memory cache (same process).
        if (isset(self::$seenHashes[$hash]) && self::$seenHashes[$hash] !== $prompt) {
            error_log("Pollinations rate limited (in-memory duplicate) for: " . $prompt .
                      " (same image as: " . self::$seenHashes[$hash] . ")");
            # Clean up the recently added entry that we now know is rate-limited.
            self::cleanupRateLimitedHash($hash);
            return FALSE;
        }

        # Check 2: File-based cache (across processes).
        $existingPrompt = self::checkFileCache($hash, $prompt);
        if ($existingPrompt !== FALSE) {
            error_log("Pollinations rate limited (file cache duplicate) for: " . $prompt .
                      " (same image as: " . $existingPrompt . ")");
            # Clean up recently added entries with this hash.
            self::cleanupRateLimitedHash($hash);
            return FALSE;
        }

        # Check 3: Database (historical data).
        if ($dbhr) {
            $existing = $dbhr->preQuery(
                "SELECT name FROM ai_images WHERE imagehash = ? AND name != ? LIMIT 1",
                [$hash, $prompt]
            );

            if (count($existing) > 0) {
                error_log("Pollinations rate limited (DB duplicate) for: " . $prompt .
                          " (same image as: " . $existing[0]['name'] . ")");
                # Also add to file cache to speed up future checks.
                self::addToFileCache($hash, $existing[0]['name']);
                return FALSE;
            }
        }

        # All checks passed - track this hash.
        self::$seenHashes[$hash] = $prompt;
        self::addToFileCache($hash, $prompt);

        return $data;
    }

    /**
     * Clean up recently added ai_images entries with a rate-limited hash.
     * Only removes entries added in the last hour to avoid removing legitimate old entries.
     * @param string $hash The rate-limited image hash.
     */
    private static function cleanupRateLimitedHash($hash) {
        global $dbhm;

        if (!$dbhm) {
            return;
        }

        # Only clean up entries added in the last hour - these are likely from this rate-limiting event.
        $deleted = $dbhm->preExec(
            "DELETE FROM ai_images WHERE imagehash = ? AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$hash]
        );

        if ($deleted) {
            $count = $dbhm->rowsAffected();
            if ($count > 0) {
                error_log("Cleaned up $count recent ai_images entries with rate-limited hash: $hash");
            }
        }

        # Also clean up messages_attachments that were recently added with externaluids
        # that are now known to be rate-limited (i.e., the ai_images entry was just deleted).
        # We look for recent AI attachments that no longer have a matching ai_images entry.
        $minId = $dbhm->preQuery("SELECT COALESCE(MAX(id), 0) - 10000 as minid FROM messages_attachments");
        $minIdVal = $minId[0]['minid'] ?? 0;

        $orphaned = $dbhm->preExec(
            "DELETE ma FROM messages_attachments ma
             LEFT JOIN ai_images ai ON ma.externaluid = ai.externaluid
             WHERE ma.externaluid LIKE 'freegletusd-%'
             AND JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
             AND ai.id IS NULL
             AND ma.id > ?",
            [$minIdVal]
        );

        if ($orphaned) {
            $count = $dbhm->rowsAffected();
            if ($count > 0) {
                error_log("Cleaned up $count orphaned message attachments");
            }
        }
    }

    /**
     * Build a prompt for a message illustration.
     * @param string $itemName The item name.
     * @return string The full prompt.
     */
    public static function buildMessagePrompt($itemName) {
        # Prompt injection defense.
        $cleanName = str_replace('CRITICAL:', '', $itemName);
        $cleanName = str_replace('Draw only', '', $cleanName);

        return "Draw a single friendly cartoon white line drawing on dark green background, moderate shading, " .
               "cute and quirky style, UK audience, centered, gender-neutral, " .
               "if showing people use abstract non-gendered figures. " .
               "CRITICAL: Do not include any text, words, letters, numbers or labels anywhere in the image. " .
               "Draw only a picture of: " . $cleanName;
    }

    /**
     * Build a prompt for a job illustration.
     * @param string $jobTitle The job title.
     * @return string The full prompt.
     */
    public static function buildJobPrompt($jobTitle) {
        # Prompt injection defense.
        $cleanName = str_replace('CRITICAL:', '', $jobTitle);
        $cleanName = str_replace('Draw only', '', $cleanName);

        return "simple cute cartoon " . $cleanName . " white line drawing on solid dark forest green background, " .
               "minimalist icon style, gender-neutral, if showing people use abstract non-gendered figures, " .
               "absolutely no text, no words, no letters, no numbers, no labels, " .
               "no writing, no captions, no signs, no speech bubbles, no border, filling the entire frame";
    }

    /**
     * Fetch a batch of images, returning successful results and tracking failures.
     * Individual item failures (no data returned) do NOT fail the batch - only actual
     * rate-limiting (HTTP 429 or duplicate images) fails the entire batch.
     *
     * @param array $items Array of ['name' => string, 'prompt' => string, 'width' => int, 'height' => int]
     * @param int $timeout Timeout per request in seconds.
     * @return array|false Array with 'results' and 'failed' keys on success, FALSE if rate-limited.
     *                     'results' => [['name' => string, 'data' => string, 'hash' => string], ...]
     *                     'failed' => [name => TRUE, ...] items that failed to fetch (not rate-limited)
     */
    public static function fetchBatch($items, $timeout = 120) {
        if (empty($items)) {
            return ['results' => [], 'failed' => []];
        }

        $results = [];
        $failed = [];
        $batchHashes = [];

        foreach ($items as $item) {
            $name = $item['name'];
            $prompt = $item['prompt'];
            $width = $item['width'] ?? 640;
            $height = $item['height'] ?? 480;

            $url = "https://image.pollinations.ai/prompt/" . urlencode($prompt) .
                   "?width={$width}&height={$height}&nologo=true&seed=1";

            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout
                ]
            ]);

            error_log("Batch fetching image for: $name");
            $data = @file_get_contents($url, FALSE, $ctx);

            # Check for HTTP 429 - this is real rate-limiting, fail entire batch.
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\d+\.?\d*\s+429/', $header)) {
                        error_log("Pollinations rate limited (HTTP 429) for batch item: $name");
                        return FALSE;
                    }
                }
            }

            # No data returned - this is an individual failure, NOT rate-limiting.
            # Skip this item but continue with others.
            if (!$data || strlen($data) == 0) {
                error_log("Pollinations failed to return data for batch item: $name (skipping)");
                $failed[$name] = TRUE;
                continue;
            }

            $hash = md5($data);

            # Check if this hash already appeared in this batch for a different item.
            # This IS rate-limiting - fail entire batch.
            if (isset($batchHashes[$hash]) && $batchHashes[$hash] !== $name) {
                error_log("Pollinations rate limited (batch duplicate) for: $name (same as: " . $batchHashes[$hash] . ")");
                # Clean up any recently added entries with this hash.
                self::cleanupRateLimitedHash($hash);
                return FALSE;
            }

            # Check file cache - this IS rate-limiting.
            $existingPrompt = self::checkFileCache($hash, $name);
            if ($existingPrompt !== FALSE) {
                error_log("Pollinations rate limited (file cache) for batch item: $name (same as: $existingPrompt)");
                self::cleanupRateLimitedHash($hash);
                return FALSE;
            }

            # Check database for historical duplicates - this IS rate-limiting.
            global $dbhr;
            if ($dbhr) {
                $existing = $dbhr->preQuery(
                    "SELECT name FROM ai_images WHERE imagehash = ? AND name != ? LIMIT 1",
                    [$hash, $name]
                );

                if (count($existing) > 0) {
                    error_log("Pollinations rate limited (DB) for batch item: $name (same as: " . $existing[0]['name'] . ")");
                    self::addToFileCache($hash, $existing[0]['name']);
                    self::cleanupRateLimitedHash($hash);
                    return FALSE;
                }
            }

            $batchHashes[$hash] = $name;
            $results[] = [
                'name' => $name,
                'data' => $data,
                'hash' => $hash,
                'msgid' => $item['msgid'] ?? NULL,
                'jobid' => $item['jobid'] ?? NULL
            ];

            # Small delay between requests.
            sleep(1);
        }

        # Add successful images to caches.
        foreach ($results as $result) {
            self::$seenHashes[$result['hash']] = $result['name'];
            self::addToFileCache($result['hash'], $result['name']);
        }

        return ['results' => $results, 'failed' => $failed];
    }

    /**
     * Cache an image in ai_images table.
     * @param string $name The item/job name.
     * @param string $uid The externaluid.
     * @param string $hash Image hash.
     */
    public static function cacheImage($name, $uid, $hash) {
        global $dbhm;

        if ($dbhm) {
            $dbhm->preExec(
                "INSERT INTO ai_images (name, externaluid, imagehash) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE externaluid = VALUES(externaluid), imagehash = VALUES(imagehash), created = NOW()",
                [$name, $uid, $hash]
            );
        }
    }

    /**
     * Upload image to TUS and cache in ai_images table.
     * @param string $name The item/job name.
     * @param string $data Image data.
     * @param string $hash Image hash.
     * @return string|false The externaluid on success, FALSE on failure.
     */
    public static function uploadAndCache($name, $data, $hash) {
        $t = new Tus();
        $tusUrl = $t->upload(NULL, 'image/jpeg', $data);

        if (!$tusUrl) {
            error_log("Failed to upload image to TUS for: $name");
            return FALSE;
        }

        $uid = 'freegletusd-' . basename($tusUrl);
        self::cacheImage($name, $uid, $hash);

        return $uid;
    }

    /**
     * Get the hash of image data.
     * @param string $data Image data.
     * @return string MD5 hash.
     */
    public static function getImageHash($data) {
        return md5($data);
    }

    /**
     * Clear the in-memory hash cache (mainly for testing).
     */
    public static function clearCache() {
        self::$seenHashes = [];
    }

    /**
     * Clear the file-based hash cache (mainly for testing/maintenance).
     */
    public static function clearFileCache() {
        if (file_exists(self::CACHE_FILE)) {
            @unlink(self::CACHE_FILE);
        }
    }
}

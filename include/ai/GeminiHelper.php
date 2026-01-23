<?php
namespace Freegle\Iznik;

/**
 * Helper class for Gemini API model selection.
 *
 * Provides dynamic model discovery to automatically use the latest available
 * Gemini Flash model, avoiding hardcoded version references that become
 * deprecated over time.
 */
class GeminiHelper {
    private static $cachedModel = NULL;
    private static $cacheTime = NULL;
    private const CACHE_TTL = 3600; // Cache model selection for 1 hour

    /**
     * Get the best available Gemini Flash model dynamically.
     *
     * Queries the Gemini API to find available flash models and selects
     * the latest version. Falls back to a safe default if the API call fails.
     *
     * @param string $preference 'lite' for flash-lite models, 'standard' for regular flash
     * @return string The model name to use
     */
    public static function getBestFlashModel($preference = 'lite') {
        // Return cached model if still valid
        if (self::$cachedModel !== NULL &&
            self::$cacheTime !== NULL &&
            (time() - self::$cacheTime) < self::CACHE_TTL) {
            return self::$cachedModel;
        }

        $apiKey = GOOGLE_GEMINI_API_KEY;
        if (empty($apiKey)) {
            return self::getFallbackModel($preference);
        }

        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey);

            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'GET',
                    'ignore_errors' => TRUE
                ]
            ]);

            $response = @file_get_contents($url, FALSE, $context);

            if ($response === FALSE) {
                error_log("GeminiHelper: Failed to fetch models list");
                return self::getFallbackModel($preference);
            }

            $data = json_decode($response, TRUE);

            if (!isset($data['models']) || !is_array($data['models'])) {
                error_log("GeminiHelper: Invalid response from models API");
                return self::getFallbackModel($preference);
            }

            $flashModels = [];

            foreach ($data['models'] as $model) {
                $modelName = $model['name'] ?? '';
                $supportedMethods = $model['supportedGenerationMethods'] ?? [];

                // Filter based on preference
                $isLite = stripos($modelName, 'lite') !== FALSE;
                $wantLite = ($preference === 'lite');

                // Look for flash models that support content generation
                if (
                    stripos($modelName, 'flash') !== FALSE &&
                    stripos($modelName, 'gemini') !== FALSE &&
                    stripos($modelName, 'exp') === FALSE && // Exclude experimental
                    stripos($modelName, 'vision') === FALSE && // Exclude vision-only
                    $isLite === $wantLite && // Match lite preference
                    in_array('generateContent', $supportedMethods)
                ) {
                    // Extract version number for sorting (e.g., 2.5, 2.0, 1.5)
                    $version = 0;
                    if (preg_match('/gemini-(\d+)\.(\d+)-flash/i', $modelName, $matches)) {
                        $version = floatval($matches[1] . '.' . $matches[2]);
                    }

                    $flashModels[] = [
                        'name' => $modelName,
                        'version' => $version
                    ];
                }
            }

            if (empty($flashModels)) {
                error_log("GeminiHelper: No matching flash models found, using fallback");
                return self::getFallbackModel($preference);
            }

            // Sort by version descending to get the latest
            usort($flashModels, function($a, $b) {
                return $b['version'] <=> $a['version'];
            });

            $selectedModel = $flashModels[0]['name'];

            // Remove 'models/' prefix if present
            if (strpos($selectedModel, 'models/') === 0) {
                $selectedModel = substr($selectedModel, 7);
            }

            error_log("GeminiHelper: Using model {$selectedModel}");

            // Cache the result
            self::$cachedModel = $selectedModel;
            self::$cacheTime = time();

            return $selectedModel;

        } catch (\Exception $e) {
            error_log("GeminiHelper: Error fetching models: " . $e->getMessage());
            return self::getFallbackModel($preference);
        }
    }

    /**
     * Get a fallback model when dynamic selection fails.
     *
     * @param string $preference 'lite' or 'standard'
     * @return string Fallback model name
     */
    private static function getFallbackModel($preference) {
        // Use 2.5 as fallback since 2.0 is being deprecated
        return $preference === 'lite' ? 'gemini-2.5-flash-lite' : 'gemini-2.5-flash';
    }

    /**
     * Clear the model cache. Useful for testing or forcing a refresh.
     */
    public static function clearCache() {
        self::$cachedModel = NULL;
        self::$cacheTime = NULL;
    }
}

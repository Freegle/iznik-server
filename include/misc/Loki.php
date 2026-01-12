<?php
namespace Freegle\Iznik;

/**
 * Loki client for sending logs to Grafana Loki via JSON files.
 *
 * Logs are written to JSON files which Alloy ships to Loki.
 * This approach is resilient (survives Loki downtime) and non-blocking.
 */
class Loki
{
    private static $instance = NULL;
    private $enabled = FALSE;
    private $jsonLogPath = '/var/log/freegle';

    private function __construct()
    {
        // Read from constants defined in /etc/iznik.conf.
        $this->enabled = defined('LOKI_ENABLED') && LOKI_ENABLED;
        $jsonPath = defined('LOKI_JSON_PATH') ? LOKI_JSON_PATH : NULL;

        if ($this->enabled && empty($jsonPath)) {
            error_log('Loki enabled but LOKI_JSON_PATH not set, disabling Loki');
            $this->enabled = FALSE;
        } elseif (!empty($jsonPath)) {
            $this->jsonLogPath = $jsonPath;
        }
    }

    public static function getInstance()
    {
        if (self::$instance === NULL) {
            self::$instance = new Loki();
        }
        return self::$instance;
    }

    /**
     * Check if Loki logging is enabled.
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * Headers to exclude from logging for security reasons.
     */
    private static $sensitiveHeaderPatterns = [
        '/^authorization$/i',
        '/^cookie$/i',
        '/^set-cookie$/i',
        '/token/i',
        '/key/i',
        '/secret/i',
        '/password/i',
        '/^x-api-key$/i',
    ];

    /**
     * Headers to include in logging (allowlist approach for request headers).
     */
    private static $allowedRequestHeaders = [
        'user-agent',
        'referer',
        'content-type',
        'accept',
        'accept-language',
        'accept-encoding',
        'x-forwarded-for',
        'x-forwarded-proto',
        'x-request-id',
        'x-real-ip',
        'origin',
        'host',
        'content-length',
        'x-trace-id',
        'x-session-id',
        'x-client-timestamp',
        // Logging context headers.
        'x-freegle-session',
        'x-freegle-page',
        'x-freegle-modal',
        'x-freegle-site',
    ];

    /**
     * Get trace and context headers from the current request.
     *
     * @return array Trace and context information from headers
     */
    public function getTraceHeaders()
    {
        $headers = [];

        // Header mappings: header name (lowercase) => output key.
        $headerMappings = [
            // Legacy trace headers.
            'x-trace-id' => 'trace_id',
            'x-session-id' => 'session_id',
            'x-client-timestamp' => 'client_timestamp',
            // New logging context headers.
            'x-freegle-session' => 'freegle_session',
            'x-freegle-page' => 'freegle_page',
            'x-freegle-modal' => 'freegle_modal',
            'x-freegle-site' => 'freegle_site',
        ];

        // Get headers from Apache headers or $_SERVER.
        if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            foreach ($requestHeaders as $name => $value) {
                $nameLower = strtolower($name);
                if (isset($headerMappings[$nameLower])) {
                    $headers[$headerMappings[$nameLower]] = $value;
                }
            }
        }

        // Fallback to $_SERVER for any missing headers.
        $serverMappings = [
            'HTTP_X_TRACE_ID' => 'trace_id',
            'HTTP_X_SESSION_ID' => 'session_id',
            'HTTP_X_CLIENT_TIMESTAMP' => 'client_timestamp',
            'HTTP_X_FREEGLE_SESSION' => 'freegle_session',
            'HTTP_X_FREEGLE_PAGE' => 'freegle_page',
            'HTTP_X_FREEGLE_MODAL' => 'freegle_modal',
            'HTTP_X_FREEGLE_SITE' => 'freegle_site',
        ];

        foreach ($serverMappings as $serverKey => $outputKey) {
            if (empty($headers[$outputKey]) && !empty($_SERVER[$serverKey])) {
                $headers[$outputKey] = $_SERVER[$serverKey];
            }
        }

        return $headers;
    }

    /**
     * Maximum string length for logged values.
     */
    const MAX_STRING_LENGTH = 32;

    /**
     * Truncate a string to MAX_STRING_LENGTH characters.
     *
     * @param string $value String to truncate
     * @return string Truncated string
     */
    private function truncateString($value)
    {
        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }
        return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
    }

    /**
     * Recursively truncate all string values in an array.
     *
     * @param mixed $data Data to truncate
     * @return mixed Truncated data
     */
    private function truncateData($data)
    {
        if (is_string($data)) {
            return $this->truncateString($data);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->truncateData($value);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Log API request to Loki.
     *
     * @param string $version API version (v1 or v2)
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param int $statusCode HTTP status code
     * @param float $duration Request duration in milliseconds
     * @param int|null $userId User ID if authenticated
     * @param array $extra Extra fields to log
     */
    public function logApiRequest($version, $method, $endpoint, $statusCode, $duration, $userId = NULL, $extra = [])
    {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'api',
            'api_version' => $version,
            'method' => $method,
            'status_code' => (string)$statusCode,
        ];

        // Add user_id as label for indexed queries.
        if ($userId) {
            $labels['user_id'] = (string)$userId;
        }

        // Include trace headers for distributed tracing correlation.
        $traceHeaders = $this->getTraceHeaders();

        $logLine = array_merge([
            'endpoint' => $endpoint,
            'duration_ms' => $duration,
            'user_id' => $userId,
            'timestamp' => date('c'),
        ], $traceHeaders, $extra);

        $this->log($labels, $logLine);
    }

    /**
     * Log API request with full request/response data to Loki.
     *
     * @param string $version API version (v1 or v2)
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param int $statusCode HTTP status code
     * @param float $duration Request duration in milliseconds
     * @param int|null $userId User ID if authenticated
     * @param array $extra Extra fields to log
     * @param array $queryParams Query parameters (will be truncated)
     * @param array|null $requestBody Request body (will be truncated)
     * @param array|null $responseBody Response body (will be truncated)
     */
    public function logApiRequestFull($version, $method, $endpoint, $statusCode, $duration, $userId = NULL, $extra = [], $queryParams = [], $requestBody = NULL, $responseBody = NULL)
    {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'api',
            'api_version' => $version,
            'method' => $method,
            'status_code' => (string)$statusCode,
        ];

        // Add user_id as label for indexed queries.
        if ($userId) {
            $labels['user_id'] = (string)$userId;
        }

        // Include trace headers for distributed tracing correlation.
        $traceHeaders = $this->getTraceHeaders();

        $logLine = array_merge([
            'endpoint' => $endpoint,
            'duration_ms' => $duration,
            'user_id' => $userId,
            'timestamp' => date('c'),
        ], $traceHeaders, $extra);

        // Add query parameters (truncated).
        if (!empty($queryParams)) {
            $logLine['query_params'] = $this->truncateData($queryParams);
        }

        // Add request body (truncated).
        if (!empty($requestBody)) {
            $logLine['request_body'] = $this->truncateData($requestBody);
        }

        // Add response body (truncated).
        if (!empty($responseBody)) {
            $logLine['response_body'] = $this->truncateData($responseBody);
        }

        $this->log($labels, $logLine);
    }

    /**
     * Log API headers to Loki (separate stream with longer retention for debugging).
     *
     * @param string $version API version (v1 or v2)
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $requestHeaders Request headers
     * @param array $responseHeaders Response headers
     * @param int|null $userId User ID if authenticated
     * @param string|null $requestId Unique request ID for correlation with main API log
     */
    public function logApiHeaders($version, $method, $endpoint, $requestHeaders, $responseHeaders, $userId = NULL, $requestId = NULL)
    {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'api_headers',
            'api_version' => $version,
            'method' => $method,
        ];

        $logLine = [
            'endpoint' => $endpoint,
            'user_id' => $userId,
            'request_id' => $requestId,
            'request_headers' => $this->filterHeaders($requestHeaders, TRUE),
            'response_headers' => $this->filterHeaders($responseHeaders, FALSE),
            'timestamp' => date('c'),
        ];

        $this->log($labels, $logLine);
    }

    /**
     * Filter headers to remove sensitive information.
     *
     * @param array $headers Headers to filter
     * @param bool $useAllowlist Whether to use allowlist (for request headers) or just blocklist
     * @return array Filtered headers
     */
    private function filterHeaders($headers, $useAllowlist = FALSE)
    {
        $filtered = [];

        foreach ($headers as $name => $value) {
            $nameLower = strtolower($name);

            // Check against sensitive patterns.
            $isSensitive = FALSE;
            foreach (self::$sensitiveHeaderPatterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $isSensitive = TRUE;
                    break;
                }
            }

            if ($isSensitive) {
                continue;
            }

            // For request headers, use allowlist.
            if ($useAllowlist) {
                if (in_array($nameLower, self::$allowedRequestHeaders)) {
                    $filtered[$name] = is_array($value) ? implode(', ', $value) : $value;
                }
            } else {
                // For response headers, include all non-sensitive.
                $filtered[$name] = is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return $filtered;
    }

    /**
     * Log email send to Loki.
     *
     * @param string $type Email type (digest, notification, etc.)
     * @param string $recipient Recipient email address
     * @param string $subject Email subject
     * @param int|null $userId User ID
     * @param int|null $groupId Group ID
     * @param array $extra Extra fields to log
     */
    public function logEmailSend($type, $recipient, $subject, $userId = NULL, $groupId = NULL, $extra = [])
    {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'email',
            'email_type' => $type,
        ];

        if ($groupId) {
            $labels['groupid'] = (string)$groupId;
        }

        $logLine = array_merge([
            'recipient' => $this->hashEmail($recipient),
            'subject' => $subject,
            'user_id' => $userId,
            'group_id' => $groupId,
            'timestamp' => date('c'),
        ], $extra);

        $this->log($labels, $logLine);
    }

    /**
     * Log from the logs table (for dual-write).
     *
     * @param array $params Log parameters matching logs table columns
     */
    public function logFromLogsTable($params)
    {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'app' => 'freegle',
            'source' => 'logs_table',
            'type' => $params['type'] ?? 'unknown',
            'subtype' => $params['subtype'] ?? 'unknown',
        ];

        if (!empty($params['groupid'])) {
            $labels['groupid'] = (string)$params['groupid'];
        }

        // Add user_id as label for indexed queries.
        if (!empty($params['user'])) {
            $labels['user_id'] = (string)$params['user'];
        }

        $logLine = [
            'user' => $params['user'] ?? NULL,
            'byuser' => $params['byuser'] ?? NULL,
            'msgid' => $params['msgid'] ?? NULL,
            'groupid' => $params['groupid'] ?? NULL,
            'text' => $params['text'] ?? NULL,
            'configid' => $params['configid'] ?? NULL,
            'stdmsgid' => $params['stdmsgid'] ?? NULL,
            'bulkopid' => $params['bulkopid'] ?? NULL,
            'timestamp' => $params['timestamp'] ?? date('c'),
        ];

        $this->log($labels, $logLine);
    }

    /**
     * Send a log entry to Loki.
     *
     * @param array $labels Loki labels
     * @param array|string $logLine Log content
     */
    public function log($labels, $logLine)
    {
        $this->logWithTimestamp($labels, $logLine, NULL);
    }

    /**
     * Send a log entry to Loki with a specific timestamp (for historical backfill).
     *
     * @param array $labels Loki labels
     * @param array|string $logLine Log content
     * @param string|int|float|null $timestamp Timestamp - can be:
     *   - NULL: use current time
     *   - string: ISO format (2025-12-15 10:30:00) or MySQL datetime
     *   - int/float: Unix timestamp (seconds or with microseconds)
     */
    public function logWithTimestamp($labels, $logLine, $timestamp = NULL)
    {
        if (!$this->enabled) {
            return;
        }

        // Convert log line to JSON string if needed.
        if (is_array($logLine)) {
            $logLine = json_encode($logLine);
        }

        // Determine timestamp.
        if ($timestamp === NULL) {
            $ts = date('c');
        } elseif (is_string($timestamp)) {
            $unixTs = strtotime($timestamp);
            $ts = ($unixTs === FALSE) ? date('c') : date('c', $unixTs);
        } else {
            $ts = date('c', (int)$timestamp);
        }

        // Write directly to JSON file.
        $this->writeLogEntry($labels, $logLine, $ts);
    }

    /**
     * Write a single log entry to JSON file.
     *
     * @param array $labels Loki labels
     * @param string $logLine JSON-encoded log content
     * @param string $timestamp ISO format timestamp
     */
    private function writeLogEntry($labels, $logLine, $timestamp)
    {
        // Determine source from labels for filename.
        $source = $labels['source'] ?? 'api';
        $logFile = $this->jsonLogPath . '/' . $source . '.log';

        // Ensure directory exists.
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, TRUE);
        }

        // Build entry structure matching what Alloy expects.
        $entry = [
            'timestamp' => $timestamp,
            'labels' => $labels,
            'message' => json_decode($logLine, TRUE) ?? $logLine,
        ];

        @file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Flush is now a no-op since we write directly to files.
     * Kept for API compatibility.
     */
    public function flush()
    {
        // No-op - we write directly to files now.
    }

    /**
     * Push log entries directly to Loki HTTP API.
     * Used for historical backfill where we want immediate ingestion with specific timestamps.
     *
     * @param string $lokiUrl Loki push endpoint (e.g. http://loki:3100/loki/api/v1/push)
     * @param array $entries Array of entries, each with 'labels', 'logLine', 'timestamp' keys
     * @return bool TRUE on success, FALSE on failure
     */
    public function pushDirectToLoki($lokiUrl, $entries)
    {
        if (empty($entries)) {
            return TRUE;
        }

        // Group entries by label set (Loki requires same labels in a stream).
        $streams = [];

        foreach ($entries as $entry) {
            $labels = $entry['labels'] ?? [];
            $logLine = $entry['logLine'];
            $timestamp = $entry['timestamp'];

            // Convert log line to JSON string if needed.
            if (is_array($logLine)) {
                $logLine = json_encode($logLine);
            }

            // Convert timestamp to nanoseconds.
            if (is_string($timestamp)) {
                $unixTs = strtotime($timestamp);
                if ($unixTs === FALSE) {
                    $unixTs = time();
                }
            } else {
                $unixTs = (int)$timestamp;
            }
            $nanoTs = (string)($unixTs * 1000000000);

            // Create label key for grouping.
            ksort($labels);
            $labelKey = json_encode($labels);

            if (!isset($streams[$labelKey])) {
                $streams[$labelKey] = [
                    'stream' => $labels,
                    'values' => [],
                ];
            }

            $streams[$labelKey]['values'][] = [$nanoTs, $logLine];
        }

        // Build Loki push payload.
        $payload = [
            'streams' => array_values($streams),
        ];

        // Send to Loki.
        $ch = curl_init($lokiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Loki push error: $error");
            return FALSE;
        }

        if ($httpCode !== 204 && $httpCode !== 200) {
            error_log("Loki push failed with HTTP $httpCode: $response");
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Hash email for privacy in logs.
     */
    private function hashEmail($email)
    {
        // Keep domain but hash local part for privacy.
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            return substr(md5($parts[0]), 0, 8) . '@' . $parts[1];
        }
        return substr(md5($email), 0, 16);
    }

    /**
     * Destructor - no-op now since we write directly.
     */
    public function __destruct()
    {
        // No-op.
    }

    /**
     * Query Loki for logs matching a LogQL query.
     *
     * @param string $query LogQL query string
     * @param int $limit Maximum number of results
     * @param string|null $start Start time (ISO format or relative like "24h")
     * @param string|null $end End time (ISO format, defaults to now)
     * @return array Array of log entries with parsed JSON
     */
    public function query($query, $limit = 1000, $start = '24h', $end = NULL)
    {
        $lokiUrl = defined('LOKI_URL') ? LOKI_URL : 'http://loki:3100';

        // Convert relative time to absolute.
        if ($end === NULL) {
            $endTime = time();
        } else {
            $endTime = strtotime($end);
        }

        if (preg_match('/^(\d+)([hdm])$/', $start, $matches)) {
            $amount = (int)$matches[1];
            $unit = $matches[2];
            switch ($unit) {
                case 'h':
                    $startTime = $endTime - ($amount * 3600);
                    break;
                case 'd':
                    $startTime = $endTime - ($amount * 86400);
                    break;
                case 'm':
                    $startTime = $endTime - ($amount * 60);
                    break;
                default:
                    $startTime = $endTime - 86400;
            }
        } else {
            $startTime = strtotime($start);
            if ($startTime === FALSE) {
                $startTime = $endTime - 86400;
            }
        }

        // Build query URL.
        $params = [
            'query' => $query,
            'limit' => $limit,
            'start' => $startTime . '000000000',
            'end' => $endTime . '000000000',
        ];

        $url = $lokiUrl . '/loki/api/v1/query_range?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Loki query error: $error");
            return [];
        }

        if ($httpCode !== 200) {
            error_log("Loki query failed with HTTP $httpCode: $response");
            return [];
        }

        $data = json_decode($response, TRUE);
        if (!$data || $data['status'] !== 'success') {
            return [];
        }

        // Parse results into a flat array of log entries.
        $results = [];
        foreach ($data['data']['result'] ?? [] as $stream) {
            $labels = $stream['stream'] ?? [];
            foreach ($stream['values'] ?? [] as $value) {
                $timestamp = $value[0];
                $logLine = $value[1];
                $parsed = json_decode($logLine, TRUE) ?? ['raw' => $logLine];
                $results[] = array_merge($labels, $parsed, [
                    '_timestamp' => $timestamp,
                ]);
            }
        }

        return $results;
    }

    /**
     * Find distinct IPs used by a user from API logs.
     *
     * @param int $userId User ID
     * @param string $lookback How far back to look (default 30d)
     * @return array Array of IP addresses
     */
    public function findUserIPs($userId, $lookback = '30d')
    {
        $query = '{app="freegle",source="api",user_id="' . (int)$userId . '"}';
        $results = $this->query($query, 5000, $lookback);

        $ips = [];
        foreach ($results as $entry) {
            $ip = $entry['ip'] ?? $entry['client_ip'] ?? NULL;
            if ($ip && !in_array($ip, $ips)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    /**
     * Find users who have used a specific IP address.
     *
     * @param string $ip IP address
     * @param string $lookback How far back to look (default 30d)
     * @return array Array of user IDs
     */
    public function findUsersForIP($ip, $lookback = '30d')
    {
        // Query all API logs and filter by IP in the log line.
        $query = '{app="freegle",source="api"} |= "' . addslashes($ip) . '"';
        $results = $this->query($query, 5000, $lookback);

        $userIds = [];
        foreach ($results as $entry) {
            $entryIp = $entry['ip'] ?? $entry['client_ip'] ?? NULL;
            if ($entryIp === $ip) {
                $userId = $entry['user_id'] ?? NULL;
                if ($userId && !in_array($userId, $userIds)) {
                    $userIds[] = (int)$userId;
                }
            }
        }

        return $userIds;
    }

    /**
     * Check if a user has successfully accessed from a specific IP.
     *
     * @param int $userId User ID
     * @param string $ip IP address
     * @param string $lookback How far back to look (default 30d)
     * @return bool TRUE if user has accessed from this IP with success, or if Loki is not enabled
     */
    public function hasUserAccessedFromIP($userId, $ip, $lookback = '30d')
    {
        // Skip verification if Loki is not enabled (e.g., in tests).
        if (!$this->enabled) {
            return TRUE;
        }

        $query = '{app="freegle",source="api",user_id="' . (int)$userId . '",status_code="200"} |= "' . addslashes($ip) . '"';
        $results = $this->query($query, 100, $lookback);

        foreach ($results as $entry) {
            $entryIp = $entry['ip'] ?? $entry['client_ip'] ?? NULL;
            if ($entryIp === $ip) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Find users sharing IPs with a given user within a time window.
     *
     * @param int $userId User ID to check
     * @param int $windowMinutes Time window in minutes (default 5)
     * @param string $lookback How far back to look (default 7d)
     * @return array Array of user IDs who shared IPs within the time window
     */
    public function findUsersWithSharedIPActivity($userId, $windowMinutes = 5, $lookback = '7d')
    {
        // Get all IPs used by this user with timestamps.
        $query = '{app="freegle",source="api",user_id="' . (int)$userId . '"}';
        $userLogs = $this->query($query, 5000, $lookback);

        $userActivity = [];
        foreach ($userLogs as $entry) {
            $ip = $entry['ip'] ?? $entry['client_ip'] ?? NULL;
            $timestamp = $entry['timestamp'] ?? NULL;
            if ($ip && $timestamp) {
                $userActivity[] = [
                    'ip' => $ip,
                    'time' => strtotime($timestamp),
                ];
            }
        }

        if (empty($userActivity)) {
            return [];
        }

        // Get unique IPs.
        $ips = array_unique(array_column($userActivity, 'ip'));

        $sharedUsers = [];
        foreach ($ips as $ip) {
            $otherUsers = $this->findUsersForIP($ip, $lookback);
            foreach ($otherUsers as $otherUserId) {
                if ($otherUserId != $userId && !in_array($otherUserId, $sharedUsers)) {
                    // Check if activity was within the time window.
                    $otherQuery = '{app="freegle",source="api",user_id="' . (int)$otherUserId . '"} |= "' . addslashes($ip) . '"';
                    $otherLogs = $this->query($otherQuery, 1000, $lookback);

                    foreach ($otherLogs as $otherEntry) {
                        $otherIp = $otherEntry['ip'] ?? $otherEntry['client_ip'] ?? NULL;
                        $otherTime = strtotime($otherEntry['timestamp'] ?? '');

                        if ($otherIp === $ip && $otherTime) {
                            foreach ($userActivity as $ua) {
                                if ($ua['ip'] === $ip) {
                                    $diff = abs($ua['time'] - $otherTime);
                                    if ($diff <= $windowMinutes * 60) {
                                        $sharedUsers[] = $otherUserId;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_unique($sharedUsers);
    }

    /**
     * Check if an IP has been used by a moderator.
     *
     * @param string $ip IP address
     * @param string $lookback How far back to look (default 30d)
     * @return bool TRUE if a mod has used this IP
     */
    public function hasModUsedIP($ip, $lookback = '30d')
    {
        global $dbhr;

        $userIds = $this->findUsersForIP($ip, $lookback);

        if (empty($userIds)) {
            return FALSE;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $mods = $dbhr->preQuery(
            "SELECT id FROM users WHERE id IN ($placeholders) AND systemrole != ?",
            array_merge($userIds, [User::SYSTEMROLE_USER])
        );

        return count($mods) > 0;
    }
}

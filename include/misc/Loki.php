<?php
namespace Freegle\Iznik;

/**
 * Loki client for sending logs to Grafana Loki.
 *
 * This is a lightweight client that sends logs asynchronously using fire-and-forget
 * HTTP requests to avoid impacting application performance.
 */
class Loki
{
    private static $instance = NULL;
    private $enabled = FALSE;
    private $url = NULL;
    private $batch = [];
    private $batchSize = 10;
    private $lastFlush = 0;
    private $flushInterval = 5; // seconds
    private $jsonFileEnabled = FALSE;
    private $jsonLogPath = '/var/log/freegle';

    private function __construct()
    {
        $this->enabled = getenv('LOKI_ENABLED') === 'true' || getenv('LOKI_ENABLED') === '1';
        $this->url = getenv('LOKI_URL');

        // Enable JSON file logging for Alloy to ship.
        $this->jsonFileEnabled = getenv('LOKI_JSON_FILE') === 'true' || getenv('LOKI_JSON_FILE') === '1';
        $jsonPath = getenv('LOKI_JSON_PATH');
        if (!empty($jsonPath)) {
            $this->jsonLogPath = $jsonPath;
        }

        // Enabled if either Loki URL is set or JSON file logging is on.
        if (!$this->enabled && !$this->jsonFileEnabled) {
            $this->enabled = FALSE;
        } elseif ($this->jsonFileEnabled) {
            // JSON file logging means we're enabled even without URL.
            $this->enabled = TRUE;
        } elseif ($this->enabled && empty($this->url)) {
            $this->enabled = FALSE;
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
    ];

    /**
     * Get trace headers from the current request.
     *
     * @return array Trace information from headers
     */
    public function getTraceHeaders()
    {
        $headers = [];

        // Get trace headers from Apache headers or $_SERVER.
        if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            foreach ($requestHeaders as $name => $value) {
                $nameLower = strtolower($name);
                if ($nameLower === 'x-trace-id') {
                    $headers['trace_id'] = $value;
                } elseif ($nameLower === 'x-session-id') {
                    $headers['session_id'] = $value;
                } elseif ($nameLower === 'x-client-timestamp') {
                    $headers['client_timestamp'] = $value;
                }
            }
        }

        // Fallback to $_SERVER.
        if (empty($headers['trace_id']) && !empty($_SERVER['HTTP_X_TRACE_ID'])) {
            $headers['trace_id'] = $_SERVER['HTTP_X_TRACE_ID'];
        }
        if (empty($headers['session_id']) && !empty($_SERVER['HTTP_X_SESSION_ID'])) {
            $headers['session_id'] = $_SERVER['HTTP_X_SESSION_ID'];
        }
        if (empty($headers['client_timestamp']) && !empty($_SERVER['HTTP_X_CLIENT_TIMESTAMP'])) {
            $headers['client_timestamp'] = $_SERVER['HTTP_X_CLIENT_TIMESTAMP'];
        }

        return $headers;
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
     * Log API headers to Loki (separate stream with longer retention for debugging).
     *
     * @param string $version API version (v1 or v2)
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $requestHeaders Request headers
     * @param array $responseHeaders Response headers
     * @param int|null $userId User ID if authenticated
     */
    public function logApiHeaders($version, $method, $endpoint, $requestHeaders, $responseHeaders, $userId = NULL)
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

            // Check against sensitive patterns
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

            // For request headers, use allowlist
            if ($useAllowlist) {
                if (in_array($nameLower, self::$allowedRequestHeaders)) {
                    $filtered[$name] = is_array($value) ? implode(', ', $value) : $value;
                }
            } else {
                // For response headers, include all non-sensitive
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

        // Convert log line to JSON string if needed
        if (is_array($logLine)) {
            $logLine = json_encode($logLine);
        }

        // Add to batch
        $labelKey = json_encode($labels);
        if (!isset($this->batch[$labelKey])) {
            $this->batch[$labelKey] = [
                'stream' => $labels,
                'values' => [],
            ];
        }

        // Loki expects timestamp in nanoseconds as string (no scientific notation).
        if ($timestamp === NULL) {
            // Use current time
            $tsNano = sprintf('%.0f', microtime(TRUE) * 1000000000);
        } elseif (is_string($timestamp)) {
            // Parse date string to Unix timestamp, then convert to nanoseconds
            $unixTs = strtotime($timestamp);
            if ($unixTs === FALSE) {
                // Invalid date string, use current time
                $tsNano = sprintf('%.0f', microtime(TRUE) * 1000000000);
            } else {
                $tsNano = sprintf('%.0f', $unixTs * 1000000000);
            }
        } else {
            // Numeric timestamp (seconds, possibly with microseconds)
            $tsNano = sprintf('%.0f', $timestamp * 1000000000);
        }

        $this->batch[$labelKey]['values'][] = [$tsNano, $logLine];

        // Flush if batch is full or interval has passed
        $now = time();
        if (count($this->batch) >= $this->batchSize || ($now - $this->lastFlush) >= $this->flushInterval) {
            $this->flush();
        }
    }

    /**
     * Flush batch to Loki and/or JSON file.
     */
    public function flush()
    {
        if (empty($this->batch) || !$this->enabled) {
            return;
        }

        $payload = ['streams' => array_values($this->batch)];
        $this->batch = [];
        $this->lastFlush = time();

        // Send to Loki API if URL configured.
        if (!empty($this->url)) {
            $this->sendAsync($payload);
        }

        // Write to JSON file if enabled (for Alloy to ship).
        if ($this->jsonFileEnabled) {
            $this->writeToJsonFile($payload);
        }
    }

    /**
     * Write logs to JSON file for Alloy to ship.
     *
     * @param array $payload The log payload
     */
    private function writeToJsonFile($payload)
    {
        // Determine source from first stream for filename.
        $source = 'api';
        if (!empty($payload['streams'][0]['stream']['source'])) {
            $source = $payload['streams'][0]['stream']['source'];
        }

        $logFile = $this->jsonLogPath . '/' . $source . '.log';

        // Ensure directory exists.
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, TRUE);
        }

        // Write each log entry as a JSON line.
        foreach ($payload['streams'] as $stream) {
            foreach ($stream['values'] as $value) {
                $entry = [
                    'timestamp' => date('c', (int)($value[0] / 1000000000)),
                    'labels' => $stream['stream'],
                    'message' => $value[1],
                ];

                // Try to decode message if it's JSON.
                $decoded = json_decode($value[1], TRUE);
                if ($decoded !== NULL) {
                    $entry['message'] = $decoded;
                }

                @file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
            }
        }
    }

    /**
     * Send payload to Loki with minimal blocking.
     * Waits for response status to ensure data was received.
     *
     * @param array $payload The Loki push payload
     */
    private function sendAsync($payload)
    {
        $url = $this->url . '/loki/api/v1/push';
        $json = json_encode($payload);

        $parts = parse_url($url);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $path = $parts['path'];

        $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if (!$socket) {
            return;
        }

        // Short timeout to minimize latency impact.
        stream_set_timeout($socket, 1);

        $request = "POST $path HTTP/1.1\r\n";
        $request .= "Host: $host\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($json) . "\r\n";
        $request .= "Connection: close\r\n\r\n";
        $request .= $json;

        @fwrite($socket, $request);

        // Wait for response status line to ensure data was received.
        // This is minimal blocking but ensures the request completed.
        @fgets($socket, 128);

        @fclose($socket);
    }

    /**
     * Hash email for privacy in logs.
     */
    private function hashEmail($email)
    {
        // Keep domain but hash local part for privacy
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            return substr(md5($parts[0]), 0, 8) . '@' . $parts[1];
        }
        return substr(md5($email), 0, 16);
    }

    /**
     * Destructor - flush any remaining logs.
     */
    public function __destruct()
    {
        $this->flush();
    }
}

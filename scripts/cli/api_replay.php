<?php
/**
 * Replay API requests from Loki logs.
 *
 * Usage:
 *   php api_replay.php -r <request_id>    # Replay by request_id
 *   php api_replay.php -u <user_id>       # Replay last request from user
 *   php api_replay.php -q <logql_query>   # Replay first result from LogQL query
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('r:u:q:');

$requestId = $opts['r'] ?? NULL;
$userId = $opts['u'] ?? NULL;
$query = $opts['q'] ?? NULL;

$loki = Loki::getInstance();

$logs = [];

if ($requestId) {
    error_log("Find log by request_id: $requestId");
    $query = '{app="freegle",source="api"} |= "' . addslashes($requestId) . '"';
    $logs = $loki->query($query, 1, '7d');
} elseif ($userId) {
    error_log("Find last log for user: $userId");
    $query = '{app="freegle",source="api",user_id="' . (int)$userId . '"}';
    $logs = $loki->query($query, 1, '7d');
} elseif ($query) {
    error_log("Find log by query: $query");
    $logs = $loki->query($query, 1, '7d');
} else {
    error_log("Usage: php api_replay.php -r <request_id> | -u <user_id> | -q <logql_query>");
    exit(1);
}

if (empty($logs)) {
    error_log("No matching logs found.");
    exit(1);
}

session_start();
foreach ($logs as $log) {
    error_log("Found log: " . json_encode($log));

    $userId = $log['user_id'] ?? NULL;
    if ($userId) {
        error_log("Impersonate user $userId");
        $_SESSION['id'] = $userId;
    }

    # Reconstruct request from query_params and request_body if available.
    $request = [];
    if (isset($log['query_params'])) {
        $request = array_merge($request, $log['query_params']);
    }
    if (isset($log['request_body'])) {
        $request = array_merge($request, $log['request_body']);
    }

    if (!empty($request)) {
        $_REQUEST = $request;
        error_log("Replaying request: " . json_encode($_REQUEST));
        API::call();
    } else {
        error_log("No request data available to replay (query_params/request_body not logged).");
    }
}


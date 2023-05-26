<?php

// Start configure
$log_file = '/tmp/csp-violations.log';

$current_domain = preg_replace('/www\./i', '', $_SERVER['SERVER_NAME']);

http_response_code(204); // HTTP 204 No Content

$json_data = file_get_contents('php://input');

// We pretty print the JSON before adding it to the log file
if ($json_data = json_decode($json_data)) {
    $json_data = json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    file_put_contents($log_file, $json_data, FILE_APPEND | LOCK_EX);
}
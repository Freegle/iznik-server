<?php
/**
 * Export logs from database to JSON file for Loki migration.
 *
 * Usage:
 *   php logs_dump.php -d 7 -o /tmp/logs.json       # Last 7 days
 *   php logs_dump.php -s "2025-12-01" -e "2025-12-15"      # Date range
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

// Parse command line arguments
$opts = getopt('s:e:d:o:b:vh', ['help', 'dry-run']);

if (isset($opts['h']) || isset($opts['help'])) {
    echo <<<HELP
Export logs from database to JSON file for Loki migration.

Usage: php logs_dump.php [options]

Options:
  -s <start>     Start date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
  -e <end>       End date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
  -d <days>      Days ago (alternative to start/end)
  -o <file>      Output file path (default: logs_export_YYYYMMDD_HHMMSS.json)
  -b <batch>     Batch size for DB queries (default: 10000)
  -v             Verbose output
  --dry-run      Show what would be exported without writing file
  -h, --help     Show this help message

Examples:
  php logs_dump.php -d 7 -o /tmp/logs_7days.json
  php logs_dump.php -s "2025-12-01" -e "2025-12-15"
  php logs_dump.php -d 1 --dry-run -v

HELP;
    exit(0);
}

// Configuration
$verbose = isset($opts['v']);
$dryRun = isset($opts['dry-run']);
$batchSize = isset($opts['b']) ? intval($opts['b']) : 10000;

// Determine date range
$startDate = NULL;
$endDate = NULL;

if (isset($opts['d'])) {
    $days = intval($opts['d']);
    $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $endDate = date('Y-m-d H:i:s');
} elseif (isset($opts['s'])) {
    $startDate = $opts['s'];
    $endDate = $opts['e'] ?? date('Y-m-d H:i:s');
} else {
    error_log("Error: Must specify either -d (days ago) or -s (start date)");
    exit(1);
}

// Output file
$outputFile = $opts['o'] ?? 'logs_export_' . date('Ymd_His') . '.json';

if ($verbose) {
    error_log("Configuration:");
    error_log("  Start date: $startDate");
    error_log("  End date: $endDate");
    error_log("  Output file: $outputFile");
    error_log("  Batch size: $batchSize");
    error_log("  Dry run: " . ($dryRun ? 'yes' : 'no'));
    error_log("");
}

$totalExported = 0;

// Open output file
$fp = NULL;
if (!$dryRun) {
    $fp = fopen($outputFile, 'w');
    if (!$fp) {
        error_log("Error: Could not open output file: $outputFile");
        exit(1);
    }
}

/**
 * Export logs table
 */
function exportLogsTable($dbhr, $startDate, $endDate, $batchSize, $verbose, $dryRun, $fp) {
    $offset = 0;
    $exported = 0;
    $startTime = microtime(TRUE);

    if ($verbose) {
        error_log("Exporting logs table...");
    }

    while (TRUE) {
        $sql = "SELECT id, timestamp, byuser, type, subtype, groupid, user, msgid, configid, stdmsgid, bulkopid, text
                FROM logs
                WHERE timestamp >= ? AND timestamp <= ?
                ORDER BY id ASC
                LIMIT " . intval($batchSize) . " OFFSET " . intval($offset);

        $rows = $dbhr->preQuery($sql, [$startDate, $endDate]);

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $record = [
                'source' => 'logs',
                'id' => $row['id'],
                'timestamp' => $row['timestamp'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'user' => $row['user'],
                'byuser' => $row['byuser'],
                'groupid' => $row['groupid'],
                'msgid' => $row['msgid'],
                'configid' => $row['configid'],
                'stdmsgid' => $row['stdmsgid'],
                'bulkopid' => $row['bulkopid'],
                'text' => $row['text'],
            ];

            if (!$dryRun && $fp) {
                fwrite($fp, json_encode($record) . "\n");
            }

            $exported++;
        }

        $offset += $batchSize;

        if ($verbose && $exported % 10000 === 0) {
            $elapsed = microtime(TRUE) - $startTime;
            $rate = round($exported / $elapsed);
            error_log("  Exported $exported logs records ($rate records/sec)");
        }
    }

    if ($verbose) {
        $elapsed = microtime(TRUE) - $startTime;
        error_log("  Completed: $exported logs records in " . round($elapsed, 1) . " seconds");
    }

    return $exported;
}

// Execute export
$overallStart = microtime(TRUE);
$totalExported = exportLogsTable($dbhr, $startDate, $endDate, $batchSize, $verbose, $dryRun, $fp);

// Close file
if ($fp) {
    fclose($fp);
}

// Summary
$overallElapsed = microtime(TRUE) - $overallStart;
error_log("");
error_log("Export Summary:");
error_log("  Total records: $totalExported");
error_log("  Time: " . round($overallElapsed, 1) . " seconds");

if (!$dryRun) {
    $fileSize = filesize($outputFile);
    $fileSizeMB = round($fileSize / (1024 * 1024), 1);
    error_log("  Output file: $outputFile ($fileSizeMB MB)");
} else {
    error_log("  (Dry run - no file written)");
}

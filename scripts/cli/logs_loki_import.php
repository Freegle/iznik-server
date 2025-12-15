<?php
/**
 * Import logs from JSON file into Loki.
 *
 * Tracks imported records in a SQLite database to avoid duplicates.
 * Safe to run multiple times - already-imported records are skipped.
 *
 * Usage:
 *   php logs_loki_import.php -i /tmp/logs.json -v          # Import with verbose output
 *   php logs_loki_import.php -i /tmp/logs.json --dry-run   # Parse file without sending
 *   php logs_loki_import.php -i /tmp/logs.json --force     # Re-import all (ignore tracking)
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/misc/Loki.php');

// Parse command line arguments
$opts = getopt('i:b:t:vh', ['help', 'dry-run', 'force', 'reset-tracking']);

if (isset($opts['h']) || isset($opts['help'])) {
    echo <<<HELP
Import logs from JSON file into Loki.

Tracks imported records in a SQLite database to avoid duplicates.
Safe to run multiple times - already-imported records are skipped.

Usage: php logs_loki_import.php [options]

Options:
  -i <file>      Input JSON file (required)
  -b <batch>     Batch size for Loki sends (default: 100)
  -t <file>      Tracking database file (default: /tmp/loki_import_tracking.sqlite)
  -v             Verbose output
  --dry-run      Parse file and show stats without sending to Loki
  --force        Re-import all records, ignoring tracking database
  --reset-tracking  Clear the tracking database before import
  -h, --help     Show this help message

Examples:
  php logs_loki_import.php -i /tmp/logs_7days.json -v
  php logs_loki_import.php -i /tmp/logs.json --dry-run
  php logs_loki_import.php -i /tmp/logs.json --force    # Re-import everything

Tracking Database:
  The script maintains a SQLite database to track which records have been imported.
  This allows safe re-running without creating duplicates in Loki.
  Location: /tmp/loki_import_tracking.sqlite (or use -t to specify)

Note: Requires LOKI_ENABLED=true and LOKI_URL set in environment.

HELP;
    exit(0);
}

// Configuration
$inputFile = $opts['i'] ?? NULL;
$verbose = isset($opts['v']);
$dryRun = isset($opts['dry-run']);
$force = isset($opts['force']);
$resetTracking = isset($opts['reset-tracking']);
$batchSize = isset($opts['b']) ? intval($opts['b']) : 100;
$trackingDb = $opts['t'] ?? '/tmp/loki_import_tracking.sqlite';

if (!$inputFile) {
    error_log("Error: Input file required (-i option)");
    exit(1);
}

if (!file_exists($inputFile)) {
    error_log("Error: Input file not found: $inputFile");
    exit(1);
}

// Check Loki is enabled
$loki = Loki::getInstance();

if (!$dryRun && !$loki->isEnabled()) {
    error_log("Error: Loki is not enabled. Set LOKI_ENABLED=true and LOKI_URL in environment.");
    exit(1);
}

// Initialize tracking database
$trackingPdo = NULL;
if (!$force && !$dryRun) {
    try {
        $trackingPdo = new \PDO("sqlite:$trackingDb");
        $trackingPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Create tracking table if it doesn't exist
        $trackingPdo->exec("
            CREATE TABLE IF NOT EXISTS imported_records (
                source TEXT NOT NULL,
                original_id INTEGER NOT NULL,
                imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (source, original_id)
            )
        ");

        // Create index for faster lookups
        $trackingPdo->exec("
            CREATE INDEX IF NOT EXISTS idx_source_id ON imported_records(source, original_id)
        ");

        // Reset tracking if requested
        if ($resetTracking) {
            $trackingPdo->exec("DELETE FROM imported_records");
            if ($verbose) {
                error_log("Tracking database cleared.");
            }
        }
    } catch (\PDOException $e) {
        error_log("Warning: Could not initialize tracking database: " . $e->getMessage());
        error_log("Continuing without duplicate tracking.");
        $trackingPdo = NULL;
    }
}

if ($verbose) {
    error_log("Configuration:");
    error_log("  Input file: $inputFile");
    error_log("  Batch size: $batchSize");
    error_log("  Tracking DB: " . ($force ? '(disabled - force mode)' : $trackingDb));
    error_log("  Dry run: " . ($dryRun ? 'yes' : 'no'));
    error_log("  Force mode: " . ($force ? 'yes' : 'no'));
    error_log("  Loki enabled: " . ($loki->isEnabled() ? 'yes' : 'no'));
    error_log("");
}

/**
 * Check if a record has already been imported.
 */
function isAlreadyImported($trackingPdo, $source, $originalId) {
    if (!$trackingPdo) {
        return FALSE;
    }

    $stmt = $trackingPdo->prepare("SELECT 1 FROM imported_records WHERE source = ? AND original_id = ?");
    $stmt->execute([$source, $originalId]);
    return $stmt->fetch() !== FALSE;
}

/**
 * Mark a record as imported.
 */
function markAsImported($trackingPdo, $source, $originalId) {
    if (!$trackingPdo) {
        return;
    }

    $stmt = $trackingPdo->prepare("INSERT OR IGNORE INTO imported_records (source, original_id) VALUES (?, ?)");
    $stmt->execute([$source, $originalId]);
}

// Statistics
$stats = [
    'logs' => 0,
    'logs_api' => 0,
    'errors' => 0,
    'skipped' => 0,
    'duplicates' => 0,
];

$startTime = microtime(TRUE);
$lineNumber = 0;
$imported = 0;

// Open input file
$fp = fopen($inputFile, 'r');
if (!$fp) {
    error_log("Error: Could not open input file: $inputFile");
    exit(1);
}

if ($verbose) {
    error_log("Importing logs...");
}

while (($line = fgets($fp)) !== FALSE) {
    $lineNumber++;
    $line = trim($line);

    if (empty($line)) {
        continue;
    }

    $record = json_decode($line, TRUE);

    if ($record === NULL) {
        $stats['errors']++;
        if ($verbose) {
            error_log("  Error parsing line $lineNumber: " . json_last_error_msg());
        }
        continue;
    }

    $source = $record['source'] ?? NULL;

    if ($source === 'logs') {
        $originalId = $record['id'] ?? NULL;

        // Check for duplicates (unless force mode)
        if (!$force && $originalId && isAlreadyImported($trackingPdo, 'logs', $originalId)) {
            $stats['duplicates']++;
            continue;
        }

        // Import from logs table
        $labels = [
            'app' => 'freegle',
            'source' => 'logs_table',
            'type' => $record['type'] ?? 'unknown',
            'subtype' => $record['subtype'] ?? 'unknown',
        ];

        if (!empty($record['groupid'])) {
            $labels['groupid'] = (string)$record['groupid'];
        }

        $logLine = [
            'user' => $record['user'],
            'byuser' => $record['byuser'],
            'msgid' => $record['msgid'],
            'groupid' => $record['groupid'],
            'text' => $record['text'],
            'configid' => $record['configid'],
            'stdmsgid' => $record['stdmsgid'],
            'bulkopid' => $record['bulkopid'],
            'original_id' => $originalId,
            'timestamp' => $record['timestamp'],
        ];

        if (!$dryRun) {
            $loki->logWithTimestamp($labels, $logLine, $record['timestamp']);
            markAsImported($trackingPdo, 'logs', $originalId);
        }

        $stats['logs']++;
        $imported++;

    } elseif ($source === 'logs_api') {
        $originalId = $record['id'] ?? NULL;

        // Check for duplicates (unless force mode)
        if (!$force && $originalId && isAlreadyImported($trackingPdo, 'logs_api', $originalId)) {
            $stats['duplicates']++;
            continue;
        }

        // Import from logs_api table
        $response = $record['response'] ?? [];

        $labels = [
            'app' => 'freegle',
            'source' => 'api',
            'api_version' => 'v1',
            'method' => $response['method'] ?? 'unknown',
            'status_code' => (string)($response['ret'] ?? 0),
        ];

        $logLine = [
            'userid' => $record['userid'],
            'ip' => $record['ip'],
            'session' => $record['session'],
            'call' => $record['call'] ?? ($response['call'] ?? NULL),
            'ret' => $record['ret'] ?? ($response['ret'] ?? NULL),
            'status' => $record['status'] ?? ($response['status'] ?? NULL),
            'cpucost' => $record['cpucost'] ?? ($response['cpucost'] ?? NULL),
            'original_id' => $originalId,
            'timestamp' => $record['timestamp'],
        ];

        if (!$dryRun) {
            $loki->logWithTimestamp($labels, $logLine, $record['timestamp']);
            markAsImported($trackingPdo, 'logs_api', $originalId);
        }

        $stats['logs_api']++;
        $imported++;

    } else {
        $stats['skipped']++;
        if ($verbose && $stats['skipped'] <= 10) {
            error_log("  Skipping unknown source: $source (line $lineNumber)");
        }
    }

    // Progress output
    if ($verbose && $imported > 0 && $imported % 10000 === 0) {
        $elapsed = microtime(TRUE) - $startTime;
        $rate = $imported / $elapsed;
        error_log("  Imported $imported records ({$rate:.0f} records/sec)");
    }

    // Force flush periodically
    if (!$dryRun && $imported % $batchSize === 0) {
        $loki->flush();
    }
}

fclose($fp);

// Final flush
if (!$dryRun) {
    $loki->flush();
}

// Summary
$elapsed = microtime(TRUE) - $startTime;
error_log("");
error_log("Import Summary:");
error_log("  Total imported: $imported");
error_log("  - logs table records: {$stats['logs']}");
error_log("  - logs_api table records: {$stats['logs_api']}");
error_log("  Duplicates skipped: {$stats['duplicates']}");
error_log("  Unknown source skipped: {$stats['skipped']}");
error_log("  Errors: {$stats['errors']}");
error_log("  Time: {$elapsed:.1f} seconds");
error_log("  Rate: " . ($elapsed > 0 ? round($imported / $elapsed) : 0) . " records/sec");

if ($dryRun) {
    error_log("  (Dry run - no data sent to Loki)");
}

if ($force) {
    error_log("  (Force mode - duplicate tracking disabled)");
}

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/scripts/cli/spiralling_investigation_simulate_temporal.php');

global $dbhr, $dbhm;

/**
 * Two-stage Bayesian optimization for isochrone parameters
 *
 * Stage 1: Optimize spatial parameters (initialMinutes, maxMinutes, etc.)
 * Stage 2: Optimize temporal expansion curve parameters
 */

class IsochroneParameterOptimizer {
    private $dbhr;
    private $dbhm;
    private $sqliteDb;
    private $activeHoursPattern;
    private $optimizationRunId;

    // Configuration
    private $startDate;
    private $endDate;
    private $groupIds;
    private $sampleSize;
    private $dbPath;

    public function __construct($dbhr, $dbhm, $options = []) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->startDate = $options['startDate'] ?? date('Y-m-d', strtotime('-60 days'));
        $this->endDate = $options['endDate'] ?? date('Y-m-d', strtotime('-7 days'));
        $this->groupIds = $options['groupIds'] ?? [];
        $this->sampleSize = $options['sampleSize'] ?? 50; // Messages per optimization run

        // Initialize SQLite database for tracking optimization
        $this->dbPath = $options['dbPath'] ?? '/tmp/isochrone_optimization.db';
        $this->initializeSQLite();

        // Pre-compute active hours pattern from historical data
        $this->activeHoursPattern = $this->computeActiveHours();
    }

    /**
     * Initialize SQLite database for optimization tracking
     */
    private function initializeSQLite() {
        echo "Initializing SQLite database at {$this->dbPath}\n";

        $this->sqliteDb = new \SQLite3($this->dbPath);
        $this->sqliteDb->busyTimeout(5000);

        // Create tables
        $this->sqliteDb->exec("
            CREATE TABLE IF NOT EXISTS optimization_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stage TEXT NOT NULL,
                search_space TEXT NOT NULL,
                fixed_params TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                sample_size INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'running',
                best_parameters TEXT NULL,
                best_score REAL NULL,
                created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed TEXT NULL
            )
        ");

        $this->sqliteDb->exec("
            CREATE TABLE IF NOT EXISTS optimization_iterations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                iteration INTEGER NOT NULL,
                parameters TEXT NOT NULL,
                score REAL NOT NULL,
                created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (run_id) REFERENCES optimization_runs(id)
            )
        ");

        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_run_iteration ON optimization_iterations(run_id, iteration)");
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_run_score ON optimization_iterations(run_id, score DESC)");

        echo "SQLite database initialized\n";
    }

    /**
     * Compute which hours of the day are "active" (≥3% of total replies)
     */
    private function computeActiveHours() {
        echo "Computing active hours pattern from historical reply data...\n";

        $sql = "SELECT
                  HOUR(cm.date) as hour_of_day,
                  COUNT(*) as reply_count,
                  COUNT(*) * 100.0 / SUM(COUNT(*)) OVER () as pct_of_total
                FROM chat_messages cm
                WHERE cm.type = 'Interested'
                  AND cm.date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
                GROUP BY HOUR(cm.date)
                ORDER BY hour_of_day";

        $results = $this->dbhr->preQuery($sql);

        $pattern = [];
        foreach (range(0, 23) as $hour) {
            $pattern[$hour] = FALSE; // Default inactive
        }

        foreach ($results as $row) {
            $hour = intval($row['hour_of_day']);
            $pct = floatval($row['pct_of_total']);

            if ($pct >= 3.0) {
                $pattern[$hour] = TRUE;
                echo "  Hour $hour: {$pct}% - ACTIVE\n";
            } else {
                echo "  Hour $hour: {$pct}% - inactive\n";
            }
        }

        return $pattern;
    }

    /**
     * Calculate adjusted hours (only counting active hours)
     */
    private function calculateAdjustedHours($startTimestamp, $endTimestamp) {
        if ($endTimestamp <= $startTimestamp) {
            return 0;
        }

        $adjustedHours = 0;
        $current = $startTimestamp;

        while ($current < $endTimestamp) {
            $hourOfDay = intval(date('G', $current));
            if ($this->activeHoursPattern[$hourOfDay]) {
                $adjustedHours++;
            }
            $current += 3600; // Move forward 1 hour
        }

        return $adjustedHours;
    }

    /**
     * Run Stage 1: Optimize spatial parameters
     */
    public function optimizeStage1($numIterations = 50) {
        echo "\n=== STAGE 1: SPATIAL PARAMETER OPTIMIZATION ===\n";
        echo "Iterations: $numIterations\n";
        echo "Sample size per iteration: {$this->sampleSize} messages\n\n";

        // Fixed temporal parameters for Stage 1
        $fixedTemporal = [
            'breakpoint1' => 12,    // hours
            'breakpoint2' => 24,    // hours
            'interval1' => 4,       // hours (240 min)
            'interval2' => 6,       // hours (360 min)
            'interval3' => 8        // hours (480 min)
        ];

        // Search space for spatial parameters
        $searchSpace = [
            'initialMinutes' => ['min' => 3, 'max' => 15, 'type' => 'int'],
            'maxMinutes' => ['min' => 45, 'max' => 120, 'type' => 'int'],
            'increment' => ['min' => 3, 'max' => 10, 'type' => 'int'],
            'minUsers' => ['min' => 50, 'max' => 200, 'type' => 'int'],
            'activeSince' => ['min' => 60, 'max' => 180, 'type' => 'int'],
            'numReplies' => ['min' => 5, 'max' => 9, 'type' => 'int'],
            'transport' => ['values' => ['car'], 'type' => 'categorical']
        ];

        $bestParams = NULL;
        $bestScore = 0;
        $allResults = [];

        // Create optimization run record
        $this->createOptimizationRun('stage1', $searchSpace, $fixedTemporal);

        for ($i = 1; $i <= $numIterations; $i++) {
            echo "\n--- Iteration $i/$numIterations ---\n";

            // Sample parameters
            if ($i <= 15) {
                // First 15: Latin Hypercube Sampling for exploration
                $params = $this->latinHypercubeSample($searchSpace, $i, 15);
            } else {
                // Remaining: Bayesian-inspired sampling (focus near best results)
                $params = $this->bayesianSample($searchSpace, $allResults, $bestParams);
            }

            // Add fixed temporal parameters
            $params = array_merge($params, $fixedTemporal);

            echo "Testing parameters:\n";
            echo json_encode($params, JSON_PRETTY_PRINT) . "\n";

            // Run simulation
            $score = $this->evaluateParameters($params);

            echo "Score: " . round($score * 100, 2) . "%\n";

            // Store result
            $result = [
                'iteration' => $i,
                'params' => $params,
                'score' => $score
            ];
            $allResults[] = $result;
            $this->storeOptimizationIteration($result);

            // Track best
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestParams = $params;
                echo "*** NEW BEST SCORE: " . round($bestScore * 100, 2) . "% ***\n";
            }
        }

        echo "\n=== STAGE 1 COMPLETE ===\n";
        echo "Best score: " . round($bestScore * 100, 2) . "%\n";
        echo "Best parameters:\n";
        echo json_encode($bestParams, JSON_PRETTY_PRINT) . "\n";

        $this->updateOptimizationRun($bestParams, $bestScore);

        return $bestParams;
    }

    /**
     * Run Stage 2: Optimize temporal curve parameters
     */
    public function optimizeStage2($spatialParams, $numIterations = 50) {
        echo "\n=== STAGE 2: TEMPORAL CURVE OPTIMIZATION ===\n";
        echo "Iterations: $numIterations\n";
        echo "Sample size per iteration: {$this->sampleSize} messages\n\n";

        // Search space for temporal parameters
        $searchSpace = [
            'breakpoint1' => ['min' => 6, 'max' => 18, 'type' => 'int'],
            'breakpoint2' => ['min' => 18, 'max' => 48, 'type' => 'int'],
            'interval1' => ['min' => 2, 'max' => 6, 'type' => 'int'],
            'interval2' => ['min' => 4, 'max' => 10, 'type' => 'int'],
            'interval3' => ['min' => 6, 'max' => 12, 'type' => 'int']
        ];

        $bestParams = NULL;
        $bestScore = 0;
        $allResults = [];

        // Create optimization run record
        $this->createOptimizationRun('stage2', $searchSpace, $spatialParams);

        for ($i = 1; $i <= $numIterations; $i++) {
            echo "\n--- Iteration $i/$numIterations ---\n";

            // Sample temporal parameters
            if ($i <= 15) {
                $temporalParams = $this->latinHypercubeSample($searchSpace, $i, 15);
            } else {
                $temporalParams = $this->bayesianSample($searchSpace, $allResults, $bestParams);
            }

            // Check constraints
            if (!$this->checkTemporalConstraints($temporalParams)) {
                echo "Skipping invalid temporal parameters (constraint violation)\n";
                $i--; // Don't count this iteration
                continue;
            }

            // Combine with fixed spatial parameters
            $params = array_merge($spatialParams, $temporalParams);

            echo "Testing temporal parameters:\n";
            echo json_encode($temporalParams, JSON_PRETTY_PRINT) . "\n";

            // Run simulation
            $score = $this->evaluateParameters($params);

            echo "Score: " . round($score * 100, 2) . "%\n";

            // Store result
            $result = [
                'iteration' => $i,
                'params' => $temporalParams,
                'score' => $score
            ];
            $allResults[] = $result;
            $this->storeOptimizationIteration($result);

            // Track best
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestParams = $temporalParams;
                echo "*** NEW BEST SCORE: " . round($bestScore * 100, 2) . "% ***\n";
            }
        }

        echo "\n=== STAGE 2 COMPLETE ===\n";
        echo "Best score: " . round($bestScore * 100, 2) . "%\n";
        echo "Best temporal parameters:\n";
        echo json_encode($bestParams, JSON_PRETTY_PRINT) . "\n";

        // Combine best spatial and temporal
        $finalParams = array_merge($spatialParams, $bestParams);

        $this->updateOptimizationRun($finalParams, $bestScore);

        return $finalParams;
    }

    /**
     * Check temporal parameter constraints
     */
    private function checkTemporalConstraints($params) {
        // Breakpoints must be ordered
        if ($params['breakpoint1'] >= $params['breakpoint2']) {
            return FALSE;
        }

        // Intervals should generally increase (allow equality)
        if ($params['interval1'] > $params['interval2'] ||
            $params['interval2'] > $params['interval3']) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Latin Hypercube Sampling for initial exploration
     */
    private function latinHypercubeSample($searchSpace, $sampleNum, $totalSamples) {
        $params = [];

        foreach ($searchSpace as $paramName => $config) {
            if ($config['type'] === 'categorical') {
                $params[$paramName] = $config['values'][0]; // Fixed for now
            } else {
                // Divide range into bins and sample from the appropriate bin
                $min = $config['min'];
                $max = $config['max'];
                $binSize = ($max - $min) / $totalSamples;

                $binStart = $min + ($sampleNum - 1) * $binSize;
                $binEnd = $binStart + $binSize;

                // Random value within the bin
                $value = $binStart + mt_rand() / mt_getrandmax() * $binSize;

                if ($config['type'] === 'int') {
                    $params[$paramName] = intval(round($value));
                } else {
                    $params[$paramName] = $value;
                }

                // Clamp to bounds
                $params[$paramName] = max($min, min($max, $params[$paramName]));
            }
        }

        return $params;
    }

    /**
     * Bayesian-inspired sampling (sample near best results with some exploration)
     */
    private function bayesianSample($searchSpace, $allResults, $bestParams) {
        if (empty($allResults)) {
            // Fallback to random if no results yet
            return $this->randomSample($searchSpace);
        }

        // Sort results by score
        usort($allResults, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Take top 20% as "good" results
        $topN = max(1, intval(count($allResults) * 0.2));
        $topResults = array_slice($allResults, 0, $topN);

        // Randomly select one of the top results to explore around
        $baseResult = $topResults[array_rand($topResults)];
        $baseParams = $baseResult['params'];

        // Sample with Gaussian perturbation
        $params = [];
        foreach ($searchSpace as $paramName => $config) {
            if ($config['type'] === 'categorical') {
                $params[$paramName] = $config['values'][0];
            } else {
                $min = $config['min'];
                $max = $config['max'];
                $range = $max - $min;

                // Get base value (filter out temporal params if in spatial stage and vice versa)
                $baseValue = isset($baseParams[$paramName]) ? $baseParams[$paramName] : ($min + $max) / 2;

                // Add Gaussian noise (stddev = 20% of range)
                $noise = $this->gaussianRandom(0, $range * 0.2);
                $value = $baseValue + $noise;

                // Clamp to bounds
                $value = max($min, min($max, $value));

                if ($config['type'] === 'int') {
                    $params[$paramName] = intval(round($value));
                } else {
                    $params[$paramName] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Random sampling from search space
     */
    private function randomSample($searchSpace) {
        $params = [];

        foreach ($searchSpace as $paramName => $config) {
            if ($config['type'] === 'categorical') {
                $params[$paramName] = $config['values'][array_rand($config['values'])];
            } else {
                $min = $config['min'];
                $max = $config['max'];
                $value = $min + mt_rand() / mt_getrandmax() * ($max - $min);

                if ($config['type'] === 'int') {
                    $params[$paramName] = intval(round($value));
                } else {
                    $params[$paramName] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Generate Gaussian random number using Box-Muller transform
     */
    private function gaussianRandom($mean = 0, $stddev = 1) {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();

        $z0 = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return $mean + $z0 * $stddev;
    }

    /**
     * Evaluate a parameter set by running simulation
     */
    private function evaluateParameters($params) {
        echo "Running simulation...\n";

        // Create modified simulator with temporal curve support
        $simulator = new MessageIsochroneSimulatorWithTemporal(
            $this->dbhr,
            $this->dbhm,
            [
                'startDate' => $this->startDate,
                'endDate' => $this->endDate,
                'groupIds' => $this->groupIds,
                'limit' => $this->sampleSize,
                'params' => $params
            ]
        );

        // Run simulation
        $results = $simulator->runAndReturnResults();

        if (empty($results)) {
            echo "Warning: No results from simulation\n";
            return 0;
        }

        // Calculate success rate
        $successes = 0;
        $failures = [];

        foreach ($results as $msgResult) {
            // Calculate adjusted hours to final expansion
            $arrivalTime = strtotime($msgResult['arrival']);
            $finalExpansionTime = strtotime($msgResult['final_expansion_timestamp']);
            $adjustedHours = $this->calculateAdjustedHours($arrivalTime, $finalExpansionTime);

            // Check success criteria
            $reachedTaker = $msgResult['taker_reached'] ?? FALSE;
            $gotReplies = $msgResult['replies_at_final'] >= $params['numReplies'];
            $withinTime = $adjustedHours <= 112;

            if (($reachedTaker || $gotReplies) && $withinTime) {
                $successes++;
            } else {
                $failures[] = [
                    'msgid' => $msgResult['msgid'],
                    'adjusted_hours' => $adjustedHours,
                    'reached_taker' => $reachedTaker,
                    'replies' => $msgResult['replies_at_final']
                ];
            }
        }

        $successRate = $successes / count($results);

        echo "  Results: $successes/" . count($results) . " messages succeeded\n";

        if (count($failures) > 0 && count($failures) <= 5) {
            echo "  Sample failures:\n";
            foreach (array_slice($failures, 0, 3) as $f) {
                echo "    - Msg {$f['msgid']}: {$f['adjusted_hours']}h, replies={$f['replies']}, taker=" . ($f['reached_taker'] ? 'yes' : 'no') . "\n";
            }
        }

        return $successRate;
    }

    /**
     * SQLite operations for tracking optimization
     */
    private function createOptimizationRun($stage, $searchSpace, $fixedParams) {
        $stmt = $this->sqliteDb->prepare("
            INSERT INTO optimization_runs
            (stage, search_space, fixed_params, start_date, end_date, sample_size, status, created)
            VALUES (:stage, :search_space, :fixed_params, :start_date, :end_date, :sample_size, 'running', datetime('now'))
        ");

        $stmt->bindValue(':stage', $stage, SQLITE3_TEXT);
        $stmt->bindValue(':search_space', json_encode($searchSpace), SQLITE3_TEXT);
        $stmt->bindValue(':fixed_params', json_encode($fixedParams), SQLITE3_TEXT);
        $stmt->bindValue(':start_date', $this->startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end_date', $this->endDate, SQLITE3_TEXT);
        $stmt->bindValue(':sample_size', $this->sampleSize, SQLITE3_INTEGER);

        $stmt->execute();
        $this->optimizationRunId = $this->sqliteDb->lastInsertRowID();

        echo "Created optimization run {$this->optimizationRunId}\n";
    }

    private function storeOptimizationIteration($result) {
        $stmt = $this->sqliteDb->prepare("
            INSERT INTO optimization_iterations
            (run_id, iteration, parameters, score, created)
            VALUES (:run_id, :iteration, :parameters, :score, datetime('now'))
        ");

        $stmt->bindValue(':run_id', $this->optimizationRunId, SQLITE3_INTEGER);
        $stmt->bindValue(':iteration', $result['iteration'], SQLITE3_INTEGER);
        $stmt->bindValue(':parameters', json_encode($result['params']), SQLITE3_TEXT);
        $stmt->bindValue(':score', $result['score'], SQLITE3_FLOAT);

        $stmt->execute();
    }

    private function updateOptimizationRun($bestParams, $bestScore) {
        $stmt = $this->sqliteDb->prepare("
            UPDATE optimization_runs
            SET status = 'completed',
                best_parameters = :best_parameters,
                best_score = :best_score,
                completed = datetime('now')
            WHERE id = :id
        ");

        $stmt->bindValue(':best_parameters', json_encode($bestParams), SQLITE3_TEXT);
        $stmt->bindValue(':best_score', $bestScore, SQLITE3_FLOAT);
        $stmt->bindValue(':id', $this->optimizationRunId, SQLITE3_INTEGER);

        $stmt->execute();
    }

    /**
     * Get optimization history for analysis
     */
    public function getOptimizationHistory($runId = NULL) {
        $runId = $runId ?? $this->optimizationRunId;

        $results = [];
        $query = $this->sqliteDb->query("
            SELECT iteration, parameters, score
            FROM optimization_iterations
            WHERE run_id = $runId
            ORDER BY iteration ASC
        ");

        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $results[] = [
                'iteration' => $row['iteration'],
                'params' => json_decode($row['parameters'], TRUE),
                'score' => $row['score']
            ];
        }

        return $results;
    }

    /**
     * Export results to CSV for analysis
     */
    public function exportResultsToCSV($outputPath = NULL) {
        $outputPath = $outputPath ?? '/tmp/optimization_results_' . date('Y-m-d_His') . '.csv';

        $results = $this->getOptimizationHistory();

        if (empty($results)) {
            echo "No results to export\n";
            return;
        }

        $fp = fopen($outputPath, 'w');

        // Header row - get all parameter names from first result
        $firstParams = $results[0]['params'];
        $headers = array_merge(['iteration', 'score'], array_keys($firstParams));
        fputcsv($fp, $headers);

        // Data rows
        foreach ($results as $result) {
            $row = [$result['iteration'], $result['score']];
            foreach ($firstParams as $key => $value) {
                $row[] = $result['params'][$key] ?? '';
            }
            fputcsv($fp, $row);
        }

        fclose($fp);
        echo "Results exported to $outputPath\n";
    }
}

// Parse command line arguments
$options = getopt('', [
    'stage:',
    'stage1-iterations:',
    'stage2-iterations:',
    'start:',
    'end:',
    'groups:',
    'sample-size:',
    'db-path:',
    'export-csv:',
    'help'
]);

if (isset($options['help']) || (!isset($options['stage']) && !isset($options['export-csv']))) {
    echo "Usage: php optimize_isochrone_parameters.php --stage=<1|2|both> [options]\n\n";
    echo "Two-stage Bayesian optimization for isochrone parameters.\n";
    echo "Results stored in local SQLite database (default: /tmp/isochrone_optimization.db)\n\n";
    echo "Required:\n";
    echo "  --stage=<1|2|both>     Which stage to run\n";
    echo "                         1: Optimize spatial parameters (initialMinutes, maxMinutes, etc.)\n";
    echo "                         2: Optimize temporal curve (expansion timing)\n";
    echo "                         both: Run both stages sequentially\n";
    echo "  --export-csv=PATH      Export optimization results to CSV file\n\n";
    echo "Options:\n";
    echo "  --stage1-iterations=N  Number of iterations for stage 1 (default: 50)\n";
    echo "  --stage2-iterations=N  Number of iterations for stage 2 (default: 50)\n";
    echo "  --start=DATE           Start date for historical data (default: 60 days ago)\n";
    echo "  --end=DATE             End date for historical data (default: 7 days ago)\n";
    echo "  --groups=IDS           Comma-separated group IDs to include (default: all)\n";
    echo "  --sample-size=N        Messages per iteration (default: 50)\n";
    echo "  --db-path=PATH         SQLite database path (default: /tmp/isochrone_optimization.db)\n";
    echo "  --help                 Show this help\n\n";
    echo "Examples:\n";
    echo "  # Run both stages with defaults\n";
    echo "  php optimize_isochrone_parameters.php --stage=both\n\n";
    echo "  # Run stage 1 only with more iterations\n";
    echo "  php optimize_isochrone_parameters.php --stage=1 --stage1-iterations=100\n\n";
    echo "  # Run stage 2 with specific date range\n";
    echo "  php optimize_isochrone_parameters.php --stage=2 --start=2025-01-01 --end=2025-01-31\n\n";
    echo "  # Export results to CSV\n";
    echo "  php optimize_isochrone_parameters.php --export-csv=/tmp/results.csv --db-path=/tmp/isochrone_optimization.db\n\n";
    exit(0);
}

// Handle CSV export mode
if (isset($options['export-csv'])) {
    $dbPath = $options['db-path'] ?? '/tmp/isochrone_optimization.db';

    if (!file_exists($dbPath)) {
        echo "Error: Database not found at $dbPath\n";
        exit(1);
    }

    $optimizer = new IsochroneParameterOptimizer($dbhr, $dbhm, ['dbPath' => $dbPath]);
    $optimizer->exportResultsToCSV($options['export-csv']);
    exit(0);
}

$stage = $options['stage'];
$stage1Iterations = intval($options['stage1-iterations'] ?? 50);
$stage2Iterations = intval($options['stage2-iterations'] ?? 50);

$optimizerOptions = [];
if (isset($options['start'])) {
    $optimizerOptions['startDate'] = $options['start'];
}
if (isset($options['end'])) {
    $optimizerOptions['endDate'] = $options['end'];
}
if (isset($options['groups'])) {
    $groupIds = array_map('trim', explode(',', $options['groups']));
    $optimizerOptions['groupIds'] = array_map('intval', $groupIds);
}
if (isset($options['sample-size'])) {
    $optimizerOptions['sampleSize'] = intval($options['sample-size']);
}
if (isset($options['db-path'])) {
    $optimizerOptions['dbPath'] = $options['db-path'];
}

$optimizer = new IsochroneParameterOptimizer($dbhr, $dbhm, $optimizerOptions);

if ($stage === 'both') {
    // Run both stages
    $bestSpatial = $optimizer->optimizeStage1($stage1Iterations);
    $bestFinal = $optimizer->optimizeStage2($bestSpatial, $stage2Iterations);

    echo "\n=== OPTIMIZATION COMPLETE ===\n";
    echo "Final best parameters:\n";
    echo json_encode($bestFinal, JSON_PRETTY_PRINT) . "\n";

} elseif ($stage === '1') {
    $bestSpatial = $optimizer->optimizeStage1($stage1Iterations);

} elseif ($stage === '2') {
    // Need to load best spatial params from stage 1
    echo "Error: Stage 2 requires best parameters from Stage 1\n";
    echo "Please run Stage 1 first, then manually specify the spatial parameters\n";
    echo "Or use --stage=both to run both stages\n";
    exit(1);

} else {
    echo "Error: Invalid stage '$stage'. Use 1, 2, or both.\n";
    exit(1);
}

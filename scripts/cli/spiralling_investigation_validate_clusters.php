<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/scripts/cli/spiralling_investigation_optimize_parameters.php');

global $dbhr, $dbhm;

/**
 * Validate whether isochrone parameters should vary by cluster
 *
 * This script runs parameter optimization on representative groups from each cluster
 * and statistically validates whether different clusters need different parameters.
 */

class ClusterParameterValidator {
    private $dbhr;
    private $dbhm;
    private $representativesPath;
    private $startDate;
    private $endDate;
    private $sampleSize;
    private $iterations;
    private $outputPath;

    public function __construct($dbhr, $dbhm, $options = []) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->representativesPath = $options['representativesPath'] ?? '/tmp/group_clusters_representatives.csv';
        $this->startDate = $options['startDate'] ?? date('Y-m-d', strtotime('-60 days'));
        $this->endDate = $options['endDate'] ?? date('Y-m-d', strtotime('-7 days'));
        $this->sampleSize = $options['sampleSize'] ?? 50;
        $this->iterations = $options['iterations'] ?? 30;
        $this->outputPath = $options['outputPath'] ?? '/tmp/cluster_parameter_validation.json';
    }

    public function validate() {
        echo "=== Cluster Parameter Validation ===\n\n";

        // Load representative groups
        $representatives = $this->loadRepresentatives();
        echo "Loaded " . count($representatives) . " representative groups\n";

        // Group by cluster
        $byCluster = $this->groupByCluster($representatives);
        echo "Found " . count($byCluster) . " clusters\n\n";

        // Run optimization for each cluster
        $clusterResults = [];

        foreach ($byCluster as $clusterId => $clusterReps) {
            echo "\n=== CLUSTER $clusterId ===\n";
            echo "Representative groups: " . implode(', ', array_column($clusterReps, 'group_name')) . "\n";
            echo "Group IDs: " . implode(', ', array_column($clusterReps, 'group_id')) . "\n\n";

            $groupIds = array_column($clusterReps, 'group_id');

            // Run optimization for this cluster
            $result = $this->optimizeForCluster($clusterId, $groupIds);

            $clusterResults[$clusterId] = $result;

            echo "\nCluster $clusterId best parameters:\n";
            echo json_encode($result['best_params'], JSON_PRETTY_PRINT) . "\n";
            echo "Score: " . round($result['best_score'] * 100, 2) . "%\n";
        }

        // Analyze parameter differences between clusters
        echo "\n=== Cross-Cluster Analysis ===\n\n";
        $analysis = $this->analyzeParameterDifferences($clusterResults);

        // Export results
        $this->exportResults($clusterResults, $analysis);

        // Show recommendation
        $this->showRecommendation($analysis);

        return [
            'cluster_results' => $clusterResults,
            'analysis' => $analysis
        ];
    }

    private function loadRepresentatives() {
        if (!file_exists($this->representativesPath)) {
            throw new \Exception("Representatives file not found: {$this->representativesPath}\n" .
                "Run cluster_groups.php first to generate this file.");
        }

        $fp = fopen($this->representativesPath, 'r');
        $headers = fgetcsv($fp);

        $representatives = [];
        while ($row = fgetcsv($fp)) {
            $rep = array_combine($headers, $row);
            $representatives[] = $rep;
        }

        fclose($fp);
        return $representatives;
    }

    private function groupByCluster($representatives) {
        $byCluster = [];

        foreach ($representatives as $rep) {
            $clusterId = $rep['cluster'];
            if (!isset($byCluster[$clusterId])) {
                $byCluster[$clusterId] = [];
            }
            $byCluster[$clusterId][] = $rep;
        }

        return $byCluster;
    }

    private function optimizeForCluster($clusterId, $groupIds) {
        echo "Running optimization for cluster $clusterId...\n";

        $dbPath = "/tmp/cluster_{$clusterId}_optimization.db";

        // Remove old database if exists
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        $optimizer = new IsochroneParameterOptimizer($this->dbhr, $this->dbhm, [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'groupIds' => $groupIds,
            'sampleSize' => $this->sampleSize,
            'dbPath' => $dbPath
        ]);

        // Run stage 1 only (spatial parameters)
        // Stage 2 (temporal) can be done later if parameters differ significantly
        $bestParams = $optimizer->optimizeStage1($this->iterations);

        // Get optimization history for analysis
        $history = $optimizer->getOptimizationHistory();

        // Calculate score statistics
        $scores = array_column($history, 'score');
        $bestScore = max($scores);
        $avgScore = array_sum($scores) / count($scores);

        return [
            'cluster_id' => $clusterId,
            'group_ids' => $groupIds,
            'best_params' => $bestParams,
            'best_score' => $bestScore,
            'avg_score' => $avgScore,
            'history' => $history
        ];
    }

    private function analyzeParameterDifferences($clusterResults) {
        echo "Analyzing parameter differences across clusters...\n\n";

        // Parameters to compare
        $spatialParams = ['initialMinutes', 'maxMinutes', 'increment', 'minUsers', 'activeSince', 'numReplies'];

        $analysis = [
            'parameters' => [],
            'overall_variance' => 0,
            'significant_differences' => FALSE
        ];

        foreach ($spatialParams as $param) {
            $values = [];
            foreach ($clusterResults as $clusterId => $result) {
                if (isset($result['best_params'][$param])) {
                    $values[$clusterId] = $result['best_params'][$param];
                }
            }

            if (count($values) == 0) continue;

            // Calculate statistics
            $mean = array_sum($values) / count($values);
            $min = min($values);
            $max = max($values);
            $range = $max - $min;

            // Calculate coefficient of variation (CV)
            $variance = 0;
            foreach ($values as $value) {
                $variance += pow($value - $mean, 2);
            }
            $stddev = sqrt($variance / count($values));
            $cv = $mean > 0 ? ($stddev / $mean) * 100 : 0;

            // Determine if differences are significant
            // CV > 20% suggests meaningful differences
            $significant = $cv > 20;

            $analysis['parameters'][$param] = [
                'values' => $values,
                'mean' => round($mean, 2),
                'min' => $min,
                'max' => $max,
                'range' => $range,
                'stddev' => round($stddev, 2),
                'cv_percent' => round($cv, 2),
                'significant' => $significant
            ];

            echo "Parameter: $param\n";
            echo "  Values by cluster: " . json_encode($values) . "\n";
            echo "  Mean: " . round($mean, 2) . ", Range: $min - $max\n";
            echo "  Coefficient of Variation: " . round($cv, 2) . "%\n";
            echo "  Significant difference: " . ($significant ? "YES" : "no") . "\n\n";
        }

        // Overall assessment: Are ANY parameters significantly different?
        $significantParams = array_filter($analysis['parameters'], function($p) {
            return $p['significant'];
        });

        $analysis['significant_differences'] = count($significantParams) > 0;
        $analysis['num_significant_params'] = count($significantParams);
        $analysis['significant_params'] = array_keys($significantParams);

        // Calculate overall variance score (average CV across parameters)
        $cvs = array_column($analysis['parameters'], 'cv_percent');
        $analysis['overall_variance'] = round(array_sum($cvs) / count($cvs), 2);

        return $analysis;
    }

    private function exportResults($clusterResults, $analysis) {
        $output = [
            'validation_date' => date('Y-m-d H:i:s'),
            'date_range' => [
                'start' => $this->startDate,
                'end' => $this->endDate
            ],
            'sample_size_per_iteration' => $this->sampleSize,
            'iterations_per_cluster' => $this->iterations,
            'cluster_results' => $clusterResults,
            'analysis' => $analysis
        ];

        file_put_contents($this->outputPath, json_encode($output, JSON_PRETTY_PRINT));

        echo "\nValidation results exported to: {$this->outputPath}\n";
    }

    private function showRecommendation($analysis) {
        echo "\n=== RECOMMENDATION ===\n\n";

        if (!$analysis['significant_differences']) {
            echo "✓ Parameters are CONSISTENT across clusters\n\n";
            echo "Recommendation: Use GLOBAL parameters for all groups.\n";
            echo "  - Run full optimization with all groups combined\n";
            echo "  - Apply same parameters to all groups\n";
            echo "  - This simplifies deployment and maintenance\n\n";

            echo "Why: Coefficient of variation is low across all parameters\n";
            echo "  (Overall variance: {$analysis['overall_variance']}% < 20% threshold)\n\n";

        } else {
            echo "⚠ Parameters VARY SIGNIFICANTLY across clusters\n\n";
            echo "Recommendation: Use CLUSTER-SPECIFIC parameters.\n";
            echo "  - Run full optimization (Stage 1 + 2) for each cluster\n";
            echo "  - Map all groups to their cluster\n";
            echo "  - Apply cluster-specific parameters in production\n\n";

            echo "Why: {$analysis['num_significant_params']} parameter(s) differ significantly:\n";
            foreach ($analysis['significant_params'] as $param) {
                $info = $analysis['parameters'][$param];
                echo "  - $param: CV = {$info['cv_percent']}% (range: {$info['min']} - {$info['max']})\n";
            }
            echo "\n";

            echo "Next steps:\n";
            echo "  1. Run Stage 2 optimization for each cluster:\n";
            foreach (array_keys($analysis['parameters'][array_key_first($analysis['parameters'])]['values']) as $clusterId) {
                echo "     php optimize_isochrone_parameters.php --stage=2 --db-path=/tmp/cluster_{$clusterId}_optimization.db\n";
            }
            echo "\n  2. Create cluster parameter mapping in code\n";
            echo "  3. Update isochrone generation to use cluster-specific parameters\n\n";
        }
    }
}

// Parse command line arguments
$options = getopt('', [
    'representatives:',
    'start:',
    'end:',
    'sample-size:',
    'iterations:',
    'output:',
    'help'
]);

if (isset($options['help'])) {
    echo "Usage: php spiralling_investigation_validate_clusters.php [options]\n\n";
    echo "Validates whether isochrone parameters should vary by cluster by running\n";
    echo "parameter optimization on representative groups from each cluster.\n\n";
    echo "This script answers the question: 'Do different types of groups (urban, rural, etc.)\n";
    echo "need different isochrone parameters, or can we use one global set?'\n\n";
    echo "Options:\n";
    echo "  --representatives=PATH  Input CSV from cluster_groups.php\n";
    echo "                          (default: /tmp/group_clusters_representatives.csv)\n";
    echo "  --start=DATE            Start date for historical data (default: 60 days ago)\n";
    echo "  --end=DATE              End date for historical data (default: 7 days ago)\n";
    echo "  --sample-size=N         Messages per iteration (default: 50)\n";
    echo "  --iterations=N          Optimization iterations per cluster (default: 30)\n";
    echo "  --output=PATH           Output JSON file path\n";
    echo "                          (default: /tmp/cluster_parameter_validation.json)\n";
    echo "  --help                  Show this help\n\n";
    echo "Process:\n";
    echo "  1. Loads representative groups from each cluster\n";
    echo "  2. Runs parameter optimization for each cluster independently\n";
    echo "  3. Compares optimized parameters across clusters\n";
    echo "  4. Uses coefficient of variation (CV) to assess differences\n";
    echo "  5. Recommends global vs cluster-specific parameters\n\n";
    echo "Decision criteria:\n";
    echo "  - CV < 20%: Parameters are similar → use global parameters\n";
    echo "  - CV > 20%: Parameters differ → use cluster-specific parameters\n\n";
    echo "Example workflow:\n";
    echo "  # 1. Analyze all groups\n";
    echo "  php spiralling_investigation_analyze_characteristics.php\n\n";
    echo "  # 2. Cluster groups by characteristics\n";
    echo "  php spiralling_investigation_cluster_groups.php --clusters=4\n\n";
    echo "  # 3. Validate if clusters need different parameters (THIS SCRIPT)\n";
    echo "  php spiralling_investigation_validate_clusters.php --iterations=50\n\n";
    echo "  # 4. Based on recommendation, either:\n";
    echo "  #    a) Run global optimization if parameters are consistent\n";
    echo "  #    b) Run per-cluster optimization if parameters vary\n\n";
    echo "Database Files:\n";
    echo "  - Validation results: {output} (JSON summary)\n";
    echo "  - Per-cluster data: /tmp/cluster_{N}_optimization.db (SQLite databases)\n\n";
    exit(0);
}

$validatorOptions = [];
if (isset($options['representatives'])) {
    $validatorOptions['representativesPath'] = $options['representatives'];
}
if (isset($options['start'])) {
    $validatorOptions['startDate'] = $options['start'];
}
if (isset($options['end'])) {
    $validatorOptions['endDate'] = $options['end'];
}
if (isset($options['sample-size'])) {
    $validatorOptions['sampleSize'] = intval($options['sample-size']);
}
if (isset($options['iterations'])) {
    $validatorOptions['iterations'] = intval($options['iterations']);
}
if (isset($options['output'])) {
    $validatorOptions['outputPath'] = $options['output'];
}

$validator = new ClusterParameterValidator($dbhr, $dbhm, $validatorOptions);
$validator->validate();

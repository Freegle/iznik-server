<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

/**
 * Cluster groups by characteristics to identify representative groups for optimization
 *
 * Uses k-means clustering on normalized group characteristics to identify
 * groups with similar geographic and demographic profiles.
 */

class GroupClusterer {
    private $inputPath;
    private $outputPath;
    private $numClusters;
    private $features;

    public function __construct($options = []) {
        $this->inputPath = $options['inputPath'] ?? '/tmp/group_characteristics.csv';
        $this->outputPath = $options['outputPath'] ?? '/tmp/group_clusters.csv';
        $this->numClusters = $options['numClusters'] ?? 4;

        // Features to use for clustering (normalized)
        $this->features = [
            'area_km2',
            'user_density',
            'compactness',
            'avg_user_distance_km',
            'urban_percentage'
        ];
    }

    public function cluster() {
        echo "=== Clustering Groups ===\n\n";

        // Load group characteristics
        $groups = $this->loadCSV($this->inputPath);
        echo "Loaded " . count($groups) . " groups from {$this->inputPath}\n";

        if (count($groups) < $this->numClusters) {
            echo "Error: Not enough groups (" . count($groups) . ") for {$this->numClusters} clusters\n";
            return;
        }

        // Normalize features
        $normalized = $this->normalizeFeatures($groups);

        // Run k-means clustering
        echo "Running k-means clustering with {$this->numClusters} clusters...\n\n";
        $assignments = $this->kMeansClustering($normalized, $this->numClusters);

        // Add cluster assignments to original data
        foreach ($groups as $i => $group) {
            $groups[$i]['cluster'] = $assignments[$i];
        }

        // Analyze clusters
        $this->analyzeClusters($groups);

        // Select representative groups from each cluster
        $representatives = $this->selectRepresentatives($groups, $normalized, $assignments);

        // Export results
        $this->exportResults($groups, $representatives);

        return [
            'groups' => $groups,
            'representatives' => $representatives
        ];
    }

    /**
     * Evaluate different numbers of clusters to help determine optimal k
     */
    public function evaluateClusters($minK = 2, $maxK = 10) {
        echo "=== Evaluating Cluster Counts ===\n\n";

        // Load and normalize data
        $groups = $this->loadCSV($this->inputPath);
        echo "Loaded " . count($groups) . " groups from {$this->inputPath}\n";

        if (count($groups) < $maxK) {
            $maxK = count($groups) - 1;
            echo "Adjusting maxK to " . $maxK . " (limited by number of groups)\n";
        }

        $normalized = $this->normalizeFeatures($groups);

        echo "\nTesting k from $minK to $maxK...\n\n";
        echo str_pad("k", 5) . str_pad("WCSS", 12) . str_pad("Silhouette", 15) . "Interpretation\n";
        echo str_repeat("-", 70) . "\n";

        $results = [];

        for ($k = $minK; $k <= $maxK; $k++) {
            // Run clustering
            $assignments = $this->kMeansClustering($normalized, $k, 50);

            // Calculate metrics
            $wcss = $this->calculateWCSS($normalized, $assignments, $k);
            $silhouette = $this->calculateSilhouette($normalized, $assignments);

            // Interpretation
            $interpretation = $this->interpretSilhouette($silhouette);

            $results[] = [
                'k' => $k,
                'wcss' => $wcss,
                'silhouette' => $silhouette
            ];

            echo str_pad($k, 5) .
                 str_pad(number_format($wcss, 2), 12) .
                 str_pad(number_format($silhouette, 4), 15) .
                 $interpretation . "\n";
        }

        echo "\n" . str_repeat("-", 70) . "\n";
        echo "\nMetric Explanations:\n";
        echo "- WCSS (Within-Cluster Sum of Squares): Lower is better (tighter clusters)\n";
        echo "  Look for 'elbow' where adding more clusters doesn't help much\n";
        echo "- Silhouette Score: Ranges from -1 to 1, higher is better\n";
        echo "  > 0.7: Strong clustering\n";
        echo "  > 0.5: Reasonable clustering\n";
        echo "  > 0.25: Weak but acceptable\n";
        echo "  < 0.25: Poor clustering\n\n";

        // Recommend optimal k
        $recommendation = $this->recommendK($results);
        echo "=== Recommendation ===\n";
        echo "Based on the metrics, consider using k = $recommendation\n";
        echo "However, also consider domain knowledge:\n";
        echo "  - k=3: Urban, Suburban, Rural\n";
        echo "  - k=4: Dense Urban, Suburban, Rural Large, Rural Small\n";
        echo "  - k=5: Very Dense Urban, Urban, Suburban, Rural, Very Rural\n\n";

        return $results;
    }

    private function calculateWCSS($normalized, $assignments, $k) {
        // Calculate centroids for each cluster
        $centroids = [];
        for ($c = 0; $c < $k; $c++) {
            $clusterPoints = [];
            foreach ($assignments as $i => $clusterId) {
                if ($clusterId == $c) {
                    $clusterPoints[] = $normalized[$i];
                }
            }
            if (count($clusterPoints) > 0) {
                $centroids[$c] = $this->calculateCentroid($clusterPoints);
            }
        }

        // Calculate sum of squared distances to centroids
        $wcss = 0;
        foreach ($assignments as $i => $clusterId) {
            if (isset($centroids[$clusterId])) {
                $dist = $this->euclideanDistance($normalized[$i], $centroids[$clusterId]);
                $wcss += $dist * $dist;
            }
        }

        return $wcss;
    }

    private function calculateSilhouette($normalized, $assignments) {
        $n = count($normalized);
        $silhouetteScores = [];

        for ($i = 0; $i < $n; $i++) {
            $myCluster = $assignments[$i];

            // Calculate a(i): average distance to points in same cluster
            $sameClusterDistances = [];
            for ($j = 0; $j < $n; $j++) {
                if ($i != $j && $assignments[$j] == $myCluster) {
                    $sameClusterDistances[] = $this->euclideanDistance($normalized[$i], $normalized[$j]);
                }
            }
            $a = count($sameClusterDistances) > 0 ? array_sum($sameClusterDistances) / count($sameClusterDistances) : 0;

            // Calculate b(i): minimum average distance to points in other clusters
            $otherClusters = array_unique($assignments);
            $minAvgDistance = PHP_FLOAT_MAX;

            foreach ($otherClusters as $otherCluster) {
                if ($otherCluster == $myCluster) continue;

                $otherClusterDistances = [];
                for ($j = 0; $j < $n; $j++) {
                    if ($assignments[$j] == $otherCluster) {
                        $otherClusterDistances[] = $this->euclideanDistance($normalized[$i], $normalized[$j]);
                    }
                }

                if (count($otherClusterDistances) > 0) {
                    $avgDistance = array_sum($otherClusterDistances) / count($otherClusterDistances);
                    $minAvgDistance = min($minAvgDistance, $avgDistance);
                }
            }
            $b = $minAvgDistance;

            // Silhouette score for this point
            $silhouetteScores[] = $b > $a ? ($b - $a) / $b : 0;
        }

        // Return average silhouette score
        return array_sum($silhouetteScores) / count($silhouetteScores);
    }

    private function interpretSilhouette($score) {
        if ($score > 0.7) return "Strong";
        if ($score > 0.5) return "Reasonable";
        if ($score > 0.25) return "Weak but acceptable";
        return "Poor";
    }

    private function recommendK($results) {
        // Find k with best silhouette score
        $bestSilhouette = -1;
        $bestK = 2;

        foreach ($results as $result) {
            if ($result['silhouette'] > $bestSilhouette) {
                $bestSilhouette = $result['silhouette'];
                $bestK = $result['k'];
            }
        }

        // But also check for elbow in WCSS
        // Look for biggest drop in WCSS improvement
        $maxImprovement = 0;
        $elbowK = 2;

        for ($i = 1; $i < count($results) - 1; $i++) {
            $prevDrop = $results[$i-1]['wcss'] - $results[$i]['wcss'];
            $nextDrop = $results[$i]['wcss'] - $results[$i+1]['wcss'];
            $improvement = $prevDrop - $nextDrop;

            if ($improvement > $maxImprovement) {
                $maxImprovement = $improvement;
                $elbowK = $results[$i]['k'];
            }
        }

        // Prefer silhouette if it's reasonable, otherwise use elbow
        if ($bestSilhouette > 0.25) {
            return $bestK;
        } else {
            return $elbowK;
        }
    }

    private function loadCSV($path) {
        if (!file_exists($path)) {
            throw new \Exception("Input file not found: $path");
        }

        $fp = fopen($path, 'r');
        $headers = fgetcsv($fp);

        $groups = [];
        while ($row = fgetcsv($fp)) {
            $group = array_combine($headers, $row);
            $groups[] = $group;
        }

        fclose($fp);
        return $groups;
    }

    private function normalizeFeatures($groups) {
        echo "Normalizing features...\n";

        $normalized = [];
        $stats = [];

        // Calculate mean and stddev for each feature
        foreach ($this->features as $feature) {
            $values = array_column($groups, $feature);
            $mean = array_sum($values) / count($values);

            $variance = 0;
            foreach ($values as $value) {
                $variance += pow($value - $mean, 2);
            }
            $stddev = sqrt($variance / count($values));

            $stats[$feature] = [
                'mean' => $mean,
                'stddev' => max($stddev, 0.0001) // Avoid division by zero
            ];

            echo "  $feature: mean=" . round($mean, 2) . ", stddev=" . round($stddev, 2) . "\n";
        }

        // Normalize each group
        foreach ($groups as $group) {
            $normalizedGroup = [];

            foreach ($this->features as $feature) {
                $value = floatval($group[$feature]);
                $mean = $stats[$feature]['mean'];
                $stddev = $stats[$feature]['stddev'];

                // Z-score normalization
                $normalizedGroup[$feature] = ($value - $mean) / $stddev;
            }

            $normalized[] = $normalizedGroup;
        }

        echo "Normalized " . count($normalized) . " groups\n\n";
        return $normalized;
    }

    private function kMeansClustering($normalized, $k, $maxIterations = 100) {
        $n = count($normalized);

        // Initialize centroids randomly
        $centroidIndices = array_rand($normalized, $k);
        if (!is_array($centroidIndices)) {
            $centroidIndices = [$centroidIndices];
        }

        $centroids = [];
        foreach ($centroidIndices as $idx) {
            $centroids[] = $normalized[$idx];
        }

        $assignments = array_fill(0, $n, 0);
        $changed = TRUE;
        $iteration = 0;

        while ($changed && $iteration < $maxIterations) {
            $changed = FALSE;
            $iteration++;

            // Assign each point to nearest centroid
            for ($i = 0; $i < $n; $i++) {
                $minDist = PHP_FLOAT_MAX;
                $bestCluster = 0;

                for ($c = 0; $c < $k; $c++) {
                    $dist = $this->euclideanDistance($normalized[$i], $centroids[$c]);
                    if ($dist < $minDist) {
                        $minDist = $dist;
                        $bestCluster = $c;
                    }
                }

                if ($assignments[$i] != $bestCluster) {
                    $assignments[$i] = $bestCluster;
                    $changed = TRUE;
                }
            }

            // Recalculate centroids
            for ($c = 0; $c < $k; $c++) {
                $clusterPoints = [];
                for ($i = 0; $i < $n; $i++) {
                    if ($assignments[$i] == $c) {
                        $clusterPoints[] = $normalized[$i];
                    }
                }

                if (count($clusterPoints) > 0) {
                    $centroids[$c] = $this->calculateCentroid($clusterPoints);
                }
            }
        }

        echo "K-means converged after $iteration iterations\n";

        return $assignments;
    }

    private function euclideanDistance($point1, $point2) {
        $sum = 0;
        foreach ($this->features as $feature) {
            $diff = $point1[$feature] - $point2[$feature];
            $sum += $diff * $diff;
        }
        return sqrt($sum);
    }

    private function calculateCentroid($points) {
        $centroid = [];

        foreach ($this->features as $feature) {
            $sum = 0;
            foreach ($points as $point) {
                $sum += $point[$feature];
            }
            $centroid[$feature] = $sum / count($points);
        }

        return $centroid;
    }

    private function analyzeClusters($groups) {
        echo "\n=== Cluster Analysis ===\n\n";

        // Group by cluster
        $clusters = [];
        foreach ($groups as $group) {
            $clusterId = $group['cluster'];
            if (!isset($clusters[$clusterId])) {
                $clusters[$clusterId] = [];
            }
            $clusters[$clusterId][] = $group;
        }

        // Analyze each cluster
        foreach ($clusters as $clusterId => $clusterGroups) {
            echo "Cluster $clusterId: " . count($clusterGroups) . " groups\n";

            // Calculate median characteristics
            $areas = array_column($clusterGroups, 'area_km2');
            $densities = array_column($clusterGroups, 'user_density');
            $urbanPcts = array_column($clusterGroups, 'urban_percentage');
            $types = array_column($clusterGroups, 'group_type');

            echo "  Median area: " . round($this->median($areas), 2) . " km²\n";
            echo "  Median density: " . round($this->median($densities), 2) . " users/km²\n";
            echo "  Median urban %: " . round($this->median($urbanPcts), 2) . "%\n";

            // Most common type
            $typeCounts = array_count_values($types);
            arsort($typeCounts);
            $mostCommonType = array_key_first($typeCounts);
            echo "  Most common type: $mostCommonType (" . $typeCounts[$mostCommonType] . " groups)\n";

            // Show a few example groups
            echo "  Example groups: ";
            $examples = array_slice($clusterGroups, 0, 3);
            echo implode(', ', array_column($examples, 'group_name')) . "\n";

            echo "\n";
        }
    }

    private function selectRepresentatives($groups, $normalized, $assignments) {
        echo "=== Selecting Representative Groups ===\n\n";

        $representatives = [];

        // For each cluster, select groups closest to centroid
        $numClusters = max($assignments) + 1;

        for ($c = 0; $c < $numClusters; $c++) {
            // Get all groups in this cluster
            $clusterIndices = [];
            foreach ($assignments as $i => $clusterId) {
                if ($clusterId == $c) {
                    $clusterIndices[] = $i;
                }
            }

            if (count($clusterIndices) == 0) {
                continue;
            }

            // Calculate cluster centroid
            $clusterPoints = [];
            foreach ($clusterIndices as $idx) {
                $clusterPoints[] = $normalized[$idx];
            }
            $centroid = $this->calculateCentroid($clusterPoints);

            // Find 3 groups closest to centroid
            $distances = [];
            foreach ($clusterIndices as $idx) {
                $dist = $this->euclideanDistance($normalized[$idx], $centroid);
                $distances[$idx] = $dist;
            }

            asort($distances);
            $representativeIndices = array_slice(array_keys($distances), 0, 3, TRUE);

            echo "Cluster $c representatives:\n";
            foreach ($representativeIndices as $idx) {
                $group = $groups[$idx];
                echo "  - {$group['group_name']} (ID: {$group['group_id']})\n";
                echo "    Area: {$group['area_km2']} km², Density: {$group['user_density']} users/km², Urban: {$group['urban_percentage']}%\n";

                $representatives[] = [
                    'cluster' => $c,
                    'group_id' => $group['group_id'],
                    'group_name' => $group['group_name'],
                    'distance_to_centroid' => round($distances[$idx], 4)
                ];
            }
            echo "\n";
        }

        return $representatives;
    }

    private function exportResults($groups, $representatives) {
        // Export clustered groups
        $fp = fopen($this->outputPath, 'w');

        $headers = array_keys($groups[0]);
        fputcsv($fp, $headers);

        foreach ($groups as $group) {
            fputcsv($fp, array_values($group));
        }

        fclose($fp);
        echo "Clustered groups exported to: {$this->outputPath}\n";

        // Export representatives
        $repPath = str_replace('.csv', '_representatives.csv', $this->outputPath);
        $fp = fopen($repPath, 'w');

        $headers = array_keys($representatives[0]);
        fputcsv($fp, $headers);

        foreach ($representatives as $rep) {
            fputcsv($fp, array_values($rep));
        }

        fclose($fp);
        echo "Representative groups exported to: $repPath\n";
    }

    private function median($arr) {
        if (count($arr) == 0) return 0;
        sort($arr);
        $count = count($arr);
        $middle = floor($count / 2);

        if ($count % 2 == 0) {
            return ($arr[$middle - 1] + $arr[$middle]) / 2;
        }

        return $arr[$middle];
    }
}

// Parse command line arguments
$options = getopt('', [
    'input:',
    'output:',
    'clusters:',
    'evaluate',
    'min-k:',
    'max-k:',
    'help'
]);

if (isset($options['help'])) {
    echo "Usage: php spiralling_investigation_cluster_groups.php [options]\n\n";
    echo "Clusters groups by characteristics using k-means clustering to identify\n";
    echo "representative groups for parameter optimization.\n\n";
    echo "Options:\n";
    echo "  --input=PATH      Input CSV from analyze_group_characteristics.php\n";
    echo "                    (default: /tmp/group_characteristics.csv)\n";
    echo "  --output=PATH     Output CSV file path\n";
    echo "                    (default: /tmp/group_clusters.csv)\n";
    echo "  --clusters=N      Number of clusters (default: 4)\n";
    echo "  --evaluate        Evaluate different cluster counts (k=2 to k=10)\n";
    echo "                    Shows WCSS and Silhouette scores to help choose k\n";
    echo "  --min-k=N         Minimum k to evaluate (default: 2, requires --evaluate)\n";
    echo "  --max-k=N         Maximum k to evaluate (default: 10, requires --evaluate)\n";
    echo "  --help            Show this help\n\n";
    echo "Clustering features:\n";
    echo "  - area_km2: Geographic size of group catchment area\n";
    echo "  - user_density: Active users per km²\n";
    echo "  - compactness: Shape regularity (1.0 = circle)\n";
    echo "  - avg_user_distance_km: Average user distance from centroid\n";
    echo "  - urban_percentage: Estimated urban vs rural mix\n\n";
    echo "Workflow:\n";
    echo "  1. Run with --evaluate to determine optimal number of clusters:\n";
    echo "     php spiralling_investigation_cluster_groups.php --evaluate\n\n";
    echo "  2. Run actual clustering with chosen k:\n";
    echo "     php spiralling_investigation_cluster_groups.php --clusters=4\n\n";
    echo "Output:\n";
    echo "  - group_clusters.csv: All groups with cluster assignments\n";
    echo "  - group_clusters_representatives.csv: 3 representative groups per cluster\n\n";
    exit(0);
}

$clustererOptions = [];
if (isset($options['input'])) {
    $clustererOptions['inputPath'] = $options['input'];
}
if (isset($options['output'])) {
    $clustererOptions['outputPath'] = $options['output'];
}
if (isset($options['clusters'])) {
    $clustererOptions['numClusters'] = intval($options['clusters']);
}

$clusterer = new GroupClusterer($clustererOptions);

if (isset($options['evaluate'])) {
    // Evaluate mode - test different k values
    $minK = isset($options['min-k']) ? intval($options['min-k']) : 2;
    $maxK = isset($options['max-k']) ? intval($options['max-k']) : 10;
    $clusterer->evaluateClusters($minK, $maxK);
} else {
    // Normal mode - perform clustering
    $clusterer->cluster();
}

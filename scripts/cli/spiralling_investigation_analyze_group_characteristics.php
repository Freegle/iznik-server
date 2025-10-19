<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

/**
 * Analyze group characteristics to determine if isochrone parameters should vary by group
 *
 * This script calculates geographic and demographic characteristics for all groups
 * to enable clustering and representative sampling for parameter optimization.
 */

class GroupCharacteristicsAnalyzer {
    private $dbhr;
    private $dbhm;
    private $outputPath;

    public function __construct($dbhr, $dbhm, $options = []) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->outputPath = $options['outputPath'] ?? '/tmp/group_characteristics.csv';
    }

    public function analyze() {
        echo "=== Analyzing Group Characteristics ===\n\n";

        // Get all active Freegle groups with CGA
        $groups = $this->getActiveGroups();
        echo "Found " . count($groups) . " active groups with CGA polygons\n\n";

        if (count($groups) == 0) {
            echo "No groups found. Exiting.\n";
            return;
        }

        $results = [];

        foreach ($groups as $index => $group) {
            $groupNum = $index + 1;
            echo "[$groupNum/" . count($groups) . "] Analyzing group {$group['id']}: {$group['nameshort']}\n";

            try {
                $characteristics = $this->analyzeGroup($group);
                if ($characteristics) {
                    $results[] = $characteristics;
                    echo "  ✓ Area: {$characteristics['area_km2']} km², Users: {$characteristics['active_users']}, Density: {$characteristics['user_density']} users/km²\n";
                } else {
                    echo "  ⚠ Skipped - no valid CGA or users\n";
                }
            } catch (\Exception $e) {
                echo "  ✗ Error: " . $e->getMessage() . "\n";
            }
        }

        echo "\n=== Analysis Complete ===\n";
        echo "Analyzed " . count($results) . " groups\n";

        // Export to CSV
        $this->exportToCSV($results);

        // Show summary statistics
        $this->showSummaryStats($results);

        return $results;
    }

    private function getActiveGroups() {
        $sql = "SELECT id, nameshort, ST_AsGeoJSON(polyindex) as cga_geojson
                FROM `groups`
                WHERE type = 'Freegle'
                  AND publish = 1
                  AND polyindex IS NOT NULL
                  AND ST_GeometryType(polyindex) IN ('POLYGON', 'MULTIPOLYGON')
                ORDER BY nameshort ASC";

        return $this->dbhr->preQuery($sql);
    }

    private function analyzeGroup($group) {
        $groupId = $group['id'];

        // Parse CGA polygon
        $cgaGeoJson = json_decode($group['cga_geojson'], TRUE);
        if (!$cgaGeoJson || !isset($cgaGeoJson['coordinates'])) {
            return NULL;
        }

        // Get CGA centroid
        $centroid = $this->getCentroid($groupId);
        if (!$centroid) {
            return NULL;
        }

        // Calculate geometric characteristics
        $areaM2 = $this->calculateArea($cgaGeoJson);
        if ($areaM2 <= 0) {
            return NULL;
        }
        $areaKm2 = round($areaM2 / 1000000, 2);
        $perimeterKm = $this->calculatePerimeter($cgaGeoJson);
        $compactness = $this->calculateCompactness($areaKm2, $perimeterKm);

        // Get active users in the last 90 days
        $activeUsers = $this->getActiveUsers($groupId);
        $userCount = count($activeUsers);

        if ($userCount == 0) {
            return NULL;
        }

        $userDensity = round($userCount / max($areaKm2, 0.1), 2);

        // Calculate user spatial distribution
        $userSpread = $this->calculateUserSpread($activeUsers, $centroid);

        // Calculate messages per week (as activity metric)
        $messagesPerWeek = $this->getMessageRate($groupId);

        // Calculate urban/rural classification using user locations
        $urbanPct = $this->estimateUrbanPercentage($activeUsers);

        // Classify group type
        $groupType = $this->classifyGroupType($areaKm2, $userDensity, $urbanPct);

        return [
            'group_id' => $groupId,
            'group_name' => $group['nameshort'],
            'area_km2' => $areaKm2,
            'perimeter_km' => $perimeterKm,
            'compactness' => $compactness,
            'centroid_lat' => $centroid['lat'],
            'centroid_lng' => $centroid['lng'],
            'active_users' => $userCount,
            'user_density' => $userDensity,
            'avg_user_distance_km' => $userSpread['avg_distance'],
            'user_spread_stddev_km' => $userSpread['stddev'],
            'messages_per_week' => $messagesPerWeek,
            'urban_percentage' => $urbanPct,
            'group_type' => $groupType
        ];
    }

    private function getCentroid($groupId) {
        $sql = "SELECT ST_X(ST_Centroid(polyindex)) as lng,
                       ST_Y(ST_Centroid(polyindex)) as lat
                FROM `groups`
                WHERE id = ?";

        $result = $this->dbhr->preQuery($sql, [$groupId]);

        if (count($result) > 0) {
            return [
                'lat' => floatval($result[0]['lat']),
                'lng' => floatval($result[0]['lng'])
            ];
        }

        return NULL;
    }

    private function calculatePerimeter($geoJson) {
        // Simple perimeter calculation from GeoJSON coordinates
        if (!isset($geoJson['coordinates'][0])) {
            return 0;
        }

        $coords = $geoJson['coordinates'][0];
        $perimeter = 0;

        for ($i = 0; $i < count($coords) - 1; $i++) {
            $p1 = $coords[$i];
            $p2 = $coords[$i + 1];

            // Haversine distance
            $distance = $this->haversineDistance($p1[1], $p1[0], $p2[1], $p2[0]);
            $perimeter += $distance;
        }

        return round($perimeter, 2);
    }

    private function calculateArea($geoJson) {
        // Calculate area in square meters from GeoJSON polygon coordinates
        // Uses planar approximation with latitude correction (accurate for small areas)

        if (!isset($geoJson['coordinates'][0])) {
            return 0;
        }

        $coords = $geoJson['coordinates'][0];
        if (count($coords) < 3) {
            return 0;
        }

        // Calculate centroid latitude for projection
        $sumLat = 0;
        foreach ($coords as $coord) {
            $sumLat += $coord[1]; // lat is second element in GeoJSON
        }
        $avgLat = $sumLat / count($coords);

        // Conversion factors at this latitude
        // 1 degree latitude ≈ 111,320 meters
        // 1 degree longitude ≈ 111,320 * cos(latitude) meters
        $metersPerDegreeLat = 111320;
        $metersPerDegreeLng = 111320 * cos(deg2rad($avgLat));

        // Convert to projected coordinates and use shoelace formula
        $area = 0;
        for ($i = 0; $i < count($coords) - 1; $i++) {
            $x1 = $coords[$i][0] * $metersPerDegreeLng;     // lng
            $y1 = $coords[$i][1] * $metersPerDegreeLat;     // lat
            $x2 = $coords[$i + 1][0] * $metersPerDegreeLng;
            $y2 = $coords[$i + 1][1] * $metersPerDegreeLat;

            $area += ($x1 * $y2) - ($x2 * $y1);
        }

        return abs($area / 2);
    }

    private function haversineDistance($lat1, $lng1, $lat2, $lng2) {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }

    private function calculateCompactness($areaKm2, $perimeterKm) {
        // Polsby-Popper compactness: 4π * area / perimeter²
        // 1.0 = perfect circle, lower = more elongated/irregular
        if ($perimeterKm == 0) {
            return 0;
        }

        $compactness = (4 * M_PI * $areaKm2) / ($perimeterKm * $perimeterKm);
        return round(min($compactness, 1.0), 4);
    }

    private function getActiveUsers($groupId) {
        // Get users active in last 90 days with approximate locations
        $sql = "SELECT DISTINCT
                    users_approxlocs.userid,
                    users_approxlocs.lat,
                    users_approxlocs.lng
                FROM users_approxlocs
                INNER JOIN memberships ON users_approxlocs.userid = memberships.userid
                WHERE memberships.groupid = ?
                  AND users_approxlocs.timestamp >= DATE_SUB(NOW(), INTERVAL 90 DAY)";

        return $this->dbhr->preQuery($sql, [$groupId]);
    }

    private function calculateUserSpread($users, $centroid) {
        if (count($users) == 0) {
            return ['avg_distance' => 0, 'stddev' => 0];
        }

        $distances = [];

        foreach ($users as $user) {
            $distance = $this->haversineDistance(
                $centroid['lat'], $centroid['lng'],
                $user['lat'], $user['lng']
            );
            $distances[] = $distance;
        }

        $avgDistance = round(array_sum($distances) / count($distances), 2);

        // Calculate standard deviation
        $variance = 0;
        foreach ($distances as $distance) {
            $variance += pow($distance - $avgDistance, 2);
        }
        $stddev = round(sqrt($variance / count($distances)), 2);

        return [
            'avg_distance' => $avgDistance,
            'stddev' => $stddev
        ];
    }

    private function getMessageRate($groupId) {
        // Messages per week in last 30 days
        $sql = "SELECT COUNT(*) / 4.0 as messages_per_week
                FROM messages
                INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                WHERE messages_groups.groupid = ?
                  AND messages.arrival >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND messages.type IN ('Offer', 'Wanted')
                  AND messages.deleted IS NULL";

        $result = $this->dbhr->preQuery($sql, [$groupId]);
        return round($result[0]['messages_per_week'], 2);
    }

    private function estimateUrbanPercentage($users) {
        // Estimate urban percentage based on user clustering
        // Urban areas typically have tight user clusters

        if (count($users) < 10) {
            return 50; // Unknown, default to mixed
        }

        // Calculate pairwise distances
        $nearbyCount = 0;
        $totalPairs = 0;

        $sampleSize = min(100, count($users)); // Sample for performance
        $sampledUsers = array_rand(array_flip(array_keys($users)), $sampleSize);

        foreach ($sampledUsers as $i) {
            foreach ($sampledUsers as $j) {
                if ($i >= $j) continue;

                $totalPairs++;
                $distance = $this->haversineDistance(
                    $users[$i]['lat'], $users[$i]['lng'],
                    $users[$j]['lat'], $users[$j]['lng']
                );

                // Users within 2km are "nearby" (urban-like)
                if ($distance < 2) {
                    $nearbyCount++;
                }
            }
        }

        if ($totalPairs == 0) {
            return 50;
        }

        // High percentage of nearby pairs = more urban
        $nearbyPct = ($nearbyCount / $totalPairs) * 100;

        // Scale to 0-100
        $urbanPct = min(100, $nearbyPct * 2);

        return round($urbanPct, 2);
    }

    private function classifyGroupType($areaKm2, $userDensity, $urbanPct) {
        // Simple heuristic classification

        if ($userDensity > 10 && $urbanPct > 60) {
            return 'Urban Dense';
        } elseif ($userDensity > 5 && $urbanPct > 40) {
            return 'Urban Moderate';
        } elseif ($userDensity > 2) {
            return 'Suburban';
        } elseif ($areaKm2 > 1000) {
            return 'Rural Large';
        } else {
            return 'Rural Small';
        }
    }

    private function exportToCSV($results) {
        if (empty($results)) {
            echo "No results to export\n";
            return;
        }

        $fp = fopen($this->outputPath, 'w');

        // Header
        $headers = array_keys($results[0]);
        fputcsv($fp, $headers);

        // Data
        foreach ($results as $row) {
            fputcsv($fp, array_values($row));
        }

        fclose($fp);

        echo "\nResults exported to: {$this->outputPath}\n";
    }

    private function showSummaryStats($results) {
        if (empty($results)) {
            return;
        }

        echo "\n=== Summary Statistics ===\n";

        // Group by type
        $byType = [];
        foreach ($results as $r) {
            $type = $r['group_type'];
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $r;
        }

        echo "\nGroups by Type:\n";
        foreach ($byType as $type => $groups) {
            echo "  $type: " . count($groups) . " groups\n";
        }

        // Overall statistics
        $areas = array_column($results, 'area_km2');
        $densities = array_column($results, 'user_density');
        $urbanPcts = array_column($results, 'urban_percentage');

        echo "\nArea (km²):\n";
        echo "  Min: " . round(min($areas), 2) . "\n";
        echo "  Max: " . round(max($areas), 2) . "\n";
        echo "  Median: " . round($this->median($areas), 2) . "\n";

        echo "\nUser Density (users/km²):\n";
        echo "  Min: " . round(min($densities), 2) . "\n";
        echo "  Max: " . round(max($densities), 2) . "\n";
        echo "  Median: " . round($this->median($densities), 2) . "\n";

        echo "\nUrban Percentage:\n";
        echo "  Min: " . round(min($urbanPcts), 2) . "\n";
        echo "  Max: " . round(max($urbanPcts), 2) . "\n";
        echo "  Median: " . round($this->median($urbanPcts), 2) . "\n";
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
    'output:',
    'help'
]);

if (isset($options['help'])) {
    echo "Usage: php analyze_group_characteristics.php [options]\n\n";
    echo "Analyzes geographic and demographic characteristics of all Freegle groups\n";
    echo "to determine if isochrone parameters should vary by group type.\n\n";
    echo "Options:\n";
    echo "  --output=PATH    Output CSV file path (default: /tmp/group_characteristics.csv)\n";
    echo "  --help           Show this help\n\n";
    echo "Output:\n";
    echo "  CSV file with characteristics for each group:\n";
    echo "  - Geographic: area, perimeter, compactness, centroid\n";
    echo "  - User: active users, user density, spatial spread\n";
    echo "  - Activity: messages per week\n";
    echo "  - Classification: urban percentage, group type\n\n";
    echo "Next steps:\n";
    echo "  1. Run this script to generate group_characteristics.csv\n";
    echo "  2. Run cluster_groups.php to cluster similar groups\n";
    echo "  3. Run optimize_by_cluster.php to optimize parameters per cluster\n\n";
    exit(0);
}

$analyzerOptions = [];
if (isset($options['output'])) {
    $analyzerOptions['outputPath'] = $options['output'];
}

$analyzer = new GroupCharacteristicsAnalyzer($dbhr, $dbhm, $analyzerOptions);
$analyzer->analyze();

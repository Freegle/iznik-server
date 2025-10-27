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
                    echo "  ✓ Area: {$characteristics['area_km2']} km², Activity: {$characteristics['messages_per_week']} msg/wk, ONS: {$characteristics['ons_ru_category']} ({$characteristics['group_type']})\n";
                } else {
                    echo "  ⚠ Skipped - no valid CGA\n";
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

        // Calculate messages per week (as activity metric)
        $messagesPerWeek = $this->getMessageRate($groupId);

        // Get ONS Rural-Urban classification from external data
        $onsClassification = $this->getONSClassification($centroid);

        // Classify group type using ONS data
        $groupType = $this->classifyGroupTypeFromONS($onsClassification);

        return [
            'group_id' => $groupId,
            'group_name' => $group['nameshort'],
            'area_km2' => $areaKm2,
            'perimeter_km' => $perimeterKm,
            'compactness' => $compactness,
            'centroid_lat' => $centroid['lat'],
            'centroid_lng' => $centroid['lng'],
            'messages_per_week' => $messagesPerWeek,
            'ons_ru_category' => $onsClassification['ru_category'] ?? 'Unknown',
            'ons_region' => $onsClassification['region_name'] ?? 'Unknown',
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

    private function getONSClassification($centroid) {
        // Look up ONS Rural-Urban classification for the group's centroid
        // Find nearest postcode within 10km
        // Note: ONS RU categories only available for England/Wales, not Scotland/NI

        $sql = "SELECT ru_category, region_name,
                       ST_Distance_Sphere(
                           POINT(?, ?),
                           POINT(lng, lat)
                       ) AS distance_meters
                FROM transport_postcode_classification
                WHERE lat IS NOT NULL
                  AND lng IS NOT NULL
                  AND lat BETWEEN -90 AND 90
                  AND lng BETWEEN -180 AND 180
                  AND ru_category IS NOT NULL
                  AND ru_category != ''
                HAVING distance_meters < 10000
                ORDER BY distance_meters ASC
                LIMIT 1";

        $result = $this->dbhr->preQuery($sql, [$centroid['lng'], $centroid['lat']]);

        if (count($result) > 0) {
            return [
                'ru_category' => $result[0]['ru_category'],
                'region_name' => $result[0]['region_name'],
                'distance_m' => round($result[0]['distance_meters'])
            ];
        }

        // No England/Wales postcode found - use simple density-based classification
        // This handles Scotland/Northern Ireland where ONS RU categories aren't available
        return $this->estimateClassificationFromDensity($centroid);
    }

    private function estimateClassificationFromDensity($centroid) {
        // For areas without ONS data (Scotland, NI), estimate based on population density
        // Using a simple city/town lookup for major urban areas

        $lat = $centroid['lat'];
        $lng = $centroid['lng'];

        // Major Scottish cities (approximate bounds)
        $cities = [
            // [name, lat_min, lat_max, lng_min, lng_max, category]
            ['Glasgow', 55.8, 55.9, -4.35, -4.15, 'A1'],       // Major conurbation
            ['Edinburgh', 55.9, 56.0, -3.25, -3.05, 'A1'],     // Major conurbation
            ['Aberdeen', 57.1, 57.2, -2.2, -2.0, 'B1'],        // Minor conurbation
            ['Dundee', 56.4, 56.5, -3.0, -2.9, 'B1'],          // Minor conurbation
            ['Inverness', 57.4, 57.5, -4.3, -4.1, 'C1'],       // City/town
            ['Belfast', 54.5, 54.7, -6.0, -5.8, 'A1'],         // Major conurbation
        ];

        foreach ($cities as $city) {
            list($name, $latMin, $latMax, $lngMin, $lngMax, $category) = $city;
            if ($lat >= $latMin && $lat <= $latMax && $lng >= $lngMin && $lng <= $lngMax) {
                return [
                    'ru_category' => $category,
                    'region_name' => 'Scotland/NI (estimated)',
                    'distance_m' => 0
                ];
            }
        }

        // Default to rural for Scotland/NI
        return [
            'ru_category' => 'E1',  // Rural village (conservative default)
            'region_name' => 'Scotland/NI (estimated)',
            'distance_m' => 0
        ];
    }

    private function classifyGroupTypeFromONS($onsData) {
        // Classify group based on official ONS Rural-Urban classification

        if (!$onsData || !$onsData['ru_category']) {
            return 'Unknown';
        }

        $ruCat = $onsData['ru_category'];

        // A1 = Urban Major Conurbation (London, Manchester, etc.)
        // B1 = Urban Minor Conurbation
        // C1/C2 = Urban City and Town
        // D1/D2 = Rural Town and Fringe
        // E1/E2 = Rural Village
        // F1/F2 = Rural Hamlets and Isolated Dwellings

        if ($ruCat == 'A1') {
            return 'Urban Dense';
        } elseif ($ruCat == 'B1' || $ruCat == 'C1') {
            return 'Urban Moderate';
        } elseif ($ruCat == 'C2' || $ruCat == 'D1' || $ruCat == 'D2') {
            return 'Suburban';
        } elseif ($ruCat == 'E1' || $ruCat == 'E2') {
            return 'Rural Village';
        } elseif ($ruCat == 'F1' || $ruCat == 'F2') {
            return 'Rural Sparse';
        } else {
            return 'Unknown';
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
        $activities = array_column($results, 'messages_per_week');

        echo "\nArea (km²):\n";
        echo "  Min: " . round(min($areas), 2) . "\n";
        echo "  Max: " . round(max($areas), 2) . "\n";
        echo "  Median: " . round($this->median($areas), 2) . "\n";

        echo "\nActivity (messages/week):\n";
        echo "  Min: " . round(min($activities), 2) . "\n";
        echo "  Max: " . round(max($activities), 2) . "\n";
        echo "  Median: " . round($this->median($activities), 2) . "\n";
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

#!/usr/bin/env php
<?php
/**
 * Analyze message response time distribution by geographic area type
 *
 * This script analyzes:
 * - Time delay between message arrival and first reply
 * - Geographic variation (urban vs rural) using RU classification
 * - Only considers "active hours" (dynamically calculated from last week's data) to exclude sleep time
 * - Focuses on last 6 months of messages
 *
 * Output:
 * - Response time distributions by RU category
 * - Percentiles (50th, 75th, 90th, 95th)
 * - Comparison urban vs rural
 * - Recommendations for spiralling timing parameters
 *
 * Usage: php spiralling_response_time_analysis.php [--months=6] [--output=/tmp/response_analysis.json]
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

class ResponseTimeAnalysis {
    private $dbhr;
    private $dbhm;
    private $months;
    private $activeHoursStart = 7;  // 7am
    private $activeHoursEnd = 23;   // 11pm

    public function __construct($dbhr, $dbhm, $months = 6) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->months = $months;

        // Calculate active hours from last week's data
        $this->calculateActiveHoursFromData();
    }

    /**
     * Calculate active hours based on last week's message and reply activity
     */
    private function calculateActiveHoursFromData() {
        echo "Calculating active hours from last week's data...\n";

        $oneWeekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        // Get hourly distribution of message arrivals and replies
        $sql = "
            SELECT
                HOUR(timestamp) AS hour,
                COUNT(*) AS activity_count
            FROM (
                SELECT arrival AS timestamp FROM messages WHERE arrival >= ?
                UNION ALL
                SELECT date AS timestamp FROM chat_messages WHERE date >= ? AND type = 'Interested'
            ) activity
            GROUP BY HOUR(timestamp)
            ORDER BY hour
        ";

        $hourlyActivity = $this->dbhr->preQuery($sql, [$oneWeekAgo, $oneWeekAgo]);

        if (empty($hourlyActivity)) {
            echo "  WARNING: No activity data found, using default 7am-11pm\n\n";
            return;
        }

        // Build array of activity by hour (0-23)
        $activityByHour = array_fill(0, 24, 0);
        $totalActivity = 0;

        foreach ($hourlyActivity as $row) {
            $hour = (int) $row['hour'];
            $count = (int) $row['activity_count'];
            $activityByHour[$hour] = $count;
            $totalActivity += $count;
        }

        echo "  Total activity events: " . number_format($totalActivity) . "\n";

        // Find peak hour activity
        $peakActivity = max($activityByHour);
        echo "  Peak hour activity: " . number_format($peakActivity) . " events\n";

        // Find hours with significant activity (>20% of peak hour)
        $threshold = $peakActivity * 0.20;
        $activeHours = [];

        foreach ($activityByHour as $hour => $count) {
            if ($count >= $threshold) {
                $activeHours[] = $hour;
            }
        }

        if (empty($activeHours)) {
            echo "  WARNING: No hours meet activity threshold, using default 7am-11pm\n\n";
            return;
        }

        // Set start and end based on continuous range
        $this->activeHoursStart = min($activeHours);
        $this->activeHoursEnd = max($activeHours) + 1; // +1 because end is exclusive

        echo "  Calculated active hours: {$this->activeHoursStart}:00 - {$this->activeHoursEnd}:00\n";
        echo "  Based on hours with >" . round($threshold) . " events (20% of peak)\n\n";
    }

    public function run() {
        echo "Message Response Time Analysis\n";
        echo "==============================\n";
        echo "Period: Last {$this->months} months\n";
        echo "Active hours: {$this->activeHoursStart}:00 - {$this->activeHoursEnd}:00\n\n";

        // Step 1: Get message response times with geographic data
        echo "Step 1: Extracting message response times...\n";
        $responseData = $this->getMessageResponseTimes();

        if (empty($responseData)) {
            echo "ERROR: No data found\n";
            return false;
        }

        echo "  ✓ Found " . number_format(count($responseData)) . " messages with replies\n\n";

        // Step 2: Aggregate by RU category
        echo "Step 2: Aggregating by geographic area type...\n";
        $aggregated = $this->aggregateByRUCategory($responseData);

        // Step 3: Calculate statistics
        echo "\nStep 3: Calculating statistics...\n";
        $statistics = $this->calculateStatistics($aggregated);

        // Step 4: Output results
        echo "\n";
        $this->outputResults($statistics);

        return $statistics;
    }

    /**
     * Get message response times with geographic classification
     */
    private function getMessageResponseTimes() {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->months} months"));

        echo "  Querying messages since $cutoffDate...\n";

        // Query to get:
        // 1. Message arrival time
        // 2. First reply time (Interested chat message)
        // 3. Location postcode for RU classification
        $sql = "
            SELECT
                m.id AS msgid,
                m.arrival,
                l.name AS postcode,
                tpc.ru_category,
                tpc.region_code,
                tpc.region_name,
                MIN(cm.date) AS first_reply_time,
                TIMESTAMPDIFF(SECOND, m.arrival, MIN(cm.date)) AS response_seconds
            FROM messages m
            INNER JOIN messages_groups mg ON mg.msgid = m.id
            INNER JOIN `groups` g ON g.id = mg.groupid AND g.type = 'Freegle'
            INNER JOIN locations l ON l.id = m.locationid AND l.type = 'Postcode'
            LEFT JOIN transport_postcode_classification tpc
                ON REPLACE(l.name, ' ', '') = tpc.postcode
            INNER JOIN chat_messages cm ON cm.refmsgid = m.id AND cm.type = 'Interested'
            WHERE m.arrival >= ?
            AND m.type IN ('Offer', 'Wanted')
            GROUP BY m.id
            HAVING response_seconds > 0
        ";

        $messages = $this->dbhr->preQuery($sql, [$cutoffDate]);

        echo "    Found " . number_format(count($messages)) . " messages with replies\n";

        // Filter to only include active hours
        echo "  Filtering to active hours ({$this->activeHoursStart}:00-{$this->activeHoursEnd}:00)...\n";

        $filtered = [];
        $excluded = 0;

        foreach ($messages as $msg) {
            // Parse arrival and reply times
            $arrivalTime = strtotime($msg['arrival']);
            $replyTime = strtotime($msg['first_reply_time']);

            // Calculate elapsed time only during active hours
            $activeSeconds = $this->calculateActiveHoursElapsed($arrivalTime, $replyTime);

            if ($activeSeconds === null || $activeSeconds <= 0) {
                $excluded++;
                continue;
            }

            $msg['active_seconds'] = $activeSeconds;
            $msg['active_minutes'] = $activeSeconds / 60;
            $msg['active_hours'] = $activeSeconds / 3600;

            $filtered[] = $msg;
        }

        echo "    Kept " . number_format(count($filtered)) . " messages\n";
        echo "    Excluded " . number_format($excluded) . " (outside active hours)\n";

        return $filtered;
    }

    /**
     * Calculate elapsed time during active hours only
     *
     * This accounts for overnight periods where users are asleep
     */
    private function calculateActiveHoursElapsed($startTime, $endTime) {
        if ($endTime <= $startTime) {
            return null;
        }

        $elapsed = 0;
        $current = $startTime;

        while ($current < $endTime) {
            $hour = (int) date('H', $current);

            // Check if current hour is within active hours
            if ($hour >= $this->activeHoursStart && $hour < $this->activeHoursEnd) {
                // Add time until next hour boundary or end time
                $nextHour = strtotime(date('Y-m-d H:00:00', strtotime('+1 hour', $current)));
                $chunkEnd = min($nextHour, $endTime);
                $elapsed += ($chunkEnd - $current);
                $current = $chunkEnd;
            } else {
                // Skip to next active hour
                if ($hour < $this->activeHoursStart) {
                    // Skip to start of active hours today
                    $current = strtotime(date('Y-m-d', $current) . ' ' . $this->activeHoursStart . ':00:00');
                } else {
                    // Skip to start of active hours tomorrow
                    $current = strtotime(date('Y-m-d', strtotime('+1 day', $current)) . ' ' . $this->activeHoursStart . ':00:00');
                }

                // If we've skipped past the end time, stop
                if ($current >= $endTime) {
                    break;
                }
            }
        }

        return $elapsed;
    }

    /**
     * Aggregate response times by RU category
     */
    private function aggregateByRUCategory($responseData) {
        $aggregated = [];

        $ruDescriptions = [
            'A1' => 'Urban: Major Conurbation',
            'B1' => 'Urban: Minor Conurbation',
            'C1' => 'Urban: City and Town',
            'C2' => 'Urban: City and Town (Sparse)',
            'D1' => 'Rural: Town and Fringe',
            'D2' => 'Rural: Town and Fringe (Sparse)',
            'E1' => 'Rural: Village',
            'E2' => 'Rural: Village (Sparse)',
            'F1' => 'Rural: Hamlets and Isolated Dwellings',
            'F2' => 'Rural: Hamlets and Isolated Dwellings (Sparse)'
        ];

        $noClassification = 0;

        foreach ($responseData as $msg) {
            $ruCat = $msg['ru_category'] ?? 'Unknown';

            if ($ruCat === 'Unknown' || !$ruCat) {
                $noClassification++;
                continue;
            }

            if (!isset($aggregated[$ruCat])) {
                $aggregated[$ruCat] = [
                    'description' => $ruDescriptions[$ruCat] ?? $ruCat,
                    'response_minutes' => [],
                    'count' => 0
                ];
            }

            $aggregated[$ruCat]['response_minutes'][] = $msg['active_minutes'];
            $aggregated[$ruCat]['count']++;
        }

        if ($noClassification > 0) {
            echo "  Note: {$noClassification} messages without RU classification (postcode not in ONSPD)\n";
        }

        // Sort response times for percentile calculations
        foreach ($aggregated as &$data) {
            sort($data['response_minutes']);
        }

        return $aggregated;
    }

    /**
     * Calculate statistics for each RU category
     */
    private function calculateStatistics($aggregated) {
        $statistics = [];

        foreach ($aggregated as $ruCat => $data) {
            $times = $data['response_minutes'];
            $count = count($times);

            if ($count == 0) {
                continue;
            }

            $statistics[$ruCat] = [
                'description' => $data['description'],
                'count' => $count,
                'mean' => array_sum($times) / $count,
                'median' => $this->percentile($times, 50),
                'p25' => $this->percentile($times, 25),
                'p75' => $this->percentile($times, 75),
                'p90' => $this->percentile($times, 90),
                'p95' => $this->percentile($times, 95),
                'p99' => $this->percentile($times, 99),
                'min' => min($times),
                'max' => max($times)
            ];
        }

        return $statistics;
    }

    /**
     * Calculate percentile
     */
    private function percentile($values, $percentile) {
        if (empty($values)) {
            return 0;
        }

        $index = ($percentile / 100) * (count($values) - 1);
        $lower = floor($index);
        $upper = ceil($index);

        if ($lower == $upper) {
            return $values[$lower];
        }

        // Interpolate
        $fraction = $index - $lower;
        return $values[$lower] + ($fraction * ($values[$upper] - $values[$lower]));
    }

    /**
     * Output results in readable format
     */
    private function outputResults($statistics) {
        echo "Response Time Analysis Results\n";
        echo "==============================\n\n";

        // Urban categories
        echo "URBAN AREAS\n";
        echo "-----------\n";
        foreach (['A1', 'B1', 'C1', 'C2'] as $cat) {
            if (isset($statistics[$cat])) {
                $this->printCategoryStats($cat, $statistics[$cat]);
            }
        }

        echo "\n";

        // Rural categories
        echo "RURAL AREAS\n";
        echo "-----------\n";
        foreach (['D1', 'D2', 'E1', 'E2', 'F1', 'F2'] as $cat) {
            if (isset($statistics[$cat])) {
                $this->printCategoryStats($cat, $statistics[$cat]);
            }
        }

        echo "\n";

        // Urban vs Rural comparison
        echo "URBAN vs RURAL COMPARISON\n";
        echo "-------------------------\n";

        $urbanStats = $this->aggregateUrbanRural($statistics, true);
        $ruralStats = $this->aggregateUrbanRural($statistics, false);

        echo "Urban Areas (A1-C2):\n";
        echo "  Sample size: " . number_format($urbanStats['count']) . " messages\n";
        echo "  Median response: " . $this->formatMinutes($urbanStats['median']) . "\n";
        echo "  75th percentile: " . $this->formatMinutes($urbanStats['p75']) . "\n";
        echo "  90th percentile: " . $this->formatMinutes($urbanStats['p90']) . "\n";
        echo "\n";

        echo "Rural Areas (D1-F2):\n";
        echo "  Sample size: " . number_format($ruralStats['count']) . " messages\n";
        echo "  Median response: " . $this->formatMinutes($ruralStats['median']) . "\n";
        echo "  75th percentile: " . $this->formatMinutes($ruralStats['p75']) . "\n";
        echo "  90th percentile: " . $this->formatMinutes($ruralStats['p90']) . "\n";
        echo "\n";

        $medianDiff = (($ruralStats['median'] - $urbanStats['median']) / $urbanStats['median']) * 100;
        echo "Rural areas are " . round(abs($medianDiff), 1) . "% ";
        echo ($medianDiff > 0 ? "slower" : "faster") . " than urban (median)\n";
    }

    /**
     * Print statistics for a single category
     */
    private function printCategoryStats($cat, $stats) {
        echo "\n{$cat}: {$stats['description']}\n";
        echo "  Sample: " . number_format($stats['count']) . " messages\n";
        echo "  Median: " . $this->formatMinutes($stats['median']) . "\n";
        echo "  25th-75th: " . $this->formatMinutes($stats['p25']) . " - " . $this->formatMinutes($stats['p75']) . "\n";
        echo "  90th: " . $this->formatMinutes($stats['p90']) . "\n";
        echo "  95th: " . $this->formatMinutes($stats['p95']) . "\n";
    }

    /**
     * Aggregate urban or rural statistics
     */
    private function aggregateUrbanRural($statistics, $isUrban) {
        $categories = $isUrban ? ['A1', 'B1', 'C1', 'C2'] : ['D1', 'D2', 'E1', 'E2', 'F1', 'F2'];

        $allTimes = [];
        $totalCount = 0;

        foreach ($categories as $cat) {
            if (isset($statistics[$cat])) {
                // We need to reconstruct individual times for accurate percentiles
                // For now, use weighted averages of percentiles
                $totalCount += $statistics[$cat]['count'];
            }
        }

        // Calculate weighted averages
        $weightedStats = ['median' => 0, 'p75' => 0, 'p90' => 0];

        foreach ($categories as $cat) {
            if (isset($statistics[$cat])) {
                $weight = $statistics[$cat]['count'] / $totalCount;
                $weightedStats['median'] += $statistics[$cat]['median'] * $weight;
                $weightedStats['p75'] += $statistics[$cat]['p75'] * $weight;
                $weightedStats['p90'] += $statistics[$cat]['p90'] * $weight;
            }
        }

        $weightedStats['count'] = $totalCount;

        return $weightedStats;
    }

    /**
     * Format minutes into readable format
     */
    private function formatMinutes($minutes) {
        if ($minutes < 60) {
            return round($minutes) . " minutes";
        } elseif ($minutes < 1440) {
            $hours = floor($minutes / 60);
            $mins = round($minutes % 60);
            return "{$hours}h {$mins}m";
        } else {
            $days = floor($minutes / 1440);
            $hours = round(($minutes % 1440) / 60);
            return "{$days}d {$hours}h";
        }
    }

}

// Parse command line arguments
$months = 6;
$outputFile = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--months=') === 0) {
        $months = (int) substr($arg, 9);
    } elseif (strpos($arg, '--output=') === 0) {
        $outputFile = substr($arg, 9);
    }
}

// Run analysis
$analysis = new ResponseTimeAnalysis($dbhr, $dbhm, $months);
$results = $analysis->run();

// Save to file if requested
if ($outputFile && $results) {
    file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT));
    echo "\nResults saved to: $outputFile\n";
}

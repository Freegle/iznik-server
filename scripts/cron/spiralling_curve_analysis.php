#!/usr/bin/env php
<?php
/**
 * Analyze geographic distribution of repliers and takers to optimize spiralling curve
 *
 * This script analyzes:
 * 1. Distance/travel time from message to first replier (for initial isochrone sizing)
 * 2. Distance/travel time from message to taker (for curve optimization)
 * 3. Time elapsed when taker replied (for 2-day constraint)
 * 4. User density at different isochrone radii (for step sizing)
 *
 * Constraints to satisfy:
 * - Initial isochrone should include first replier 90% of the time
 * - Should reach taker within 2 days for 90% of taken messages
 * - Steps should align with response time patterns
 *
 * Output:
 * - Distance percentiles for first repliers
 * - Distance percentiles for takers
 * - Time-to-distance correlation
 * - Recommended curve parameters
 *
 * Usage: php spiralling_curve_analysis.php [--months=6] [--output=/tmp/curve_analysis.json]
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

class SpirallingCurveAnalysis {
    private $dbhr;
    private $dbhm;
    private $months;

    public function __construct($dbhr, $dbhm, $months = 6) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->months = $months;
    }

    public function run() {
        echo "Spiralling Curve Analysis\n";
        echo "=========================\n";
        echo "Period: Last {$this->months} months\n\n";

        // Step 1: Analyze first repliers
        echo "Step 1: Analyzing first replier distances...\n";
        $firstReplierData = $this->analyzeFirstRepliers();

        // Step 2: Analyze takers
        echo "\nStep 2: Analyzing taker distances and timing...\n";
        $takerData = $this->analyzeTakers();

        // Step 3: Generate curve recommendations
        echo "\nStep 3: Generating curve recommendations...\n";
        $recommendations = $this->generateCurveRecommendations($firstReplierData, $takerData);

        // Output results
        $this->outputResults($firstReplierData, $takerData, $recommendations);

        return [
            'first_repliers' => $firstReplierData,
            'takers' => $takerData,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Analyze geographic distance from message to first replier
     */
    private function analyzeFirstRepliers() {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->months} months"));

        echo "  Querying messages with replies since $cutoffDate...\n";

        // Get messages with their first replier's location
        $sql = "
            SELECT
                m.id AS msgid,
                m.locationid AS msg_locationid,
                msg_loc.lat AS msg_lat,
                msg_loc.lng AS msg_lng,
                msg_loc.name AS msg_postcode,
                u.id AS replier_userid,
                user_loc.lat AS replier_lat,
                user_loc.lng AS replier_lng,
                user_loc.name AS replier_postcode,
                cm.date AS reply_time,
                TIMESTAMPDIFF(SECOND, m.arrival, cm.date) AS reply_seconds
            FROM messages m
            INNER JOIN messages_groups mg ON mg.msgid = m.id
            INNER JOIN `groups` g ON g.id = mg.groupid AND g.type = 'Freegle'
            INNER JOIN locations msg_loc ON msg_loc.id = m.locationid AND msg_loc.type = 'Postcode'
            INNER JOIN (
                SELECT refmsgid, userid, MIN(date) AS first_reply_time
                FROM chat_messages
                WHERE type = 'Interested'
                GROUP BY refmsgid
            ) first_replies ON first_replies.refmsgid = m.id
            INNER JOIN chat_messages cm ON cm.refmsgid = m.id
                AND cm.userid = first_replies.userid
                AND cm.date = first_replies.first_reply_time
            INNER JOIN users u ON u.id = cm.userid
            LEFT JOIN locations user_loc ON user_loc.id = u.locationid AND user_loc.type = 'Postcode'
            WHERE m.arrival >= ?
            AND m.type IN ('Offer', 'Wanted')
            AND msg_loc.lat IS NOT NULL
            AND msg_loc.lng IS NOT NULL
            AND user_loc.lat IS NOT NULL
            AND user_loc.lng IS NOT NULL
        ";

        $messages = $this->dbhr->preQuery($sql, [$cutoffDate]);

        echo "  Found " . number_format(count($messages)) . " messages with first replier locations\n";

        // Calculate distances
        $distances = [];
        $distancesByTime = [];

        foreach ($messages as $msg) {
            $distance = $this->haversineDistance(
                $msg['msg_lat'],
                $msg['msg_lng'],
                $msg['replier_lat'],
                $msg['replier_lng']
            );

            $distances[] = $distance;

            $replyMinutes = $msg['reply_seconds'] / 60;
            $distancesByTime[] = [
                'distance_km' => $distance,
                'reply_minutes' => $replyMinutes
            ];
        }

        sort($distances);

        echo "  Calculated distances for " . number_format(count($distances)) . " first repliers\n";

        return [
            'count' => count($distances),
            'distances' => $distances,
            'distances_by_time' => $distancesByTime,
            'percentiles' => [
                'p50' => $this->percentile($distances, 50),
                'p75' => $this->percentile($distances, 75),
                'p90' => $this->percentile($distances, 90),
                'p95' => $this->percentile($distances, 95),
                'p99' => $this->percentile($distances, 99)
            ]
        ];
    }

    /**
     * Analyze geographic distance from message to taker and timing
     */
    private function analyzeTakers() {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->months} months"));

        echo "  Querying taken messages since $cutoffDate...\n";

        // Get messages that were taken with taker's location
        $sql = "
            SELECT
                m.id AS msgid,
                m.locationid AS msg_locationid,
                msg_loc.lat AS msg_lat,
                msg_loc.lng AS msg_lng,
                msg_loc.name AS msg_postcode,
                m.arrival,
                mo.timestamp AS taken_time,
                u.id AS taker_userid,
                user_loc.lat AS taker_lat,
                user_loc.lng AS taker_lng,
                user_loc.name AS taker_postcode,
                TIMESTAMPDIFF(SECOND, m.arrival, mo.timestamp) AS time_to_take_seconds
            FROM messages m
            INNER JOIN messages_groups mg ON mg.msgid = m.id
            INNER JOIN `groups` g ON g.id = mg.groupid AND g.type = 'Freegle'
            INNER JOIN locations msg_loc ON msg_loc.id = m.locationid AND msg_loc.type = 'Postcode'
            INNER JOIN messages_outcomes mo ON mo.msgid = m.id AND mo.outcome = 'Taken'
            INNER JOIN users u ON u.id = mo.userid
            LEFT JOIN locations user_loc ON user_loc.id = u.locationid AND user_loc.type = 'Postcode'
            WHERE m.arrival >= ?
            AND m.type IN ('Offer', 'Wanted')
            AND msg_loc.lat IS NOT NULL
            AND msg_loc.lng IS NOT NULL
            AND user_loc.lat IS NOT NULL
            AND user_loc.lng IS NOT NULL
        ";

        $messages = $this->dbhr->preQuery($sql, [$cutoffDate]);

        echo "  Found " . number_format(count($messages)) . " taken messages with taker locations\n";

        // Calculate distances and timing
        $distances = [];
        $distancesByTime = [];
        $within2Days = [];

        foreach ($messages as $msg) {
            $distance = $this->haversineDistance(
                $msg['msg_lat'],
                $msg['msg_lng'],
                $msg['taker_lat'],
                $msg['taker_lng']
            );

            $distances[] = $distance;

            $takeMinutes = $msg['time_to_take_seconds'] / 60;
            $takeHours = $takeMinutes / 60;
            $takeDays = $takeHours / 24;

            $distancesByTime[] = [
                'distance_km' => $distance,
                'take_minutes' => $takeMinutes,
                'take_hours' => $takeHours,
                'take_days' => $takeDays
            ];

            if ($takeDays <= 2) {
                $within2Days[] = $distance;
            }
        }

        sort($distances);
        sort($within2Days);

        echo "  Calculated distances for " . number_format(count($distances)) . " takers\n";
        echo "  " . number_format(count($within2Days)) . " taken within 2 days (" . round((count($within2Days) / count($distances)) * 100, 1) . "%)\n";

        return [
            'count' => count($distances),
            'distances' => $distances,
            'distances_by_time' => $distancesByTime,
            'within_2days_count' => count($within2Days),
            'within_2days_distances' => $within2Days,
            'percentiles' => [
                'p50' => $this->percentile($distances, 50),
                'p75' => $this->percentile($distances, 75),
                'p90' => $this->percentile($distances, 90),
                'p95' => $this->percentile($distances, 95),
                'p99' => $this->percentile($distances, 99)
            ],
            'within_2days_percentiles' => [
                'p50' => $this->percentile($within2Days, 50),
                'p75' => $this->percentile($within2Days, 75),
                'p90' => $this->percentile($within2Days, 90),
                'p95' => $this->percentile($within2Days, 95),
                'p99' => $this->percentile($within2Days, 99)
            ]
        ];
    }

    /**
     * Generate curve recommendations based on analysis
     */
    private function generateCurveRecommendations($firstReplierData, $takerData) {
        $recommendations = [];

        // Constraint 1: Initial isochrone should include first replier 90% of time
        $initialDistance = $firstReplierData['percentiles']['p90'];
        $recommendations['initial_isochrone_km'] = round($initialDistance, 1);

        // Constraint 2: Should reach taker within 2 days for 90% of cases
        if (!empty($takerData['within_2days_distances'])) {
            $taker2DayDistance = $takerData['within_2days_percentiles']['p90'];
            $recommendations['max_isochrone_2day_km'] = round($taker2DayDistance, 1);
        }

        // Calculate expansion steps
        // Start at p90 of first repliers, expand to p90 of 2-day takers
        $steps = [];

        // Step 1: Initial (includes 90% of first repliers)
        $steps[] = [
            'step' => 0,
            'distance_km' => round($initialDistance, 1),
            'timing' => 'immediate',
            'reason' => '90% of first repliers'
        ];

        // Step 2: Intermediate expansion (median of takers)
        if (isset($takerData['percentiles']['p50'])) {
            $steps[] = [
                'step' => 1,
                'distance_km' => round($takerData['percentiles']['p50'], 1),
                'timing' => 'after initial response period',
                'reason' => '50% of all takers'
            ];
        }

        // Step 3: Maximum 2-day expansion (90% of 2-day takers)
        if (isset($recommendations['max_isochrone_2day_km'])) {
            $steps[] = [
                'step' => 2,
                'distance_km' => $recommendations['max_isochrone_2day_km'],
                'timing' => 'within 2 days',
                'reason' => '90% of takers within 2 days'
            ];
        }

        $recommendations['expansion_steps'] = $steps;

        return $recommendations;
    }

    /**
     * Calculate haversine distance between two lat/lng points in kilometers
     */
    private function haversineDistance($lat1, $lng1, $lat2, $lng2) {
        $earthRadius = 6371; // km

        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;

        $a = sin($dlat / 2) * sin($dlat / 2) +
             cos($lat1) * cos($lat2) *
             sin($dlng / 2) * sin($dlng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
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

        $fraction = $index - $lower;
        return $values[$lower] + ($fraction * ($values[$upper] - $values[$lower]));
    }

    /**
     * Output results
     */
    private function outputResults($firstReplierData, $takerData, $recommendations) {
        echo "\n";
        echo "Analysis Results\n";
        echo "================\n\n";

        echo "FIRST REPLIER DISTANCES\n";
        echo "-----------------------\n";
        echo "Sample: " . number_format($firstReplierData['count']) . " messages\n";
        echo "Distance percentiles (km):\n";
        echo "  50th (median): " . round($firstReplierData['percentiles']['p50'], 1) . " km\n";
        echo "  75th: " . round($firstReplierData['percentiles']['p75'], 1) . " km\n";
        echo "  90th: " . round($firstReplierData['percentiles']['p90'], 1) . " km\n";
        echo "  95th: " . round($firstReplierData['percentiles']['p95'], 1) . " km\n";
        echo "  99th: " . round($firstReplierData['percentiles']['p99'], 1) . " km\n";
        echo "\n";

        echo "TAKER DISTANCES\n";
        echo "---------------\n";
        echo "Sample: " . number_format($takerData['count']) . " taken messages\n";
        echo "Within 2 days: " . number_format($takerData['within_2days_count']) . " (" .
             round(($takerData['within_2days_count'] / $takerData['count']) * 100, 1) . "%)\n";
        echo "\nAll takers - Distance percentiles (km):\n";
        echo "  50th (median): " . round($takerData['percentiles']['p50'], 1) . " km\n";
        echo "  75th: " . round($takerData['percentiles']['p75'], 1) . " km\n";
        echo "  90th: " . round($takerData['percentiles']['p90'], 1) . " km\n";
        echo "  95th: " . round($takerData['percentiles']['p95'], 1) . " km\n";
        echo "\nTakers within 2 days - Distance percentiles (km):\n";
        echo "  50th (median): " . round($takerData['within_2days_percentiles']['p50'], 1) . " km\n";
        echo "  75th: " . round($takerData['within_2days_percentiles']['p75'], 1) . " km\n";
        echo "  90th: " . round($takerData['within_2days_percentiles']['p90'], 1) . " km\n";
        echo "  95th: " . round($takerData['within_2days_percentiles']['p95'], 1) . " km\n";
        echo "\n";

        echo "CURVE RECOMMENDATIONS\n";
        echo "=====================\n\n";

        echo "Constraint 1: Initial isochrone should include first replier 90% of time\n";
        echo "  → Initial isochrone: " . $recommendations['initial_isochrone_km'] . " km\n\n";

        echo "Constraint 2: Should reach taker within 2 days for 90% of taken messages\n";
        if (isset($recommendations['max_isochrone_2day_km'])) {
            echo "  → Maximum isochrone: " . $recommendations['max_isochrone_2day_km'] . " km\n\n";
        }

        echo "Recommended Expansion Steps:\n";
        foreach ($recommendations['expansion_steps'] as $step) {
            echo "  Step {$step['step']}: {$step['distance_km']} km ({$step['timing']}) - {$step['reason']}\n";
        }
        echo "\n";

        echo "Next Steps:\n";
        echo "1. Convert these km distances to isochrone travel times (walk/cycle/drive)\n";
        echo "2. Determine timing for each expansion step based on response time analysis\n";
        echo "3. Analyze user density to determine minimum users per expansion\n";
        echo "4. Test curve with historical data to validate 90% constraints\n";
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
$analysis = new SpirallingCurveAnalysis($dbhr, $dbhm, $months);
$results = $analysis->run();

// Save to file if requested
if ($outputFile && $results) {
    file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT));
    echo "\nResults saved to: $outputFile\n";
}

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

/**
 * Bootstrap Thompson Sampling Parameters for Message Expansion Timing
 *
 * BACKGROUND - THE SPIRALLING PROBLEM:
 * When a Freegle message (Offer/Wanted) is posted, it's initially visible only to nearby groups.
 * If no one responds, the message "spirals out" to progressively more distant groups to find
 * a match. The key question is: how long should we wait before expanding?
 *
 * - Expand too early: We spam distant users unnecessarily when a local match would have happened
 * - Expand too late: The item sits unclaimed, frustrating the poster and reducing success rates
 *
 * The optimal wait time varies by context:
 * - Urban areas have more users, so responses come faster
 * - Evening posts get faster responses than 3am posts
 * - Weekends behave differently from weekdays
 *
 * WHAT THIS SCRIPT DOES:
 * Analyzes a year of historical message data to learn optimal expansion timing for each
 * combination of context factors (group density, time of day, day type, message type).
 *
 * HOW THOMPSON SAMPLING WORKS:
 * Thompson Sampling is a Bayesian approach to the multi-armed bandit problem. Imagine
 * several slot machines (arms) with unknown payout rates - we want to find the best one
 * while minimizing losses from pulling bad arms.
 *
 * For each context (e.g., "urban morning weekday Offer"), we have multiple "arms":
 * - Wait 1 hour before expanding
 * - Wait 2 hours before expanding
 * - Wait 4 hours before expanding
 * - etc.
 *
 * We model each arm's success probability using a Beta distribution:
 * - Beta(alpha, beta) where alpha = successes + 1, beta = failures + 1
 * - Starting with Beta(1,1) = uniform prior (we know nothing)
 * - Each historical message updates the counts: success increments alpha, failure increments beta
 *
 * After processing all historical data, each arm has learned parameters that represent
 * the empirical success rate. At runtime, we can either:
 * - Pick the arm with highest expected value (exploitation)
 * - Sample from Beta distributions and pick highest sample (exploration + exploitation)
 *
 * OUTPUT:
 * JSON files containing Beta parameters for each context+arm combination, plus a simplified
 * lookup table mapping context -> recommended expansion timing.
 *
 * Usage:
 *   php spiralling_bootstrap_thompson_sampling.php [options]
 *
 * Options:
 *   --start DATE       Start date (default: 1 year ago)
 *   --end DATE         End date (default: 7 days ago)
 *   --output-dir PATH  Output directory (default: /tmp/thompson_sampling)
 *   --limit N          Limit messages (for testing)
 *   --help             Show help
 */

class ThompsonSamplingBootstrapper {
    private $dbhr;
    private $dbhm;
    private $startDate;
    private $endDate;
    private $outputDir;
    private $limit;

    /**
     * Main data structures built during analysis:
     *
     * $contextBetaParams: Nested array storing Beta distribution parameters
     *   [context_hash => [
     *       'context' => [...],           // The context factors (group_type, time, etc.)
     *       'arms' => [                   // Each expansion timing option
     *           '1h' => ['alpha' => N, 'beta' => M],  // Beta params for 1-hour wait
     *           '2h' => [...],
     *           ...
     *       ],
     *       'total_observations' => N     // How many messages contributed to this context
     *   ]]
     *
     * $responseTimeDistribution: Raw response times for statistical analysis
     * $groupClassification: Cache of group -> density classification
     */
    private $contextBetaParams = [];
    private $responseTimeDistribution = [];
    private $groupClassification = [];
    private $stats = [];

    /**
     * The "arms" of our multi-armed bandit - different expansion timing strategies.
     * Each arm represents "wait N hours before expanding to more groups".
     *
     * We test multiple strategies simultaneously on historical data to learn which
     * performs best in each context. The NULL value for 'next_active' means
     * "wait until the next active period (8am-8pm)" rather than a fixed duration.
     */
    private $expansionArms = [
        '1h' => 1,
        '2h' => 2,
        '4h' => 4,
        '6h' => 6,
        '8h' => 8,
        'next_active' => NULL  // Special: wait until next active period (8am-8pm)
    ];

    /**
     * Define "active hours" when users are likely to be checking Freegle.
     * Messages posted outside these hours may benefit from waiting until
     * the next active period rather than expanding immediately.
     */
    private $activePeriodStart = 8;   // 8am
    private $activePeriodEnd = 20;    // 8pm

    public function __construct($dbhr, $dbhm, $options = []) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->startDate = $options['startDate'] ?? date('Y-m-d', strtotime('-1 year'));
        $this->endDate = $options['endDate'] ?? date('Y-m-d', strtotime('-7 days'));
        $this->outputDir = $options['outputDir'] ?? '/tmp/thompson_sampling';
        $this->limit = $options['limit'] ?? NULL;
    }

    public function run() {
        echo "\n=== Thompson Sampling Bootstrap ===\n";
        echo "Date range: {$this->startDate} to {$this->endDate}\n";
        echo "Output dir: {$this->outputDir}\n\n";

        // Create output directory
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, TRUE);
        }

        // Get all messages with responses (this query can take a while for large date ranges)
        echo "Fetching messages from database (this may take a few minutes)...\n";
        flush();
        $messages = $this->getMessagesWithResponses();
        $totalMessages = count($messages);

        echo "Found $totalMessages messages to analyze\n\n";

        if ($totalMessages == 0) {
            echo "No messages found. Exiting.\n";
            return;
        }

        // Process each message
        $processedCount = 0;
        $skippedCount = 0;

        foreach ($messages as $index => $msg) {
            $msgNum = $index + 1;

            if ($msgNum % 1000 == 0 || $msgNum == $totalMessages) {
                echo "[$msgNum/$totalMessages] Processing... (contexts: " . count($this->contextBetaParams) . ")\n";
            }

            try {
                $result = $this->processMessage($msg);
                if ($result) {
                    $processedCount++;
                } else {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                echo "  Error processing message {$msg['id']}: " . $e->getMessage() . "\n";
                $skippedCount++;
            }
        }

        echo "\n=== Bootstrap Complete ===\n";
        echo "Processed: $processedCount messages\n";
        echo "Skipped: $skippedCount messages\n";
        echo "Context buckets: " . count($this->contextBetaParams) . "\n\n";

        // Store stats
        $this->stats = [
            'total_messages' => $processedCount,
            'skipped_messages' => $skippedCount,
            'total_contexts' => count($this->contextBetaParams),
            'date_range' => ['start' => $this->startDate, 'end' => $this->endDate],
            'generated_at' => date('Y-m-d H:i:s')
        ];

        // Show summary and save results
        $this->showSummary();
        $this->saveResults();
    }

    /**
     * Fetch all messages within the date range along with their response data.
     *
     * For each message, we retrieve:
     * - Basic message info (arrival time, type, location)
     * - Group info (for density classification)
     * - First response time (when someone first expressed interest)
     * - Response count (total number of interested parties)
     * - Taker IDs (who actually received/took the item, if known)
     * - Outcome (Taken/Received/Withdrawn/Expired)
     *
     * This gives us everything needed to evaluate "would this message have succeeded
     * if we had expanded after N hours?"
     */
    private function getMessagesWithResponses() {
        $sql = "SELECT DISTINCT
                    m.id,
                    m.arrival,
                    m.type,
                    m.subject,
                    m.lat,
                    m.lng,
                    m.locationid,
                    mg.groupid,
                    g.nameshort as group_name,
                    g.region,
                    (SELECT MIN(cm.date) FROM chat_messages cm
                     WHERE cm.refmsgid = m.id AND cm.type = 'Interested') as first_response,
                    (SELECT COUNT(*) FROM chat_messages cm
                     WHERE cm.refmsgid = m.id AND cm.type = 'Interested') as response_count,
                    (SELECT GROUP_CONCAT(DISTINCT mb.userid) FROM messages_by mb
                     WHERE mb.msgid = m.id) as taker_ids,
                    (SELECT mo.outcome FROM messages_outcomes mo
                     WHERE mo.msgid = m.id ORDER BY mo.timestamp DESC LIMIT 1) as outcome
                FROM messages m
                INNER JOIN messages_groups mg ON m.id = mg.msgid
                INNER JOIN `groups` g ON mg.groupid = g.id
                WHERE m.arrival >= ?
                  AND m.arrival <= ?
                  AND m.type IN ('Offer', 'Wanted')
                  AND m.deleted IS NULL
                  AND g.type = 'Freegle'
                  AND g.publish = 1
                ORDER BY m.arrival ASC";

        $params = [$this->startDate, $this->endDate];

        if ($this->limit) {
            $sql .= " LIMIT " . intval($this->limit);
        }

        return $this->dbhr->preQuery($sql, $params);
    }

    /**
     * Process a single historical message to update our Beta distributions.
     *
     * For each message, we:
     * 1. Determine the context (group density, time of day, weekday/weekend, Offer/Wanted)
     * 2. Calculate when responses actually arrived (first response, taker response)
     * 3. For each expansion arm, ask: "If we had expanded after N hours, would this
     *    message have succeeded?" (i.e., did a response arrive before expansion?)
     * 4. Update the Beta(alpha, beta) parameters for each arm:
     *    - Success (response before expansion): increment alpha
     *    - Failure (no response before expansion): increment beta
     *
     * The key insight: we're not asking "did this message succeed?" but rather
     * "for each possible expansion timing, would waiting that long have been enough?"
     */
    private function processMessage($msg) {
        // Classify the group by population density (urban_dense, suburban, rural, etc.)
        $groupType = $this->getGroupType($msg['groupid'], $msg['region']);

        // Extract temporal context from the message arrival time
        $arrivalTime = strtotime($msg['arrival']);
        $arrivalHour = intval(date('G', $arrivalTime));
        $arrivalDow = intval(date('w', $arrivalTime));  // 0=Sunday, 6=Saturday
        $timeBucket = $this->getTimeBucket($arrivalHour);  // morning/afternoon/evening/night
        $dayType = ($arrivalDow == 0 || $arrivalDow == 6) ? 'weekend' : 'weekday';

        // Calculate when the first response arrived (minutes after posting)
        $firstResponseMinutes = NULL;
        $takerResponseMinutes = NULL;

        if ($msg['first_response']) {
            $firstResponseTime = strtotime($msg['first_response']);
            $firstResponseMinutes = ($firstResponseTime - $arrivalTime) / 60;

            // Store for response time distribution analysis (used in summary stats)
            $this->responseTimeDistribution[] = [
                'minutes' => $firstResponseMinutes,
                'group_type' => $groupType,
                'time_bucket' => $timeBucket,
                'day_type' => $dayType,
                'is_taker' => FALSE
            ];
        }

        // If we know who actually took/received the item, find when THEY responded.
        // This is more valuable than first response - the taker is the "winning" responder.
        if ($msg['taker_ids']) {
            $takerIds = explode(',', $msg['taker_ids']);
            $takerResponseMinutes = $this->getTakerResponseTime($msg['id'], $takerIds, $arrivalTime);

            if ($takerResponseMinutes !== NULL) {
                $this->responseTimeDistribution[] = [
                    'minutes' => $takerResponseMinutes,
                    'group_type' => $groupType,
                    'time_bucket' => $timeBucket,
                    'day_type' => $dayType,
                    'is_taker' => TRUE
                ];
            }
        }

        // Build the context key - this determines which "bucket" of Beta params we update.
        // Messages with similar contexts should have similar optimal expansion timings.
        $context = [
            'group_type' => $groupType,      // e.g., 'urban_dense', 'rural_sparse'
            'time_bucket' => $timeBucket,    // e.g., 'morning', 'evening'
            'day_type' => $dayType,          // 'weekday' or 'weekend'
            'msg_type' => $msg['type'],      // 'Offer' or 'Wanted'
            'response_level' => 'none'       // At posting time, no responses yet
        ];
        $contextHash = $this->hashContext($context);

        // This is the core learning step: update Beta distributions for each arm
        $this->updateBetaDistributions($context, $contextHash, $msg, $arrivalTime, $firstResponseMinutes, $takerResponseMinutes);

        return TRUE;
    }

    /**
     * Classify a group by population density.
     *
     * We use geographic area as a proxy for population density - smaller groups
     * tend to be in denser urban areas. This affects response times:
     * - Urban areas: More potential responders nearby, faster responses expected
     * - Rural areas: Fewer nearby users, may need to expand sooner to find matches
     *
     * Results are cached since the same group appears in many messages.
     */
    private function getGroupType($groupId, $region) {
        if (isset($this->groupClassification[$groupId])) {
            return $this->groupClassification[$groupId];
        }

        $groupData = $this->dbhr->preQuery("
            SELECT g.id, g.region,
                   ST_Area(polyindex) as area_units
            FROM `groups` g
            WHERE g.id = ?
        ", [$groupId]);

        $areaKm2 = 100;  // Default assumption
        if (count($groupData) > 0 && $groupData[0]['area_units']) {
            // Convert from spatial units to approximate km² (depends on SRID)
            $areaKm2 = floatval($groupData[0]['area_units']) / 1000000000;
        }

        $groupType = $this->classifyGroupByRegionAndArea($region, $areaKm2);

        $this->groupClassification[$groupId] = $groupType;

        return $groupType;
    }

    /**
     * Map region and geographic area to a density classification.
     *
     * The thresholds are empirically chosen based on UK Freegle group patterns:
     * - London groups are always dense regardless of stated area
     * - Area > 1000 km²: Very rural, sparse population (e.g., Scottish Highlands)
     * - Area > 500 km²: Rural with villages
     * - Area > 200 km²: Suburban/small town
     * - Area > 50 km²: Urban but not central
     * - Area <= 50 km²: Dense urban (city centers)
     */
    private function classifyGroupByRegionAndArea($region, $areaKm2) {
        if ($region === 'London') {
            return 'urban_dense';
        }

        if ($areaKm2 > 1000) {
            return 'rural_sparse';
        }
        if ($areaKm2 > 500) {
            return 'rural_village';
        }
        if ($areaKm2 > 200) {
            return 'suburban';
        }
        if ($areaKm2 > 50) {
            return 'urban_moderate';
        }

        return 'urban_dense';
    }

    /**
     * Bucket the hour of day into time periods.
     *
     * Response patterns vary throughout the day - evening posts often get
     * quick responses (people browsing after work), while night posts may
     * sit until morning.
     */
    private function getTimeBucket($hour) {
        if ($hour >= 6 && $hour < 12) {
            return 'morning';    // 6am-12pm: People checking before/during work
        }
        if ($hour >= 12 && $hour < 17) {
            return 'afternoon';  // 12pm-5pm: Lunch break and afternoon activity
        }
        if ($hour >= 17 && $hour < 21) {
            return 'evening';    // 5pm-9pm: Peak browsing time (after work)
        }
        return 'night';          // 9pm-6am: Low activity, responses delayed until morning
    }

    /**
     * Find when the actual taker first responded to this message.
     *
     * The "taker" is the person who ultimately received the item (for Offers)
     * or fulfilled the request (for Wanteds). Their response time is more
     * meaningful than the first random response, because it represents
     * when the successful match actually happened.
     *
     * Returns minutes from message arrival to taker's first "Interested" message,
     * or NULL if we can't determine it.
     */
    private function getTakerResponseTime($msgId, $takerIds, $arrivalTime) {
        $placeholders = implode(',', array_fill(0, count($takerIds), '?'));

        $sql = "SELECT MIN(date) as first_taker_response
                FROM chat_messages
                WHERE refmsgid = ?
                  AND type = 'Interested'
                  AND userid IN ($placeholders)";

        $params = array_merge([$msgId], $takerIds);
        $result = $this->dbhr->preQuery($sql, $params);

        if (count($result) > 0 && $result[0]['first_taker_response']) {
            $responseTime = strtotime($result[0]['first_taker_response']);
            return ($responseTime - $arrivalTime) / 60;
        }

        return NULL;
    }

    /**
     * Update Beta distribution parameters for all arms based on one message's outcome.
     *
     * This is the core Bayesian learning step. For each expansion timing arm, we ask:
     * "If we had used this arm (waited N hours before expanding), would we consider
     * this message a success?"
     *
     * The Beta distribution is conjugate to the Bernoulli likelihood, meaning:
     * - Prior: Beta(alpha, beta)
     * - After observing success: Beta(alpha+1, beta)
     * - After observing failure: Beta(alpha, beta+1)
     *
     * We start with Beta(1,1) which is uniform (no prior knowledge), and each
     * observation updates the distribution to reflect empirical success rates.
     *
     * After processing all messages, the expected success rate for an arm is:
     *   E[success_rate] = alpha / (alpha + beta)
     */
    private function updateBetaDistributions($context, $contextHash, $msg, $arrivalTime, $firstResponseMinutes, $takerResponseMinutes) {
        // Initialize this context if we haven't seen it before
        if (!isset($this->contextBetaParams[$contextHash])) {
            $this->contextBetaParams[$contextHash] = [
                'context' => $context,
                'arms' => [],
                'total_observations' => 0
            ];

            // Start with uninformative prior Beta(1,1) for each arm
            foreach ($this->expansionArms as $armId => $hours) {
                $this->contextBetaParams[$contextHash]['arms'][$armId] = [
                    'alpha' => 1,  // pseudo-count for successes
                    'beta' => 1   // pseudo-count for failures
                ];
            }
        }

        // Evaluate each arm against this message's actual response timeline
        foreach ($this->expansionArms as $armId => $waitHours) {
            $expansionMinutes = $this->getExpansionMinutes($waitHours, $arrivalTime);

            // Would this arm have been "successful" for this message?
            $success = $this->evaluateArmSuccess($expansionMinutes, $firstResponseMinutes, $takerResponseMinutes, $msg);

            // Bayesian update: success -> increment alpha, failure -> increment beta
            if ($success) {
                $this->contextBetaParams[$contextHash]['arms'][$armId]['alpha']++;
            } else {
                $this->contextBetaParams[$contextHash]['arms'][$armId]['beta']++;
            }
        }

        $this->contextBetaParams[$contextHash]['total_observations']++;
    }

    /**
     * Convert an arm's wait time to minutes.
     *
     * For fixed-hour arms (1h, 2h, etc.), this is simple multiplication.
     * For the 'next_active' arm, we calculate minutes until the next active period.
     */
    private function getExpansionMinutes($waitHours, $arrivalTime) {
        if ($waitHours === NULL) {
            // 'next_active' arm: wait until next 8am-8pm window
            return $this->minutesToNextActivePeriod($arrivalTime);
        }
        return $waitHours * 60;
    }

    /**
     * Calculate minutes from arrival time until the next active period (8am-8pm).
     *
     * If the message arrives during active hours, returns 0 (expand immediately).
     * If it arrives at night, returns minutes until 8am the next morning.
     *
     * This arm tests the hypothesis that night-time messages should simply wait
     * until morning rather than expanding into the void when no one's looking.
     */
    private function minutesToNextActivePeriod($arrivalTime) {
        $hour = intval(date('G', $arrivalTime));

        // Already in active period - no waiting needed
        if ($hour >= $this->activePeriodStart && $hour < $this->activePeriodEnd) {
            return 0;
        }

        // Calculate hours until next 8am
        if ($hour >= $this->activePeriodEnd) {
            // Evening (8pm-midnight): wait until tomorrow 8am
            $hoursUntil8am = (24 - $hour) + $this->activePeriodStart;
        } else {
            // Early morning (midnight-8am): wait until today 8am
            $hoursUntil8am = $this->activePeriodStart - $hour;
        }

        return $hoursUntil8am * 60;
    }

    /**
     * Determine if an arm would be considered "successful" for this message.
     *
     * An arm is successful if the message got a meaningful response BEFORE the
     * expansion would have happened. The intuition:
     * - If taker responded in 30 minutes, and this arm waits 4 hours, that's a success
     *   (we would have found the match without needing to expand)
     * - If taker responded in 6 hours, and this arm waits 4 hours, that's a failure
     *   (we would have expanded unnecessarily, and the taker might have been local anyway)
     *
     * We add a 1-hour buffer because:
     * - Users need time to see notifications
     * - Even if expansion happens at hour 4, a response at hour 4.5 is still "close enough"
     *
     * Priority of evidence:
     * 1. Taker response time (strongest signal - this is who actually got the item)
     * 2. First response time (weaker signal - any interest before expansion)
     * 3. Outcome only (weakest - Taken/Received with no tracked response = partial success)
     */
    private function evaluateArmSuccess($expansionMinutes, $firstResponseMinutes, $takerResponseMinutes, $msg) {
        // Buffer for notification delivery + user reaction time
        $buffer = 60;  // 1 hour grace period

        // Best evidence: when did the actual taker respond?
        if ($takerResponseMinutes !== NULL) {
            return $takerResponseMinutes <= ($expansionMinutes + $buffer);
        }

        // Second-best: when did anyone first show interest?
        if ($firstResponseMinutes !== NULL) {
            return $firstResponseMinutes <= ($expansionMinutes + $buffer);
        }

        // No tracked responses - fall back to outcome
        $outcome = $msg['outcome'] ?? 'Unknown';

        // If item was taken/received despite no tracked responses, count as success
        // (This happens with off-platform arrangements or old data)
        if (in_array($outcome, ['Taken', 'Received'])) {
            return TRUE;
        }

        // No response data and no successful outcome = failure
        return FALSE;
    }

    /**
     * Create a deterministic hash for a context.
     *
     * We sort keys first to ensure the same context always produces the same hash,
     * regardless of the order keys were added to the array.
     */
    private function hashContext($context) {
        ksort($context);
        return hash('sha256', json_encode($context));
    }

    /**
     * Display summary statistics after processing all messages.
     *
     * Shows:
     * - Message counts by group type
     * - Response time percentiles (P10, P25, P50, P75, P90, P95)
     * - Best expansion timing for each high-volume context
     */
    private function showSummary() {
        echo "\n=== Summary Statistics ===\n\n";

        // Messages by group type
        $groupTypeCounts = [];
        foreach ($this->contextBetaParams as $hash => $data) {
            $gt = $data['context']['group_type'];
            if (!isset($groupTypeCounts[$gt])) {
                $groupTypeCounts[$gt] = 0;
            }
            $groupTypeCounts[$gt] += $data['total_observations'];
        }

        echo "Messages by group type:\n";
        arsort($groupTypeCounts);
        foreach ($groupTypeCounts as $type => $count) {
            echo "  $type: $count\n";
        }

        // Response time percentiles
        echo "\nResponse time distribution (minutes):\n";
        $responseTimes = array_column($this->responseTimeDistribution, 'minutes');
        sort($responseTimes);
        $count = count($responseTimes);

        if ($count > 0) {
            $percentiles = [10, 25, 50, 75, 90, 95];
            foreach ($percentiles as $p) {
                $idx = intval($count * $p / 100);
                $idx = min($idx, $count - 1);
                $minutes = $responseTimes[$idx];
                $hours = round($minutes / 60, 1);
                echo "  P$p: " . round($minutes) . " min ({$hours} hours)\n";
            }
        }

        // Best arm by context (for high-observation contexts)
        echo "\nBest expansion timing by context:\n";
        foreach ($this->contextBetaParams as $hash => $data) {
            if ($data['total_observations'] < 50) {
                continue;
            }

            $bestArm = NULL;
            $bestRate = 0;

            $rates = [];
            foreach ($data['arms'] as $armId => $params) {
                $rate = $params['alpha'] / ($params['alpha'] + $params['beta']);
                $rates[$armId] = round($rate * 100, 1);
                if ($rate > $bestRate) {
                    $bestRate = $rate;
                    $bestArm = $armId;
                }
            }

            $ctx = $data['context'];
            echo "  {$ctx['group_type']}/{$ctx['time_bucket']}/{$ctx['day_type']}/{$ctx['msg_type']}: ";
            echo "best=$bestArm (1h:{$rates['1h']}%, 2h:{$rates['2h']}%, 4h:{$rates['4h']}%) ";
            echo "[n={$data['total_observations']}]\n";
        }
    }

    /**
     * Save all results to JSON files.
     *
     * Output files:
     * - beta_params.json: Full Beta distribution parameters for all contexts/arms
     *   (used for Thompson Sampling at runtime)
     * - response_times.json: Raw response time data for further analysis
     * - stats.json: Summary statistics about the bootstrap run
     * - lookup_table.json: Simplified mapping of context -> best arm (for quick lookups)
     */
    private function saveResults() {
        // Primary output: Beta parameters for Thompson Sampling
        $betaPath = $this->outputDir . '/beta_params.json';
        file_put_contents($betaPath, json_encode($this->contextBetaParams, JSON_PRETTY_PRINT));
        echo "\nBeta parameters saved to: $betaPath\n";

        // Response times for statistical analysis
        $responsePath = $this->outputDir . '/response_times.json';
        file_put_contents($responsePath, json_encode($this->responseTimeDistribution));
        echo "Response times saved to: $responsePath\n";

        // Run metadata
        $statsPath = $this->outputDir . '/stats.json';
        file_put_contents($statsPath, json_encode($this->stats, JSON_PRETTY_PRINT));
        echo "Stats saved to: $statsPath\n";

        // Simplified lookup for runtime (if not using full Thompson Sampling)
        $lookupTable = $this->generateLookupTable();
        $lookupPath = $this->outputDir . '/lookup_table.json';
        file_put_contents($lookupPath, json_encode($lookupTable, JSON_PRETTY_PRINT));
        echo "Lookup table saved to: $lookupPath\n";
    }

    /**
     * Generate a simplified lookup table for runtime use.
     *
     * The full Beta parameters allow Thompson Sampling (probabilistic arm selection),
     * but for simpler deployments, this lookup table provides:
     * - The single best arm for each context (highest expected success rate)
     * - Success rates for all arms (for logging/debugging)
     * - Observation counts (to identify low-confidence contexts)
     *
     * At runtime, you can either:
     * 1. Simple: Look up context, use best_arm directly
     * 2. Full Thompson: Load beta_params.json, sample from Beta distributions, pick highest
     */
    private function generateLookupTable() {
        $lookup = [];

        foreach ($this->contextBetaParams as $hash => $data) {
            $ctx = $data['context'];
            $key = "{$ctx['group_type']}_{$ctx['time_bucket']}_{$ctx['day_type']}_{$ctx['msg_type']}";

            $bestArm = '4h';  // Sensible default if calculation fails
            $bestRate = 0;
            $armRates = [];

            // Calculate expected success rate for each arm: E[rate] = alpha / (alpha + beta)
            foreach ($data['arms'] as $armId => $params) {
                $rate = $params['alpha'] / ($params['alpha'] + $params['beta']);
                $armRates[$armId] = round($rate, 4);
                if ($rate > $bestRate) {
                    $bestRate = $rate;
                    $bestArm = $armId;
                }
            }

            $lookup[$key] = [
                'best_arm' => $bestArm,
                'best_rate' => round($bestRate, 4),
                'arm_rates' => $armRates,
                'observations' => $data['total_observations'],
                'context' => $ctx
            ];
        }

        return $lookup;
    }
}

// Parse command line arguments
$options = getopt('', [
    'start:',
    'end:',
    'output-dir:',
    'limit:',
    'help'
]);

if (isset($options['help'])) {
    echo "Usage: php spiralling_bootstrap_thompson_sampling.php [options]\n\n";
    echo "Bootstrap Thompson Sampling parameters from historical Freegle data.\n\n";
    echo "This script analyzes past messages and responses to learn optimal\n";
    echo "expansion timing strategies for different contexts (group type, time of day,\n";
    echo "day of week, message type). Results are stored in JSON files for runtime use.\n\n";
    echo "Options:\n";
    echo "  --start DATE       Start date (default: 1 year ago)\n";
    echo "  --end DATE         End date (default: 7 days ago)\n";
    echo "  --output-dir PATH  Output directory (default: /tmp/thompson_sampling)\n";
    echo "  --limit N          Limit total messages (for testing)\n";
    echo "  --help             Show this help\n\n";
    echo "Example:\n";
    echo "  php spiralling_bootstrap_thompson_sampling.php --start 2024-01-01 --end 2024-12-31\n";
    echo "  php spiralling_bootstrap_thompson_sampling.php --limit 10000 --output-dir /tmp/test\n\n";
    exit(0);
}

$bootstrapperOptions = [];

if (isset($options['start'])) {
    $bootstrapperOptions['startDate'] = $options['start'];
}

if (isset($options['end'])) {
    $bootstrapperOptions['endDate'] = $options['end'];
}

if (isset($options['output-dir'])) {
    $bootstrapperOptions['outputDir'] = $options['output-dir'];
}

if (isset($options['limit'])) {
    $bootstrapperOptions['limit'] = intval($options['limit']);
}

// Run bootstrapper
$bootstrapper = new ThompsonSamplingBootstrapper($dbhr, $dbhm, $bootstrapperOptions);
$bootstrapper->run();

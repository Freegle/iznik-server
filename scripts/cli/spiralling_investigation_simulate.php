<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

/**
 * Simulate message isochrone expansion using historical data
 *
 * This script analyzes historical messages to determine optimal isochrone expansion parameters.
 * It simulates the arrival of replies over time and tracks when isochrones would have been expanded.
 *
 * IMPORTANT: By default, this simulator ONLY uses cached isochrones from the database.
 * It will NOT fetch new isochrones from Mapbox. Messages are skipped if required isochrones
 * are not already cached in the isochrones table.
 *
 * However, when an OpenRouteService (ORS) server is specified via --ors-server parameter,
 * the simulator will create new ORS isochrones on demand and store them in the database
 * with source='ORS'.
 */

class MessageIsochroneSimulator {
    private $dbhr;
    private $dbhm;
    private $runId = NULL;
    private $startDate;
    private $endDate;
    private $groupIds = [];
    private $limit = NULL;
    private $orsServer = NULL;

    // Simulation parameters to test
    private $params = [
        'initialMinutes' => 5,
        'maxMinutes' => 60,
        'increment' => 1,
        'minUsers' => 100,  // Minimum users to include in initial isochrone / add per expansion
        'activeSince' => 90,
        'transport' => 'car',
        'timeSinceLastExpand' => 60,  // minutes
        'numReplies' => 7  // Stop expanding once we have this many replies (we have enough interest)
    ];

    public function __construct($dbhr, $dbhm, $options = []) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        // Set date range - default to 30-7 days ago
        $this->startDate = $options['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
        $this->endDate = $options['endDate'] ?? date('Y-m-d', strtotime('-7 days'));
        $this->groupIds = $options['groupIds'] ?? [];
        $this->limit = $options['limit'] ?? NULL;
        $this->orsServer = $options['orsServer'] ?? NULL;

        // Override default parameters if provided
        if (isset($options['params'])) {
            $this->params = array_merge($this->params, $options['params']);
        }

        // If using ORS and transport is 'walk', automatically use 'cycle' instead
        if ($this->orsServer && strtolower($this->params['transport']) === 'walk') {
            $this->params['transport'] = 'cycle';
        }
    }

    public function run($name = NULL, $description = NULL) {
        echo "\n=== Message Isochrone Simulation ===\n";
        echo "Date range: {$this->startDate} to {$this->endDate}\n";
        echo "Isochrone source: " . ($this->orsServer ? "ORS ({$this->orsServer})" : "Mapbox (cached only)") . "\n";

        // Test ORS connectivity if specified
        if ($this->orsServer) {
            echo "Testing ORS server connectivity... ";
            if (!$this->testORSConnectivity()) {
                echo "FAILED\n";
                echo "ERROR: Cannot connect to ORS server at {$this->orsServer}\n";
                echo "Please check that the server is running and accessible.\n";
                return;
            }
            echo "OK\n";
        }

        if (count($this->groupIds) > 0) {
            echo "Group filter: " . implode(', ', $this->groupIds) . "\n";
        }
        if ($this->limit) {
            if (count($this->groupIds) > 1) {
                echo "Message limit: {$this->limit} per group (" . (count($this->groupIds) * $this->limit) . " total max)\n";
            } elseif (count($this->groupIds) == 0) {
                echo "Message limit: {$this->limit} per group (applied to all groups in date range)\n";
            } else {
                echo "Message limit: {$this->limit}\n";
            }
        }
        echo "Parameters: " . json_encode($this->params, JSON_PRETTY_PRINT) . "\n\n";

        // Create simulation run
        $this->createRun($name, $description);

        // Get messages to simulate
        $messages = $this->getMessages();
        $totalMessages = count($messages);

        echo "Found $totalMessages messages to simulate\n\n";

        if ($totalMessages == 0) {
            echo "No messages found for simulation. Exiting.\n";
            $this->markRunFailed();
            return;
        }

        $this->dbhm->preExec("UPDATE simulation_message_isochrones_runs SET status = 'running', message_count = ? WHERE id = ?", [
            $totalMessages,
            $this->runId
        ]);

        // Process each message
        $allMetrics = [];
        foreach ($messages as $index => $msg) {
            $msgNum = $index + 1;
            echo "[$msgNum/$totalMessages] Processing message {$msg['id']}: {$msg['subject']}\n";

            try {
                $metrics = $this->simulateMessage($msg, $index);
                if ($metrics) {
                    $allMetrics[] = $metrics;
                    echo "  ✓ Complete - Replies: {$metrics['total_replies']}, Final users reached: {$metrics['final_users_reached']}\n";
                } else {
                    echo "  ⚠ Skipped - no location or no active users\n";
                }
            } catch (\Exception $e) {
                echo "  ✗ Error: " . $e->getMessage() . "\n";
            }

            echo "\n";
        }

        // Calculate aggregate metrics
        echo "Calculating aggregate metrics...\n";
        $aggregateMetrics = $this->calculateAggregateMetrics($allMetrics);

        // Update run with completion
        $this->dbhm->preExec("UPDATE simulation_message_isochrones_runs SET
            status = 'completed',
            completed = NOW(),
            metrics = ?
            WHERE id = ?", [
            json_encode($aggregateMetrics),
            $this->runId
        ]);

        echo "\n=== Simulation Complete ===\n";
        echo "Run ID: {$this->runId}\n";
        echo "Messages processed: " . count($allMetrics) . "\n\n";

        echo "Aggregate metrics:\n";
        echo json_encode($aggregateMetrics, JSON_PRETTY_PRINT) . "\n\n";

        echo "Metric Definitions:\n";
        echo "- messages_analyzed: Total messages across ALL runs with these same parameters\n";
        echo "- messages_in_this_run: Number of messages processed in this run only\n";
        echo "- median_replies: Median number of replies per message (across all runs)\n";
        echo "- median_users_reached: Median users reached by final isochrones (across all runs)\n";
        echo "- median_capture_rate: Median % of replies from within final isochrones\n";
        echo "  (Higher is better - shows we're targeting the right areas)\n";
        echo "- mean_capture_rate: Average % of replies from within final isochrones\n";
        echo "- median_efficiency: Median % of notified users who actually replied\n";
        echo "  (Higher is better - shows we're not over-notifying)\n";
        echo "- mean_efficiency: Average % of notified users who replied\n";
        echo "- median_expansions: Median number of isochrone expansions per message\n";
        echo "- mean_expansions: Average number of expansions per message\n";
        echo "- messages_with_taker: Number of messages where we know who took the item\n";
        echo "- takers_reached_pct: % of takers who were within the final isochrone\n";
        echo "- takers_in_initial_pct: % of takers who were in the initial (smallest) isochrone\n";
        echo "- median_taker_reach_time: Median minutes until taker would be reached\n";
        echo "  (Lower is better - shows spiral expansion doesn't delay reaching taker)\n";
    }

    private function createRun($name, $description) {
        $filters = [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'groupids' => $this->groupIds,
            'limit' => $this->limit
        ];

        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_runs
            (name, description, parameters, filters, status) VALUES (?, ?, ?, ?, 'pending')", [
            $name ?? 'Simulation ' . date('Y-m-d H:i:s'),
            $description,
            json_encode($this->params),
            json_encode($filters)
        ]);

        $this->runId = $this->dbhm->lastInsertId();
        echo "Created simulation run {$this->runId}\n";
    }

    private function markRunFailed() {
        $this->dbhm->preExec("UPDATE simulation_message_isochrones_runs SET status = 'failed' WHERE id = ?", [
            $this->runId
        ]);
    }

    private function testORSConnectivity() {
        // Test basic connectivity with the ORS health check endpoint
        $url = rtrim($this->orsServer, '/') . '/v2/health';

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            error_log("ORS connectivity test failed: $curlError");
            return FALSE;
        }

        // Accept any 2xx response
        if ($httpCode >= 200 && $httpCode < 300) {
            return TRUE;
        }

        error_log("ORS connectivity test failed: HTTP $httpCode for URL: $url");
        return FALSE;
    }

    private function mapTransportToEnum($transport) {
        // Map simulation transport modes to Isochrone enum values
        switch (strtolower($transport)) {
            case 'walk':
            case 'walking':
                return Isochrone::WALK;
            case 'cycle':
            case 'cycling':
            case 'bike':
                return Isochrone::CYCLE;
            case 'car':
            case 'drive':
            case 'driving':
                return Isochrone::DRIVE;
            default:
                return NULL;
        }
    }

    private function getMessages() {
        // If limit is specified, apply it per group (even when no groups specified)
        if ($this->limit) {
            return $this->getMessagesPerGroup();
        }

        // No limit - use simple query
        $sql = "SELECT DISTINCT messages.id, messages.arrival, messages.subject, messages.locationid,
                       messages.lat, messages.lng, messages.fromuser, messages_groups.groupid,
                       `groups`.nameshort
                FROM messages
                INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                INNER JOIN `groups` ON messages_groups.groupid = `groups`.id
                LEFT JOIN simulation_message_isochrones_messages simm ON messages.id = simm.msgid AND simm.runid = ?
                WHERE messages.arrival >= ?
                  AND messages.arrival <= ?
                  AND messages.type IN ('Offer', 'Wanted')
                  AND messages.deleted IS NULL
                  AND messages.locationid IS NOT NULL
                  AND simm.id IS NULL";

        $params = [$this->runId, $this->startDate, $this->endDate];

        if (count($this->groupIds) > 0) {
            // Use IN clause for multiple groups
            $placeholders = implode(',', array_fill(0, count($this->groupIds), '?'));
            $sql .= " AND messages_groups.groupid IN ($placeholders)";
            $params = array_merge($params, $this->groupIds);
        }

        $sql .= " ORDER BY messages.arrival ASC";

        return $this->dbhr->preQuery($sql, $params);
    }

    private function getMessagesPerGroup() {
        // Fetch messages from each group separately, then interleave them
        // This gives a better distribution when viewing the simulation
        $messagesByGroup = [];

        // If no specific groups specified, get all groups with messages in the date range
        $groupsToProcess = $this->groupIds;
        if (count($groupsToProcess) == 0) {
            $groupsToProcess = $this->getGroupsWithMessages();
            echo "DEBUG: Found " . count($groupsToProcess) . " groups with messages\n";
        } else {
            echo "DEBUG: Processing " . count($groupsToProcess) . " specified groups\n";
        }

        foreach ($groupsToProcess as $groupId) {
            $sql = "SELECT DISTINCT messages.id, messages.arrival, messages.subject, messages.locationid,
                           messages.lat, messages.lng, messages.fromuser, messages_groups.groupid,
                           `groups`.nameshort
                    FROM messages
                    INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                    INNER JOIN `groups` ON messages_groups.groupid = `groups`.id
                    LEFT JOIN simulation_message_isochrones_messages simm ON messages.id = simm.msgid AND simm.runid = ?
                    WHERE messages.arrival >= ?
                      AND messages.arrival <= ?
                      AND messages.type IN ('Offer', 'Wanted')
                      AND messages.deleted IS NULL
                      AND messages.locationid IS NOT NULL
                      AND simm.id IS NULL
                      AND messages_groups.groupid = ?
                    ORDER BY messages.arrival ASC
                    LIMIT " . intval($this->limit);

            $params = [$this->runId, $this->startDate, $this->endDate, $groupId];
            $messagesByGroup[$groupId] = $this->dbhr->preQuery($sql, $params);
            echo "DEBUG: Group $groupId returned " . count($messagesByGroup[$groupId]) . " messages\n";
        }

        // Round-robin through groups to interleave messages
        $result = [];
        if (count($messagesByGroup) == 0) {
            return $result;
        }

        $maxMessages = max(array_map('count', $messagesByGroup));
        $seenIds = []; // Track message IDs to avoid duplicates (cross-posted messages)

        for ($i = 0; $i < $maxMessages; $i++) {
            foreach ($groupsToProcess as $groupId) {
                if (isset($messagesByGroup[$groupId][$i])) {
                    $msg = $messagesByGroup[$groupId][$i];
                    // Only add if we haven't seen this message ID before
                    if (!isset($seenIds[$msg['id']])) {
                        $result[] = $msg;
                        $seenIds[$msg['id']] = TRUE;
                    }
                }
            }
        }

        echo "DEBUG: Round-robin produced " . count($result) . " unique messages from " . count($messagesByGroup) . " groups\n";

        return $result;
    }

    private function getGroupsWithMessages() {
        // Get all groups that have messages in the date range
        $sql = "SELECT DISTINCT messages_groups.groupid
                FROM messages
                INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                LEFT JOIN simulation_message_isochrones_messages simm ON messages.id = simm.msgid AND simm.runid = ?
                WHERE messages.arrival >= ?
                  AND messages.arrival <= ?
                  AND messages.type IN ('Offer', 'Wanted')
                  AND messages.deleted IS NULL
                  AND messages.locationid IS NOT NULL
                  AND simm.id IS NULL
                ORDER BY messages_groups.groupid ASC";

        $params = [$this->runId, $this->startDate, $this->endDate];
        $results = $this->dbhr->preQuery($sql, $params);

        return array_map(function($row) {
            return $row['groupid'];
        }, $results);
    }

    private function simulateMessage($msg, $sequence) {
        // Get message location
        if (!$msg['locationid']) {
            return NULL;
        }

        $l = new Location($this->dbhr, $this->dbhm, $msg['locationid']);
        $lat = $msg['lat'] ?? $l->getPrivate('lat');
        $lng = $msg['lng'] ?? $l->getPrivate('lng');

        if (!$lat || !$lng) {
            return NULL;
        }

        // Get group info
        $g = new Group($this->dbhr, $this->dbhm, $msg['groupid']);
        $groupCGA = $this->getGroupCGA($msg['groupid']);

        // Get active users in group (within CGA + 10 miles buffer)
        $activeUsers = $this->getActiveUsersInGroup($msg['groupid'], $lat, $lng);

        if (count($activeUsers) == 0) {
            return NULL;
        }

        // Get actual replies to this message
        $replies = $this->getReplies($msg['id']);

        // Get the taker (person who collected the item)
        $taker = $this->getTaker($msg['id']);

        // Store message data
        $simMsgId = $this->storeMessage($msg, $sequence, $groupCGA, count($activeUsers), count($replies));

        // Store user data
        $this->storeUsers($simMsgId, $activeUsers, $replies);

        // Simulate isochrone expansions
        $expansionResult = $this->simulateExpansions($msg, $lat, $lng, $replies, $activeUsers, $taker);

        // Store expansion data
        $this->storeExpansions($simMsgId, $expansionResult['expansions'], $msg['arrival']);

        // Calculate metrics for this message
        $metrics = $this->calculateMessageMetrics(
            $simMsgId,
            $expansionResult['expansions'],
            $activeUsers,
            $replies,
            $expansionResult['taker_reached_at'],
            $taker
        );

        // Store metrics
        $this->dbhm->preExec("UPDATE simulation_message_isochrones_messages SET metrics = ? WHERE id = ?", [
            json_encode($metrics),
            $simMsgId
        ]);

        return $metrics;
    }

    private function getGroupCGA($groupId) {
        $polygons = $this->dbhr->preQuery("SELECT ST_AsGeoJSON(polyindex) AS geojson FROM `groups` WHERE id = ?", [
            $groupId
        ]);

        if (count($polygons) && $polygons[0]['geojson']) {
            return json_decode($polygons[0]['geojson'], TRUE);
        }

        return NULL;
    }

    private function getActiveUsersInGroup($groupId, $msgLat, $msgLng) {
        // Get active users in the group within 10 miles of message location
        // Using users_approxlocs for privacy
        $activeSince = $this->params['activeSince'];

        $sql = "SELECT DISTINCT
                    users_approxlocs.userid,
                    users_approxlocs.lat,
                    users_approxlocs.lng,
                    ST_Distance_Sphere(
                        POINT(?, ?),
                        POINT(users_approxlocs.lng, users_approxlocs.lat)
                    ) / 1000 AS distance_km
                FROM users_approxlocs
                INNER JOIN memberships ON users_approxlocs.userid = memberships.userid
                WHERE memberships.groupid = ?
                  AND users_approxlocs.timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND ST_Distance_Sphere(
                        POINT(?, ?),
                        POINT(users_approxlocs.lng, users_approxlocs.lat)
                      ) <= 16093.4
                ORDER BY distance_km ASC";

        return $this->dbhr->preQuery($sql, [
            $msgLng, $msgLat,
            $groupId,
            $activeSince,
            $msgLng, $msgLat
        ]);
    }

    private function getReplies($msgId) {
        // Get chat messages showing interest in this message
        $sql = "SELECT chat_messages.userid, chat_messages.date
                FROM chat_messages
                WHERE chat_messages.refmsgid = ?
                  AND chat_messages.type = 'Interested'
                ORDER BY chat_messages.date ASC";

        return $this->dbhr->preQuery($sql, [$msgId]);
    }

    private function getTaker($msgId) {
        // Get the person who eventually took/received the item
        $sql = "SELECT messages_by.userid
                FROM messages_by
                WHERE messages_by.msgid = ?
                LIMIT 1";

        $result = $this->dbhr->preQuery($sql, [$msgId]);
        return count($result) > 0 ? $result[0]['userid'] : NULL;
    }

    private function storeMessage($msg, $sequence, $groupCGA, $totalUsers, $totalReplies) {
        $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_messages
            (runid, msgid, sequence, arrival, subject, locationid, lat, lng, groupid, groupname,
             group_cga_polygon, total_group_users, total_replies_actual)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $this->runId,
            $msg['id'],
            $sequence,
            $msg['arrival'],
            $msg['subject'],
            $msg['locationid'],
            $msg['lat'],
            $msg['lng'],
            $msg['groupid'],
            $msg['nameshort'],
            json_encode($groupCGA),
            $totalUsers,
            $totalReplies
        ]);

        return $this->dbhm->lastInsertId();
    }

    private function storeUsers($simMsgId, $activeUsers, $replies) {
        // Create reply lookup
        $replyLookup = [];
        foreach ($replies as $reply) {
            $replyLookup[$reply['userid']] = $reply['date'];
        }

        // Store each user
        foreach ($activeUsers as $user) {
            $replied = isset($replyLookup[$user['userid']]) ? 1 : 0;
            $replyTime = $replied ? $replyLookup[$user['userid']] : NULL;
            $replyMinutes = NULL;

            if ($replyTime) {
                // Calculate minutes after message arrival (will be set later when we have arrival time)
                $replyMinutes = 0; // Placeholder
            }

            // Use hash for anonymization
            $userHash = hash('sha256', $user['userid'] . '_' . $simMsgId);

            $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_users
                (sim_msgid, user_hash, lat, lng, in_group, replied, reply_time, distance_km)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?)", [
                $simMsgId,
                $userHash,
                $user['lat'],
                $user['lng'],
                $replied,
                $replyTime,
                $user['distance_km']
            ]);
        }

        // Update reply_minutes now that we have the message arrival time
        if (count($replies) > 0) {
            $msgArrival = $this->dbhr->preQuery("SELECT arrival FROM simulation_message_isochrones_messages WHERE id = ?", [
                $simMsgId
            ])[0]['arrival'];

            $this->dbhm->preExec("UPDATE simulation_message_isochrones_users
                SET reply_minutes = TIMESTAMPDIFF(MINUTE, ?, reply_time)
                WHERE sim_msgid = ? AND replied = 1", [
                $msgArrival,
                $simMsgId
            ]);
        }
    }

    private function simulateExpansions($msg, $lat, $lng, $replies, $activeUsers, $takerId) {
        $expansions = [];
        $currentMinutes = $this->params['initialMinutes'];
        $takerReachedAt = NULL;

        // Find taker in active users
        $takerUser = NULL;
        if ($takerId) {
            foreach ($activeUsers as $user) {
                if ($user['userid'] == $takerId) {
                    $takerUser = $user;
                    break;
                }
            }
        }

        // Sort replies by time for efficient counting
        usort($replies, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Convert replies to minutes since arrival for easier comparison
        $arrivalTime = strtotime($msg['arrival']);
        $replyTimesMinutes = [];
        foreach ($replies as $reply) {
            $replyTimesMinutes[] = (strtotime($reply['date']) - $arrivalTime) / 60;
        }

        // Initial isochrone at message arrival (time 0)
        // Expand until we reach minimum number of users or hit maxMinutes
        $isochronePolygon = NULL;
        $usersInIsochrone = 0;

        while ($currentMinutes <= $this->params['maxMinutes']) {
            $isochronePolygon = $this->getIsochronePolygon($msg['locationid'], $currentMinutes, $this->params['transport']);

            // If no isochrone available, skip this message entirely
            // With ORS: only fails if server is down
            // With Mapbox: fails if not already cached
            if ($isochronePolygon === NULL && $currentMinutes === $this->params['initialMinutes']) {
                return ['expansions' => [], 'taker_reached_at' => NULL];
            }

            $usersInIsochrone = $this->countUsersInIsochrone($isochronePolygon, $activeUsers);

            // Stop if we have enough users or can't expand further
            if ($usersInIsochrone >= $this->params['minUsers'] || $currentMinutes >= $this->params['maxMinutes']) {
                break;
            }

            $currentMinutes += $this->params['increment'];
        }

        // Check if taker is in initial isochrone
        if ($takerUser && !$takerReachedAt && $this->pointInPolygon($takerUser['lat'], $takerUser['lng'], $isochronePolygon)) {
            $takerReachedAt = [
                'expansion_index' => 0,
                'minutes_after_arrival' => 0,
                'minutes' => $currentMinutes
            ];
        }

        // Count replies received by time 0 (should be 0)
        $repliesAtTime = $this->countRepliesByTime($replyTimesMinutes, 0);

        $expansions[] = [
            'sequence' => 0,
            'minutes_after_arrival' => 0,
            'minutes' => $currentMinutes,
            'transport' => $this->params['transport'],
            'polygon' => $isochronePolygon,
            'users_in_isochrone' => $usersInIsochrone,
            'new_users_reached' => $usersInIsochrone,
            'replies_at_time' => $repliesAtTime,
            'replies_in_isochrone' => $this->countRepliesInIsochroneByTime($isochronePolygon, $activeUsers, $replies, $arrivalTime, 0)
        ];

        // Expand at variable intervals (fast at first, slower later)
        // Only expand during daytime hours (8am-8pm)
        $currentTime = 0;
        $expansionIndex = 1;
        $maxSimulationTime = 72 * 60; // 3 days in minutes

        while ($currentMinutes < $this->params['maxMinutes'] && $currentTime < $maxSimulationTime) {
            // Calculate next expansion interval based on elapsed time
            $nextInterval = $this->getExpansionInterval($currentTime);

            // Move to next expansion time
            $currentTime += $nextInterval;

            // Skip nighttime hours (8pm-8am)
            $currentTime = $this->skipToNextDaytime($arrivalTime, $currentTime);

            // Check if we've exceeded simulation time
            if ($currentTime >= $maxSimulationTime) {
                break;
            }

            // Check if we have enough replies by this time to stop expanding
            $repliesAtTime = $this->countRepliesByTime($replyTimesMinutes, $currentTime);
            if ($repliesAtTime >= $this->params['numReplies']) {
                // We have enough interest, stop expanding
                break;
            }

            // Expand isochrone until we add minUsers new users or hit maxMinutes
            $prevUsers = end($expansions)['users_in_isochrone'];
            $newUsersAdded = 0;

            while ($currentMinutes < $this->params['maxMinutes']) {
                $currentMinutes += $this->params['increment'];
                $currentMinutes = min($currentMinutes, $this->params['maxMinutes']);

                $isochronePolygon = $this->getIsochronePolygon($msg['locationid'], $currentMinutes, $this->params['transport']);

                // If no isochrone available for this expansion, stop expanding
                // With ORS: only fails if server is down (rare)
                // With Mapbox: fails if not already cached (expected behavior)
                if ($isochronePolygon === NULL) {
                    break;
                }

                $usersInIsochrone = $this->countUsersInIsochrone($isochronePolygon, $activeUsers);
                $newUsersAdded = $usersInIsochrone - $prevUsers;

                // Stop if we've added enough new users or can't expand further
                if ($newUsersAdded >= $this->params['minUsers'] || $currentMinutes >= $this->params['maxMinutes']) {
                    break;
                }
            }

            // Skip this expansion if we couldn't get an isochrone
            if ($isochronePolygon === NULL) {
                break;
            }

            // Check if taker is reached in this expansion
            if ($takerUser && !$takerReachedAt && $this->pointInPolygon($takerUser['lat'], $takerUser['lng'], $isochronePolygon)) {
                $takerReachedAt = [
                    'expansion_index' => $expansionIndex,
                    'minutes_after_arrival' => $currentTime,
                    'minutes' => $currentMinutes
                ];
            }

            $expansions[] = [
                'sequence' => $expansionIndex,
                'minutes_after_arrival' => $currentTime,
                'minutes' => $currentMinutes,
                'transport' => $this->params['transport'],
                'polygon' => $isochronePolygon,
                'users_in_isochrone' => $usersInIsochrone,
                'new_users_reached' => $newUsersAdded,
                'replies_at_time' => $repliesAtTime,
                'replies_in_isochrone' => $this->countRepliesInIsochroneByTime($isochronePolygon, $activeUsers, $replies, $arrivalTime, $currentTime)
            ];

            $expansionIndex++;
        }

        return [
            'expansions' => $expansions,
            'taker_reached_at' => $takerReachedAt
        ];
    }

    /**
     * Get expansion interval in minutes based on elapsed time
     * Slowed down so distance reached in 24 hours is what was previously reached in 4 hours
     * Extends over 3 days (72 hours) total
     */
    private function getExpansionInterval($elapsedMinutes) {
        // Convert to hours for easier readability
        $elapsedHours = $elapsedMinutes / 60;

        // Expansion schedule over 72 hours:
        // 0-12 hours: expand every 4 hours
        // 12-24 hours: expand every 6 hours
        // 24-48 hours: expand every 8 hours
        // 48-72 hours: expand every 8 hours

        if ($elapsedHours < 12) {
            return 240;  // 4 hours
        } else if ($elapsedHours < 24) {
            return 360; // 6 hours
        } else {
            return 480; // 8 hours
        }
    }

    /**
     * Skip to next daytime hour (8am) if currently in nighttime (8pm-8am)
     * Returns adjusted minutes from arrival
     */
    private function skipToNextDaytime($arrivalTimestamp, $minutesFromArrival) {
        // Calculate absolute timestamp
        $currentTimestamp = $arrivalTimestamp + ($minutesFromArrival * 60);

        // Get hour of day (0-23)
        $hour = intval(date('G', $currentTimestamp));

        // If between 8am (8) and 8pm (20), we're in daytime - no adjustment needed
        if ($hour >= 8 && $hour < 20) {
            return $minutesFromArrival;
        }

        // We're in nighttime - skip to next 8am
        $currentDate = getdate($currentTimestamp);

        // If after 8pm today, skip to 8am tomorrow
        if ($hour >= 20) {
            $next8am = mktime(8, 0, 0, $currentDate['mon'], $currentDate['mday'] + 1, $currentDate['year']);
        } else {
            // Before 8am today, skip to 8am today
            $next8am = mktime(8, 0, 0, $currentDate['mon'], $currentDate['mday'], $currentDate['year']);
        }

        // Return new minutes from arrival
        return ($next8am - $arrivalTimestamp) / 60;
    }

    private function countRepliesByTime($replyTimesMinutes, $currentTime) {
        $count = 0;
        foreach ($replyTimesMinutes as $replyTime) {
            if ($replyTime <= $currentTime) {
                $count++;
            }
        }
        return $count;
    }

    private function countRepliesInIsochroneByTime($polygon, $users, $replies, $arrivalTime, $currentTimeMinutes) {
        if (!$polygon || count($replies) == 0) {
            return 0;
        }

        // Create lookup of users who replied by the current time
        $repliedUsersByTime = [];
        foreach ($replies as $reply) {
            $replyTime = strtotime($reply['date']);
            $minutesSinceArrival = ($replyTime - $arrivalTime) / 60;

            // Only count replies that occurred before or at current time
            if ($minutesSinceArrival <= $currentTimeMinutes) {
                $repliedUsersByTime[$reply['userid']] = TRUE;
            }
        }

        // Count how many of those repliers are in the isochrone
        $count = 0;
        foreach ($users as $user) {
            if (isset($repliedUsersByTime[$user['userid']]) &&
                $this->pointInPolygon($user['lat'], $user['lng'], $polygon)) {
                $count++;
            }
        }

        return $count;
    }

    private function getIsochronePolygon($locationId, $minutes, $transport) {
        // Map simulation transport mode to Isochrone class constants
        $transportEnum = $this->mapTransportToEnum($transport);

        $source = $this->orsServer ? 'ORS' : 'Mapbox';
        $transq = $transportEnum ? (" AND transport = " . $this->dbhr->quote($transportEnum)) : " AND transport IS NULL";
        $sourceq = " AND source = " . $this->dbhr->quote($source);

        $existings = $this->dbhr->preQuery("SELECT id FROM isochrones WHERE locationid = ? $transq AND minutes = ? $sourceq ORDER BY timestamp DESC LIMIT 1;", [
            $locationId,
            $minutes
        ]);

        $isochroneId = NULL;

        if (count($existings)) {
            $isochroneId = $existings[0]['id'];
        } elseif ($this->orsServer) {
            $i = new Isochrone($this->dbhr, $this->dbhm);
            $isochroneId = $i->ensureIsochroneExists($locationId, $minutes, $transportEnum, $this->orsServer);

            if (!$isochroneId) {
                echo "  ⚠ ORS server failed to create isochrone for location $locationId, $minutes minutes, $transport\n";
                echo "  Check error_log for details. ORS server may be down or unreachable.\n";
            }
        } else {
            return NULL;
        }

        if (!$isochroneId) {
            return NULL;
        }

        $result = $this->dbhr->preQuery("SELECT ST_AsGeoJSON(polygon) AS geojson FROM isochrones WHERE id = ?", [
            $isochroneId
        ]);

        if (count($result) && $result[0]['geojson']) {
            return json_decode($result[0]['geojson'], TRUE);
        }

        return NULL;
    }

    private function countUsersInIsochrone($polygon, $users) {
        if (!$polygon) {
            return 0;
        }

        $count = 0;
        foreach ($users as $user) {
            if ($this->pointInPolygon($user['lat'], $user['lng'], $polygon)) {
                $count++;
            }
        }

        return $count;
    }

    private function countRepliesInIsochrone($polygon, $users, $replies) {
        if (!$polygon) {
            return 0;
        }

        // Create lookup of users who replied
        $repliedUsers = [];
        foreach ($replies as $reply) {
            $repliedUsers[$reply['userid']] = TRUE;
        }

        $count = 0;
        foreach ($users as $user) {
            if (isset($repliedUsers[$user['userid']]) &&
                $this->pointInPolygon($user['lat'], $user['lng'], $polygon)) {
                $count++;
            }
        }

        return $count;
    }

    private function pointInPolygon($lat, $lng, $polygon) {
        // Simple point-in-polygon check using ray casting
        // Assumes GeoJSON polygon format
        if (!isset($polygon['coordinates']) || !is_array($polygon['coordinates'])) {
            return FALSE;
        }

        $coords = $polygon['coordinates'][0]; // First ring (exterior)
        $inside = FALSE;

        for ($i = 0, $j = count($coords) - 1; $i < count($coords); $j = $i++) {
            $xi = $coords[$i][0]; // lng
            $yi = $coords[$i][1]; // lat
            $xj = $coords[$j][0];
            $yj = $coords[$j][1];

            $intersect = (($yi > $lat) != ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function storeExpansions($simMsgId, $expansions, $msgArrival) {
        foreach ($expansions as $exp) {
            $timestamp = date('Y-m-d H:i:s', strtotime($msgArrival) + ($exp['minutes_after_arrival'] * 60));

            $this->dbhm->preExec("INSERT INTO simulation_message_isochrones_expansions
                (sim_msgid, sequence, timestamp, minutes_after_arrival, minutes, transport,
                 isochrone_polygon, users_in_isochrone, new_users_reached, replies_at_time, replies_in_isochrone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $simMsgId,
                $exp['sequence'],
                $timestamp,
                $exp['minutes_after_arrival'],
                $exp['minutes'],
                $exp['transport'],
                json_encode($exp['polygon']),
                $exp['users_in_isochrone'],
                $exp['new_users_reached'],
                $exp['replies_at_time'],
                $exp['replies_in_isochrone']
            ]);
        }
    }

    private function calculateMessageMetrics($simMsgId, $expansions, $activeUsers, $replies, $takerReachedAt, $takerId) {
        $finalExpansion = end($expansions);

        $metrics = [
            // Total number of users who actually replied to this message
            'total_replies' => count($replies),

            // Total number of active users in the group (within 10 miles) at the time
            'total_active_users' => count($activeUsers),

            // How many users were reached by the initial (smallest) isochrone
            'initial_users_reached' => $expansions[0]['users_in_isochrone'],

            // How many users were reached by the final (largest) isochrone
            'final_users_reached' => $finalExpansion['users_in_isochrone'],

            // Number of times the isochrone was expanded (0 = no expansions, just initial)
            'total_expansions' => count($expansions) - 1,

            // How many of the repliers were within the final isochrone
            'replies_in_final_isochrone' => $finalExpansion['replies_in_isochrone'],

            // Capture rate: % of actual replies that came from users within the final isochrone
            // (Higher is better - shows we're targeting the right area)
            'capture_rate' => count($replies) > 0 ?
                round($finalExpansion['replies_in_isochrone'] / count($replies) * 100, 2) : 0,

            // Efficiency: % of notified users who actually replied
            // (Higher is better - shows we're not over-notifying)
            'efficiency' => $finalExpansion['users_in_isochrone'] > 0 ?
                round($finalExpansion['replies_in_isochrone'] / $finalExpansion['users_in_isochrone'] * 100, 2) : 0,

            // Cost: Total number of notifications that would be sent with this strategy
            'cost_notifications' => $finalExpansion['users_in_isochrone'],

            // Taker metrics: When would the person who eventually took the item have been reached?
            'has_taker' => $takerId ? TRUE : FALSE,
            'taker_reached' => $takerReachedAt ? TRUE : FALSE,
            'taker_reached_at_expansion' => $takerReachedAt ? $takerReachedAt['expansion_index'] : NULL,
            'taker_reached_at_minutes' => $takerReachedAt ? $takerReachedAt['minutes_after_arrival'] : NULL,
            'taker_in_initial_isochrone' => $takerReachedAt && $takerReachedAt['expansion_index'] === 0 ? TRUE : FALSE
        ];

        return $metrics;
    }

    private function calculateAggregateMetrics($allMetrics) {
        if (count($allMetrics) == 0) {
            return [];
        }

        // Get current run's parameters to find all runs with same settings
        $currentRun = $this->dbhr->preQuery("SELECT parameters FROM simulation_message_isochrones_runs WHERE id = ?", [
            $this->runId
        ])[0];

        $currentParams = $currentRun['parameters'];

        // Get all messages from all completed runs with the same parameters
        // Include the current run even if still 'running' since we're calculating its final metrics
        $allMessages = $this->dbhr->preQuery(
            "SELECT m.metrics
             FROM simulation_message_isochrones_messages m
             INNER JOIN simulation_message_isochrones_runs r ON m.runid = r.id
             WHERE r.parameters = ?
               AND (r.status = 'completed' OR r.id = ?)
               AND m.metrics IS NOT NULL",
            [$currentParams, $this->runId]
        );

        $replies = [];
        $usersReached = [];
        $captureRates = [];
        $efficiencies = [];
        $expansions = [];
        $takerTimes = [];
        $messagesWithTaker = 0;
        $takersReached = 0;
        $takersInInitial = 0;

        foreach ($allMessages as $msg) {
            $metrics = json_decode($msg['metrics'], TRUE);
            if ($metrics) {
                $replies[] = $metrics['total_replies'];
                $usersReached[] = $metrics['final_users_reached'];
                $captureRates[] = $metrics['capture_rate'];
                $efficiencies[] = $metrics['efficiency'];
                $expansions[] = $metrics['total_expansions'];

                // Taker statistics
                if ($metrics['has_taker']) {
                    $messagesWithTaker++;
                    if ($metrics['taker_reached']) {
                        $takersReached++;
                        $takerTimes[] = $metrics['taker_reached_at_minutes'];
                        if ($metrics['taker_in_initial_isochrone']) {
                            $takersInInitial++;
                        }
                    }
                }
            }
        }

        $result = [
            'messages_analyzed' => count($allMessages),
            'messages_in_this_run' => count($allMetrics),
            'median_replies' => round($this->median($replies), 2),
            'median_users_reached' => round($this->median($usersReached), 2),
            'median_capture_rate' => round($this->median($captureRates), 2),
            'mean_capture_rate' => round($this->mean($captureRates), 2),
            'median_efficiency' => round($this->median($efficiencies), 2),
            'mean_efficiency' => round($this->mean($efficiencies), 2),
            'median_expansions' => round($this->median($expansions), 2),
            'mean_expansions' => round($this->mean($expansions), 2),
            'messages_with_taker' => $messagesWithTaker,
            'takers_reached_pct' => $messagesWithTaker > 0 ? round(($takersReached / $messagesWithTaker) * 100, 2) : 0,
            'takers_in_initial_pct' => $messagesWithTaker > 0 ? round(($takersInInitial / $messagesWithTaker) * 100, 2) : 0,
            'median_taker_reach_time' => round($this->median($takerTimes), 2)
        ];

        return $result;
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

    private function mean($arr) {
        if (count($arr) == 0) return 0;
        return array_sum($arr) / count($arr);
    }
}

// Parse command line arguments
$options = getopt('', [
    'start:',
    'end:',
    'group:',
    'groups:',
    'limit:',
    'min-users:',
    'name:',
    'description:',
    'ors-server:',
    'help'
]);

// Show usage message if requested
if (isset($options['help'])) {
    echo "Usage: php simulate_message_isochrones.php [options]\n\n";
    echo "Simulates message isochrone expansion using historical data to determine optimal\n";
    echo "notification strategies. Analyzes when replies arrived and tracks when users would\n";
    echo "have been reached by expanding isochrones. Each expansion aims to cover an\n";
    echo "additional minimum number of known active users (configurable via --min-users).\n\n";
    echo "Isochrone Sources:\n";
    echo "  - Without --ors-server: Uses only cached Mapbox isochrones from database\n";
    echo "    Messages are skipped if required isochrones are not already cached\n";
    echo "  - With --ors-server: Creates new ORS isochrones on demand (no cost limit)\n";
    echo "    All messages can be processed as isochrones are generated as needed\n";
    echo "    NOTE: 'walk' transport is automatically converted to 'cycle' for ORS\n\n";
    echo "Options:\n";
    echo "  --start DATE          Start date for message range (default: 30 days ago)\n";
    echo "                        Format: YYYY-MM-DD\n";
    echo "  --end DATE            End date for message range (default: 7 days ago)\n";
    echo "                        Format: YYYY-MM-DD\n";
    echo "  --group ID[,ID...]    Filter to one or more group IDs (comma-separated)\n";
    echo "                        Example: --group 12345 or --group \"12345,67890,11111\"\n";
    echo "  --groups IDS          Same as --group (accepts comma-separated IDs)\n";
    echo "  --limit N             Limit number of messages to process (default: all)\n";
    echo "                        When used with --groups, limit applies PER GROUP\n";
    echo "                        Example: --groups \"1,2,3\" --limit 100 = 300 messages total\n";
    echo "  --min-users N         Minimum users to include/add per expansion (default: 100)\n";
    echo "                        Initial isochrone expands until it reaches this many users\n";
    echo "                        Each subsequent expansion adds at least this many new users\n";
    echo "  --ors-server URL      OpenRouteService server URL (optional)\n";
    echo "                        Example: --ors-server http://localhost:8080/ors\n";
    echo "                        When specified, creates and uses ORS isochrones instead of Mapbox\n";
    echo "  --name NAME           Name for this simulation run (optional)\n";
    echo "  --description TEXT    Description for this run (optional)\n";
    echo "  --help                Show this help message\n\n";
    echo "Examples:\n";
    echo "  # Single group:\n";
    echo "  php simulate_message_isochrones.php --start 2025-09-01 --end 2025-09-30 --group 12345 --limit 100 --name \"Sept Test\"\n\n";
    echo "  # Multiple groups (10 messages per group = 30 total):\n";
    echo "  php simulate_message_isochrones.php --group \"12345,67890,11111\" --limit 10 --name \"Multi-Group Test\"\n\n";
    echo "  # Using local ORS server:\n";
    echo "  php simulate_message_isochrones.php --group 12345 --limit 100 --ors-server http://localhost:8080/ors --name \"ORS Test\"\n\n";
    echo "Simulation Parameters (hardcoded, adjust in class if needed):\n";
    echo "  - transport: car (mode of transport for isochrone calculation)\n";
    echo "    Valid values: 'car' (default), 'cycle', 'walk' (Mapbox only)\n";
    echo "  - initialMinutes: 5 (starting size for initial isochrone)\n";
    echo "  - maxMinutes: 60 (maximum isochrone size)\n";
    echo "  - increment: 5 (minutes to add per expansion step)\n";
    echo "  - minUsers: 100 (minimum users per isochrone/expansion - overridable via --min-users)\n";
    echo "  - activeSince: 90 days (only include users active in last N days)\n";
    echo "  - timeSinceLastExpand: 60 minutes (minimum time between expansions)\n";
    echo "  - numReplies: 7 (stop expanding once we have this many replies - we have enough interest)\n\n";
    exit(0);
}

$simulatorOptions = [];

if (isset($options['start'])) {
    $simulatorOptions['startDate'] = $options['start'];
}

if (isset($options['end'])) {
    $simulatorOptions['endDate'] = $options['end'];
}

// Handle group filtering - support both single --group and multiple --groups
if (isset($options['groups'])) {
    // Parse comma-separated list from --groups
    $groupIds = array_map('trim', explode(',', $options['groups']));
    $groupIds = array_map('intval', $groupIds);
    $simulatorOptions['groupIds'] = $groupIds;
} elseif (isset($options['group'])) {
    // Check if --group contains comma-separated values
    if (strpos($options['group'], ',') !== FALSE) {
        // Parse comma-separated list from --group
        $groupIds = array_map('trim', explode(',', $options['group']));
        $groupIds = array_map('intval', $groupIds);
        $simulatorOptions['groupIds'] = $groupIds;
    } else {
        // Single group - convert to array for consistency
        $simulatorOptions['groupIds'] = [intval($options['group'])];
    }
}

if (isset($options['limit'])) {
    $simulatorOptions['limit'] = $options['limit'];
}

// Handle min-users parameter
if (isset($options['min-users'])) {
    $simulatorOptions['params']['minUsers'] = intval($options['min-users']);
}

// Handle ors-server parameter
if (isset($options['ors-server'])) {
    $simulatorOptions['orsServer'] = $options['ors-server'];
}

$name = $options['name'] ?? NULL;
$description = $options['description'] ?? NULL;

// Run simulation
$simulator = new MessageIsochroneSimulator($dbhr, $dbhm, $simulatorOptions);
$simulator->run($name, $description);

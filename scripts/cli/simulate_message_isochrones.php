<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

/**
 * Simulate message isochrone expansion using historical data
 *
 * This script analyzes historical messages to determine optimal isochrone expansion parameters.
 * It simulates the arrival of replies over time and tracks when isochrones would have been expanded.
 */

class MessageIsochroneSimulator {
    private $dbhr;
    private $dbhm;
    private $runId = NULL;
    private $startDate;
    private $endDate;
    private $groupId = NULL;
    private $limit = NULL;

    // Simulation parameters to test
    private $params = [
        'initialMinutes' => 10,
        'maxMinutes' => 60,
        'increment' => 10,
        'targetUsers' => 100,
        'activeSince' => 90,
        'transport' => 'walk',
        'timeSinceLastExpand' => 60,  // minutes
        'numReplies' => 3  // replies needed to trigger expansion
    ];

    public function __construct($dbhr, $dbhm, $options = []) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        // Set date range - default to 30-7 days ago
        $this->startDate = $options['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
        $this->endDate = $options['endDate'] ?? date('Y-m-d', strtotime('-7 days'));
        $this->groupId = $options['groupId'] ?? NULL;
        $this->limit = $options['limit'] ?? NULL;

        // Override default parameters if provided
        if (isset($options['params'])) {
            $this->params = array_merge($this->params, $options['params']);
        }
    }

    public function run($name = NULL, $description = NULL) {
        echo "\n=== Message Isochrone Simulation ===\n";
        echo "Date range: {$this->startDate} to {$this->endDate}\n";
        if ($this->groupId) {
            echo "Group filter: {$this->groupId}\n";
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
        echo "Messages processed: " . count($allMetrics) . "\n";
        echo "Aggregate metrics:\n";
        echo json_encode($aggregateMetrics, JSON_PRETTY_PRINT) . "\n";
    }

    private function createRun($name, $description) {
        $filters = [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'groupId' => $this->groupId,
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

    private function getMessages() {
        $sql = "SELECT DISTINCT messages.id, messages.arrival, messages.subject, messages.locationid,
                       messages.lat, messages.lng, messages.fromuser, messages_groups.groupid,
                       `groups`.nameshort
                FROM messages
                INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                INNER JOIN `groups` ON messages_groups.groupid = `groups`.id
                WHERE messages.arrival >= ?
                  AND messages.arrival <= ?
                  AND messages.type IN ('Offer', 'Wanted')
                  AND messages.deleted IS NULL
                  AND messages.locationid IS NOT NULL";

        $params = [$this->startDate, $this->endDate];

        if ($this->groupId) {
            $sql .= " AND messages_groups.groupid = ?";
            $params[] = $this->groupId;
        }

        $sql .= " ORDER BY messages.arrival ASC";

        if ($this->limit) {
            $sql .= " LIMIT " . intval($this->limit);
        }

        return $this->dbhr->preQuery($sql, $params);
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

        // Store message data
        $simMsgId = $this->storeMessage($msg, $sequence, $groupCGA, count($activeUsers), count($replies));

        // Store user data
        $this->storeUsers($simMsgId, $activeUsers, $replies);

        // Simulate isochrone expansions
        $expansions = $this->simulateExpansions($msg, $lat, $lng, $replies, $activeUsers);

        // Store expansion data
        $this->storeExpansions($simMsgId, $expansions, $msg['arrival']);

        // Calculate metrics for this message
        $metrics = $this->calculateMessageMetrics($simMsgId, $expansions, $activeUsers, $replies);

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

    private function simulateExpansions($msg, $lat, $lng, $replies, $activeUsers) {
        $expansions = [];
        $currentMinutes = $this->params['initialMinutes'];
        $lastExpansionTime = NULL;
        $repliesSinceExpansion = 0;

        // Initial isochrone at message arrival
        $isochronePolygon = $this->getIsochronePolygon($msg['locationid'], $currentMinutes, $this->params['transport']);
        $usersInIsochrone = $this->countUsersInIsochrone($isochronePolygon, $activeUsers);

        $expansions[] = [
            'sequence' => 0,
            'minutes_after_arrival' => 0,
            'minutes' => $currentMinutes,
            'transport' => $this->params['transport'],
            'polygon' => $isochronePolygon,
            'users_in_isochrone' => $usersInIsochrone,
            'new_users_reached' => $usersInIsochrone,
            'replies_at_time' => 0,
            'replies_in_isochrone' => 0
        ];

        $lastExpansionTime = 0;

        // Sort replies by time
        usort($replies, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Process each reply
        foreach ($replies as $reply) {
            $replyTime = strtotime($reply['date']);
            $arrivalTime = strtotime($msg['arrival']);
            $minutesSinceArrival = ($replyTime - $arrivalTime) / 60;

            $repliesSinceExpansion++;

            // Check if we should expand
            $timeSinceLastExpand = $minutesSinceArrival - $lastExpansionTime;

            if ($repliesSinceExpansion >= $this->params['numReplies'] &&
                $timeSinceLastExpand >= $this->params['timeSinceLastExpand'] &&
                $currentMinutes < $this->params['maxMinutes']) {

                // Expand isochrone
                $currentMinutes += $this->params['increment'];
                $currentMinutes = min($currentMinutes, $this->params['maxMinutes']);

                $isochronePolygon = $this->getIsochronePolygon($msg['locationid'], $currentMinutes, $this->params['transport']);
                $usersInIsochrone = $this->countUsersInIsochrone($isochronePolygon, $activeUsers);
                $prevUsers = end($expansions)['users_in_isochrone'];

                $expansions[] = [
                    'sequence' => count($expansions),
                    'minutes_after_arrival' => round($minutesSinceArrival),
                    'minutes' => $currentMinutes,
                    'transport' => $this->params['transport'],
                    'polygon' => $isochronePolygon,
                    'users_in_isochrone' => $usersInIsochrone,
                    'new_users_reached' => $usersInIsochrone - $prevUsers,
                    'replies_at_time' => count($replies),
                    'replies_in_isochrone' => $this->countRepliesInIsochrone($isochronePolygon, $activeUsers, $replies)
                ];

                $lastExpansionTime = $minutesSinceArrival;
                $repliesSinceExpansion = 0;
            }
        }

        return $expansions;
    }

    private function getIsochronePolygon($locationId, $minutes, $transport) {
        // Get or create isochrone
        $i = new Isochrone($this->dbhr, $this->dbhm);
        $isochroneId = $i->ensureIsochroneExists($locationId, $minutes, $transport);

        if (!$isochroneId) {
            return NULL;
        }

        // Get polygon as GeoJSON
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

    private function calculateMessageMetrics($simMsgId, $expansions, $activeUsers, $replies) {
        $finalExpansion = end($expansions);

        $metrics = [
            'total_replies' => count($replies),
            'total_active_users' => count($activeUsers),
            'initial_users_reached' => $expansions[0]['users_in_isochrone'],
            'final_users_reached' => $finalExpansion['users_in_isochrone'],
            'total_expansions' => count($expansions) - 1,
            'replies_in_final_isochrone' => $finalExpansion['replies_in_isochrone'],
            'capture_rate' => count($replies) > 0 ?
                round($finalExpansion['replies_in_isochrone'] / count($replies) * 100, 2) : 0,
            'efficiency' => $finalExpansion['users_in_isochrone'] > 0 ?
                round($finalExpansion['replies_in_isochrone'] / $finalExpansion['users_in_isochrone'] * 100, 2) : 0,
            'cost_notifications' => $finalExpansion['users_in_isochrone']
        ];

        return $metrics;
    }

    private function calculateAggregateMetrics($allMetrics) {
        if (count($allMetrics) == 0) {
            return [];
        }

        $totals = [
            'messages' => count($allMetrics),
            'total_replies' => 0,
            'total_users_reached' => 0,
            'capture_rates' => [],
            'efficiencies' => [],
            'expansions' => []
        ];

        foreach ($allMetrics as $m) {
            $totals['total_replies'] += $m['total_replies'];
            $totals['total_users_reached'] += $m['final_users_reached'];
            $totals['capture_rates'][] = $m['capture_rate'];
            $totals['efficiencies'][] = $m['efficiency'];
            $totals['expansions'][] = $m['total_expansions'];
        }

        return [
            'messages_analyzed' => $totals['messages'],
            'total_replies' => $totals['total_replies'],
            'total_users_reached' => $totals['total_users_reached'],
            'median_capture_rate' => $this->median($totals['capture_rates']),
            'mean_capture_rate' => $this->mean($totals['capture_rates']),
            'median_efficiency' => $this->median($totals['efficiencies']),
            'mean_efficiency' => $this->mean($totals['efficiencies']),
            'median_expansions' => $this->median($totals['expansions']),
            'mean_expansions' => $this->mean($totals['expansions'])
        ];
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
    'limit:',
    'name:',
    'description:'
]);

$simulatorOptions = [];

if (isset($options['start'])) {
    $simulatorOptions['startDate'] = $options['start'];
}

if (isset($options['end'])) {
    $simulatorOptions['endDate'] = $options['end'];
}

if (isset($options['group'])) {
    $simulatorOptions['groupId'] = $options['group'];
}

if (isset($options['limit'])) {
    $simulatorOptions['limit'] = $options['limit'];
}

$name = $options['name'] ?? NULL;
$description = $options['description'] ?? NULL;

// Run simulation
$simulator = new MessageIsochroneSimulator($dbhr, $dbhm, $simulatorOptions);
$simulator->run($name, $description);

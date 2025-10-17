<?php

namespace Freegle\Iznik;

require_once(dirname(__FILE__) . '/simulate_message_isochrones.php');

/**
 * Extended simulator that supports configurable temporal expansion curves
 */
class MessageIsochroneSimulatorWithTemporal extends MessageIsochroneSimulator {

    /**
     * Override getExpansionInterval to use configurable curve parameters
     */
    protected function getExpansionInterval($elapsedMinutes) {
        $elapsedHours = $elapsedMinutes / 60;

        // Use configurable breakpoints and intervals
        $breakpoint1 = $this->params['breakpoint1'] ?? 12;
        $breakpoint2 = $this->params['breakpoint2'] ?? 24;
        $interval1 = $this->params['interval1'] ?? 4;
        $interval2 = $this->params['interval2'] ?? 6;
        $interval3 = $this->params['interval3'] ?? 8;

        if ($elapsedHours < $breakpoint1) {
            return $interval1 * 60;  // Convert hours to minutes
        } elseif ($elapsedHours < $breakpoint2) {
            return $interval2 * 60;
        } else {
            return $interval3 * 60;
        }
    }

    /**
     * Run simulation and return results array (for optimization)
     */
    public function runAndReturnResults() {
        // Get messages to simulate
        $messages = $this->getMessages();

        if (count($messages) == 0) {
            return [];
        }

        $results = [];

        foreach ($messages as $index => $msg) {
            try {
                $result = $this->simulateMessageForOptimization($msg);
                if ($result) {
                    $results[] = $result;
                }
            } catch (\Exception $e) {
                // Skip messages that fail
                continue;
            }
        }

        return $results;
    }

    /**
     * Simulate a message and return optimization-relevant data
     */
    private function simulateMessageForOptimization($msg) {
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

        // Get active users
        $activeUsers = $this->getActiveUsersInGroup($msg['groupid'], $lat, $lng);
        if (count($activeUsers) == 0) {
            return NULL;
        }

        // Get actual replies
        $replies = $this->getReplies($msg['id']);

        // Get taker
        $taker = $this->getTaker($msg['id']);

        // Simulate expansions
        $expansionResult = $this->simulateExpansions($msg, $lat, $lng, $replies, $activeUsers, $taker);

        if (empty($expansionResult['expansions'])) {
            return NULL;
        }

        $finalExpansion = end($expansionResult['expansions']);

        // Return data needed for optimization evaluation
        return [
            'msgid' => $msg['id'],
            'arrival' => $msg['arrival'],
            'final_expansion_timestamp' => date('Y-m-d H:i:s',
                strtotime($msg['arrival']) + ($finalExpansion['minutes_after_arrival'] * 60)),
            'replies_at_final' => $finalExpansion['replies_at_time'],
            'taker_reached' => $expansionResult['taker_reached_at'] !== NULL,
            'taker_reached_at_expansion' => $expansionResult['taker_reached_at']['expansion_index'] ?? NULL,
            'total_expansions' => count($expansionResult['expansions']) - 1,
            'final_users_reached' => $finalExpansion['users_in_isochrone']
        ];
    }

    /**
     * Make parent methods accessible
     */
    public function getMessages() {
        return parent::getMessages();
    }

    public function getActiveUsersInGroup($groupId, $lat, $lng) {
        return parent::getActiveUsersInGroup($groupId, $lat, $lng);
    }

    public function getReplies($msgId) {
        return parent::getReplies($msgId);
    }

    public function getTaker($msgId) {
        return parent::getTaker($msgId);
    }

    public function simulateExpansions($msg, $lat, $lng, $replies, $activeUsers, $takerId) {
        return parent::simulateExpansions($msg, $lat, $lng, $replies, $activeUsers, $takerId);
    }
}

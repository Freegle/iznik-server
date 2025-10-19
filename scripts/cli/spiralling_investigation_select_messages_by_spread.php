<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

/**
 * Select messages with good geographic spread across a group's CGA
 *
 * Instead of selecting messages by time (which could cluster in one area),
 * this selects messages distributed across the group's catchment area.
 */

class GeographicMessageSelector {
    private $dbhr;
    private $dbhm;

    public function __construct($dbhr, $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    /**
     * Select messages with good geographic spread
     *
     * @param int $groupId Group ID
     * @param int $targetCount Target number of messages (default 50)
     * @param int $lookbackDays How many days to look back (default 90)
     * @return array Array of message IDs
     */
    public function selectMessages($groupId, $targetCount = 50, $lookbackDays = 90) {
        echo "Selecting $targetCount messages with geographic spread for group $groupId\n";

        // Get group CGA bounds
        $bounds = $this->getGroupBounds($groupId);
        if (!$bounds) {
            echo "Error: No CGA polygon for group $groupId\n";
            return [];
        }

        echo "Group bounds: lat {$bounds['minLat']} to {$bounds['maxLat']}, " .
             "lng {$bounds['minLng']} to {$bounds['maxLng']}\n";

        // Divide CGA into grid cells
        $gridSize = $this->calculateOptimalGridSize($targetCount);
        echo "Using {$gridSize}x{$gridSize} grid (total {$gridSize * $gridSize} cells)\n";

        $cells = $this->createGrid($bounds, $gridSize);

        // Get messages from recent timeframe
        $startDate = date('Y-m-d', strtotime("-{$lookbackDays} days"));
        $endDate = date('Y-m-d');

        echo "Looking for messages from $startDate to $endDate\n";

        $candidateMessages = $this->getCandidateMessages($groupId, $startDate, $endDate);
        echo "Found " . count($candidateMessages) . " candidate messages\n";

        if (count($candidateMessages) == 0) {
            echo "No messages found in timeframe\n";
            return [];
        }

        // Assign messages to grid cells
        $messagesByCell = $this->assignMessagesToCells($candidateMessages, $cells);

        // Sample from cells to get good spread
        $selectedMessages = $this->sampleFromCells($messagesByCell, $targetCount);

        echo "Selected " . count($selectedMessages) . " messages with geographic spread\n";

        // Show distribution
        $this->showDistribution($selectedMessages, $cells);

        return $selectedMessages;
    }

    private function getGroupBounds($groupId) {
        $sql = "SELECT
                    ST_X(ST_PointN(ST_ExteriorRing(ST_Envelope(polyindex)), 1)) as minLng,
                    ST_Y(ST_PointN(ST_ExteriorRing(ST_Envelope(polyindex)), 1)) as minLat,
                    ST_X(ST_PointN(ST_ExteriorRing(ST_Envelope(polyindex)), 3)) as maxLng,
                    ST_Y(ST_PointN(ST_ExteriorRing(ST_Envelope(polyindex)), 3)) as maxLat
                FROM `groups`
                WHERE id = ?
                  AND polyindex IS NOT NULL";

        $result = $this->dbhr->preQuery($sql, [$groupId]);

        if (count($result) == 0) {
            return NULL;
        }

        return [
            'minLat' => floatval($result[0]['minLat']),
            'maxLat' => floatval($result[0]['maxLat']),
            'minLng' => floatval($result[0]['minLng']),
            'maxLng' => floatval($result[0]['maxLng'])
        ];
    }

    private function calculateOptimalGridSize($targetCount) {
        // Use grid size that gives roughly 1-2 messages per cell on average
        // If we want 50 messages, a 7x7 grid (49 cells) is reasonable
        return intval(ceil(sqrt($targetCount)));
    }

    private function createGrid($bounds, $gridSize) {
        $cells = [];

        $latStep = ($bounds['maxLat'] - $bounds['minLat']) / $gridSize;
        $lngStep = ($bounds['maxLng'] - $bounds['minLng']) / $gridSize;

        for ($row = 0; $row < $gridSize; $row++) {
            for ($col = 0; $col < $gridSize; $col++) {
                $cells[] = [
                    'row' => $row,
                    'col' => $col,
                    'minLat' => $bounds['minLat'] + ($row * $latStep),
                    'maxLat' => $bounds['minLat'] + (($row + 1) * $latStep),
                    'minLng' => $bounds['minLng'] + ($col * $lngStep),
                    'maxLng' => $bounds['minLng'] + (($col + 1) * $lngStep)
                ];
            }
        }

        return $cells;
    }

    private function getCandidateMessages($groupId, $startDate, $endDate) {
        $sql = "SELECT DISTINCT
                    messages.id,
                    messages.arrival,
                    messages.subject,
                    messages.lat,
                    messages.lng,
                    messages.locationid
                FROM messages
                INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                WHERE messages_groups.groupid = ?
                  AND messages.arrival >= ?
                  AND messages.arrival <= ?
                  AND messages.type IN ('Offer', 'Wanted')
                  AND messages.deleted IS NULL
                  AND messages.locationid IS NOT NULL
                  AND messages.lat IS NOT NULL
                  AND messages.lng IS NOT NULL
                ORDER BY messages.arrival DESC";

        return $this->dbhr->preQuery($sql, [$groupId, $startDate, $endDate]);
    }

    private function assignMessagesToCells($messages, $cells) {
        $messagesByCell = [];

        foreach ($messages as $msg) {
            $lat = floatval($msg['lat']);
            $lng = floatval($msg['lng']);

            // Find which cell this message belongs to
            foreach ($cells as $cellIdx => $cell) {
                if ($lat >= $cell['minLat'] && $lat < $cell['maxLat'] &&
                    $lng >= $cell['minLng'] && $lng < $cell['maxLng']) {

                    if (!isset($messagesByCell[$cellIdx])) {
                        $messagesByCell[$cellIdx] = [];
                    }

                    $messagesByCell[$cellIdx][] = $msg;
                    break;
                }
            }
        }

        return $messagesByCell;
    }

    private function sampleFromCells($messagesByCell, $targetCount) {
        $selected = [];
        $cellIndices = array_keys($messagesByCell);

        // First pass: Take one message from each cell that has messages
        foreach ($cellIndices as $cellIdx) {
            if (count($selected) >= $targetCount) {
                break;
            }

            $cellMessages = $messagesByCell[$cellIdx];
            if (count($cellMessages) > 0) {
                // Take the most recent message from this cell
                $selected[] = $cellMessages[0];
            }
        }

        // Second pass: If we need more messages, round-robin through cells
        $pass = 1;
        while (count($selected) < $targetCount) {
            $addedInPass = 0;

            foreach ($cellIndices as $cellIdx) {
                if (count($selected) >= $targetCount) {
                    break;
                }

                $cellMessages = $messagesByCell[$cellIdx];
                if (count($cellMessages) > $pass) {
                    // Take the next message from this cell
                    $selected[] = $cellMessages[$pass];
                    $addedInPass++;
                }
            }

            // If we didn't add any messages in this pass, we've exhausted all cells
            if ($addedInPass == 0) {
                break;
            }

            $pass++;
        }

        return $selected;
    }

    private function showDistribution($selectedMessages, $cells) {
        echo "\nGeographic distribution:\n";

        // Count messages per cell
        $counts = array_fill(0, count($cells), 0);

        foreach ($selectedMessages as $msg) {
            $lat = floatval($msg['lat']);
            $lng = floatval($msg['lng']);

            foreach ($cells as $cellIdx => $cell) {
                if ($lat >= $cell['minLat'] && $lat < $cell['maxLat'] &&
                    $lng >= $cell['minLng'] && $lng < $cell['maxLng']) {
                    $counts[$cellIdx]++;
                    break;
                }
            }
        }

        // Find grid size (assume square grid)
        $gridSize = intval(sqrt(count($cells)));

        // Display as grid
        for ($row = 0; $row < $gridSize; $row++) {
            $line = "  ";
            for ($col = 0; $col < $gridSize; $col++) {
                $cellIdx = $row * $gridSize + $col;
                $count = $counts[$cellIdx];

                if ($count == 0) {
                    $line .= ". ";
                } else {
                    $line .= $count . " ";
                }
            }
            echo "$line\n";
        }

        echo "\n. = no messages, numbers = message count\n";

        // Summary stats
        $nonEmptyCells = count(array_filter($counts, function($c) { return $c > 0; }));
        $coverage = round(($nonEmptyCells / count($cells)) * 100, 1);

        echo "\nCoverage: $nonEmptyCells / " . count($cells) . " cells ($coverage%)\n";
        echo "Messages per cell: min=" . min($counts) . ", max=" . max($counts) .
             ", avg=" . round(array_sum($counts) / count($counts), 1) . "\n";
    }

    /**
     * Export selected message IDs to a file
     */
    public function exportMessageIds($messages, $outputPath) {
        $fp = fopen($outputPath, 'w');
        fputcsv($fp, ['message_id', 'arrival', 'subject', 'lat', 'lng']);

        foreach ($messages as $msg) {
            fputcsv($fp, [
                $msg['id'],
                $msg['arrival'],
                $msg['subject'],
                $msg['lat'],
                $msg['lng']
            ]);
        }

        fclose($fp);
        echo "Message IDs exported to: $outputPath\n";
    }
}

// CLI interface
$options = getopt('', [
    'group:',
    'groups:',
    'count:',
    'lookback:',
    'output:',
    'help'
]);

if (isset($options['help']) || (!isset($options['group']) && !isset($options['groups']))) {
    echo "Usage: php select_messages_by_spread.php --group=<id> [options]\n\n";
    echo "Selects messages with good geographic spread across a group's catchment area.\n";
    echo "This ensures optimization and simulation see diverse scenarios, not just\n";
    echo "messages clustered in one part of the CGA.\n\n";
    echo "Options:\n";
    echo "  --group=ID        Single group ID\n";
    echo "  --groups=IDS      Multiple group IDs (comma-separated)\n";
    echo "  --count=N         Target number of messages per group (default: 50)\n";
    echo "  --lookback=DAYS   How many days to look back (default: 90)\n";
    echo "  --output=PATH     Export message IDs to CSV (optional)\n";
    echo "  --help            Show this help\n\n";
    echo "Strategy:\n";
    echo "  1. Divide group's CGA into a grid (e.g., 7x7 = 49 cells)\n";
    echo "  2. Select messages distributed across grid cells\n";
    echo "  3. Ensures coverage of center, edges, corners of service area\n";
    echo "  4. Better than time-based sampling which may cluster in one area\n\n";
    echo "Examples:\n";
    echo "  # Select 50 messages from one group\n";
    echo "  php select_messages_by_spread.php --group=12345 --count=50\n\n";
    echo "  # Select from multiple groups and export to CSV\n";
    echo "  php select_messages_by_spread.php --groups=\"12345,67890\" \\\n";
    echo "    --count=50 --output=/tmp/messages.csv\n\n";
    exit(0);
}

$selector = new GeographicMessageSelector($dbhr, $dbhm);

$targetCount = isset($options['count']) ? intval($options['count']) : 50;
$lookbackDays = isset($options['lookback']) ? intval($options['lookback']) : 90;

// Handle multiple groups
$groupIds = [];
if (isset($options['groups'])) {
    $groupIds = array_map('trim', explode(',', $options['groups']));
    $groupIds = array_map('intval', $groupIds);
} elseif (isset($options['group'])) {
    $groupIds = [intval($options['group'])];
}

$allSelectedMessages = [];

foreach ($groupIds as $groupId) {
    echo "\n=== Group $groupId ===\n";

    $messages = $selector->selectMessages($groupId, $targetCount, $lookbackDays);
    $allSelectedMessages = array_merge($allSelectedMessages, $messages);

    echo "\n";
}

// Export if requested
if (isset($options['output']) && count($allSelectedMessages) > 0) {
    $selector->exportMessageIds($allSelectedMessages, $options['output']);
}

echo "\nTotal messages selected: " . count($allSelectedMessages) . "\n";
echo "\nYou can now use these message IDs for simulation or optimization.\n";
echo "To run simulation with these specific messages, modify the SQL query\n";
echo "in simulate_message_isochrones.php to use: WHERE messages.id IN (...)\n";

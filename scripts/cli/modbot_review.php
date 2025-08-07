<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('g:l:d:mrhv');

if (count($opts) < 1 || isset($opts['h'])) {
    echo "Usage: php modbot_review.php -g <group_name> [-l <limit>] [-d <days_back>] [-m] [-r] [-v]\n";
    echo "\n";
    echo "Options:\n";
    echo "  -g <group_name> Group name (short name) or 'all' for all groups (required)\n";
    echo "  -l <limit>      Maximum number of posts to process (default: 50)\n"; 
    echo "  -d <days_back>  Number of days back to process posts (default: 30)\n";
    echo "  -m              Create microvolunteering entries for each review\n";
    echo "  -r              Search rejected posts instead of approved/pending posts\n";
    echo "  -v              Verbose debug mode - show all rule probabilities from Gemini\n";
    echo "  -h              Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php modbot_review.php -g freeglegroup -l 100 -d 7 -m -v\n";
    echo "  php modbot_review.php -g freeglegroup -r -d 3  # Review rejected posts from last 3 days\n";
    echo "  php modbot_review.php -g all -l 20 -d 1 -v      # Review posts from all groups (no actions)\n";
    exit(0);
}

$groupName = Utils::presdef('g', $opts, NULL);
$limit = Utils::presdef('l', $opts, 50);
$daysBack = Utils::presdef('d', $opts, 30);
$createMicrovolunteering = isset($opts['m']);
$searchRejected = isset($opts['r']);
$debugMode = isset($opts['v']);
$processAllGroups = ($groupName === 'all');

// Disable actions when processing all groups for safety
if ($processAllGroups) {
    $createMicrovolunteering = false;
}

if (!$groupName) {
    echo "Error: Group name (-g) is required\n";
    exit(1);
}

$limit = intval($limit);
$daysBack = intval($daysBack);

if ($limit <= 0 || $limit > 1000) {
    echo "Error: Limit must be between 1 and 1000\n";
    exit(1);
}

if ($daysBack <= 0 || $daysBack > 365) {
    echo "Error: Days back must be between 1 and 365\n";
    exit(1);
}

echo "ModBot Post Review Tool\n";
echo "======================\n";
echo "Group Name: $groupName\n";
echo "Limit: $limit posts\n";
echo "Processing posts from last $daysBack days\n";
echo "Post Collection: " . ($searchRejected ? "Rejected" : "Approved/Pending") . "\n";
echo "Microvolunteering: " . ($createMicrovolunteering && $hasModRights ? "Enabled" : "Disabled" . ($createMicrovolunteering && !$hasModRights ? " (no mod rights)" : "") . ($processAllGroups ? " (disabled for 'all' groups)" : "")) . "\n";
echo "Debug Mode: " . ($debugMode ? "Enabled" : "Disabled") . "\n\n";

if ($processAllGroups) {
    echo "Processing posts from ALL groups (actions disabled for safety)\n\n";
    $groupId = null; // Will be handled differently for all groups
} else {
    // Find group by name
    $groupSql = "SELECT id FROM `groups` WHERE nameshort = ? OR namefull = ?";
    $groupResults = $dbhr->preQuery($groupSql, [$groupName, $groupName]);

    if (empty($groupResults)) {
        echo "Error: Group '$groupName' not found\n";
        echo "Please check the group name. Use either the short name or full name.\n\n";
        
        // Show similar group names as suggestions
        $similarSql = "SELECT nameshort, namefull FROM `groups` WHERE nameshort LIKE ? OR namefull LIKE ? ORDER BY nameshort LIMIT 10";
        $similarPattern = '%' . $groupName . '%';
        $similarGroups = $dbhr->preQuery($similarSql, [$similarPattern, $similarPattern]);
        
        if (!empty($similarGroups)) {
            echo "Did you mean one of these groups?\n";
            foreach ($similarGroups as $group) {
                echo "  - {$group['nameshort']} ({$group['namefull']})\n";
            }
        } else {
            // Show first 10 groups as examples
            $exampleGroups = $dbhr->preQuery("SELECT nameshort, namefull FROM `groups` ORDER BY nameshort LIMIT 10");
            if (!empty($exampleGroups)) {
                echo "Available groups (first 10):\n";
                foreach ($exampleGroups as $group) {
                    echo "  - {$group['nameshort']} ({$group['namefull']})\n";
                }
            }
        }
        
        exit(1);
    }

    $groupId = $groupResults[0]['id'];
    $g = Group::get($dbhr, $dbhm, $groupId);

    echo "Group: " . $g->getPrivate('namefull') . " (" . $g->getPrivate('nameshort') . ") [ID: $groupId]\n\n";
}

// Initialize ModBot
$modbot = new ModBot($dbhr, $dbhm);

// Check if modbot has moderation rights (skip for 'all' groups)
if ($processAllGroups) {
    $hasModRights = false; // Always false for all groups to disable actions
} else {
    $modbotUser = User::get($dbhr, $dbhm);
    $botUserId = $modbotUser->findByEmail(MODBOT_USER);
    if (!$botUserId) {
        echo "Error: ModBot user not found. Please ensure " . MODBOT_USER . " exists in the system.\n";
        exit(1);
    }

    $modbotUser = User::get($dbhr, $dbhm, $botUserId);
    $hasModRights = $modbotUser->isModOrOwner($groupId);

    if (!$hasModRights) {
        echo "Warning: ModBot user does not have moderator privileges on this group.\n";
        echo "Analysis will proceed but no microvolunteering entries will be created.\n\n";
    }
}

// Get posts from the last N days in descending date order
$cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysBack days"));

if ($processAllGroups) {
    if ($searchRejected) {
        $sql = "
            SELECT DISTINCT m.id, m.subject, m.fromaddr, mg.arrival, m.type, g.nameshort as groupname
            FROM messages m 
            INNER JOIN messages_groups mg ON m.id = mg.msgid 
            INNER JOIN `groups` g ON mg.groupid = g.id
            WHERE mg.arrival >= ? 
            AND mg.deleted = 0 
            AND m.deleted IS NULL
            AND mg.collection = ?
            ORDER BY mg.arrival DESC 
            LIMIT " . intval($limit);
        
        echo "Fetching rejected posts from all groups...\n";
        $posts = $dbhr->preQuery($sql, [$cutoffDate, MessageCollection::REJECTED]);
    } else {
        $sql = "
            SELECT DISTINCT m.id, m.subject, m.fromaddr, mg.arrival, m.type, g.nameshort as groupname
            FROM messages m 
            INNER JOIN messages_groups mg ON m.id = mg.msgid 
            INNER JOIN `groups` g ON mg.groupid = g.id
            WHERE mg.arrival >= ? 
            AND mg.deleted = 0 
            AND m.deleted IS NULL
            AND mg.collection IN (?, ?)
            ORDER BY mg.arrival DESC 
            LIMIT " . intval($limit);
        
        echo "Fetching posts from all groups...\n";
        $posts = $dbhr->preQuery($sql, [$cutoffDate, MessageCollection::PENDING, MessageCollection::APPROVED]);
    }
} else {
    if ($searchRejected) {
        $sql = "
            SELECT DISTINCT m.id, m.subject, m.fromaddr, mg.arrival, m.type
            FROM messages m 
            INNER JOIN messages_groups mg ON m.id = mg.msgid 
            WHERE mg.groupid = ? 
            AND mg.arrival >= ? 
            AND mg.deleted = 0 
            AND m.deleted IS NULL
            AND mg.collection = ?
            ORDER BY mg.arrival DESC 
            LIMIT " . intval($limit);
        
        echo "Fetching rejected posts...\n";
        $posts = $dbhr->preQuery($sql, [$groupId, $cutoffDate, MessageCollection::REJECTED]);
    } else {
        $sql = "
            SELECT DISTINCT m.id, m.subject, m.fromaddr, mg.arrival, m.type
            FROM messages m 
            INNER JOIN messages_groups mg ON m.id = mg.msgid 
            WHERE mg.groupid = ? 
            AND mg.arrival >= ? 
            AND mg.deleted = 0 
            AND m.deleted IS NULL
            AND mg.collection IN (?, ?)
            ORDER BY mg.arrival DESC 
            LIMIT " . intval($limit);
        
        echo "Fetching posts...\n";
        $posts = $dbhr->preQuery($sql, [$groupId, $cutoffDate, MessageCollection::PENDING, MessageCollection::APPROVED]);
    }
}

if (empty($posts)) {
    echo "No " . ($searchRejected ? "rejected " : "") . "posts found in the specified time range.\n";
    exit(0);
}

echo "Found " . count($posts) . " posts to review.\n\n";

$processedCount = 0;
$violationsFound = 0;
$errorCount = 0;
$totalInputTokens = 0;
$totalOutputTokens = 0;
$estimatedCost = 0.0;

foreach ($posts as $post) {
    $processedCount++;
    
    $subject = substr($post['subject'], 0, 40) . (strlen($post['subject']) > 40 ? '...' : '');
    $groupDisplay = $processAllGroups ? "[{$post['groupname']}] " : "";
    $statusLine = "[$processedCount/" . count($posts) . "] {$groupDisplay}ID:{$post['id']} \"{$subject}\" ";
    
    // Get rejection reason if this is a rejected post
    $rejectionReason = '';
    if ($searchRejected) {
        // Find rejection log with proper structure: Type=Message, Subtype=Rejected, stdmsgid not null
        $logSql = "SELECT l.text, l.stdmsgid, sm.title FROM logs l 
                   LEFT JOIN mod_stdmsgs sm ON l.stdmsgid = sm.id 
                   WHERE l.msgid = ? AND l.type = ? AND l.subtype = ? AND l.stdmsgid IS NOT NULL 
                   ORDER BY l.timestamp DESC LIMIT 1";
        $logResult = $dbhr->preQuery($logSql, [$post['id'], Log::TYPE_MESSAGE, Log::SUBTYPE_REJECTED]);
        
        if (!empty($logResult)) {
            $logEntry = $logResult[0];
            // Use the standard message title if available, otherwise parse the log text
            if (!empty($logEntry['title'])) {
                $rejectionReason = $logEntry['title'];
            } else {
                // Fallback to parsing log text
                $logText = $logEntry['text'];
                if (preg_match('/rejected message .* using (.*)/', $logText, $matches)) {
                    $rejectionReason = trim($matches[1]);
                } elseif (preg_match('/rejected.*?reason:\s*(.*)/', $logText, $matches)) {
                    $rejectionReason = trim($matches[1]);
                } else {
                    $rejectionReason = trim($logText);
                }
            }
        }
        
        // Debug output to help understand log format when no reason found
        if (empty($rejectionReason) && $debugMode) {
            echo "    → Debug: No rejection reason found for post {$post['id']}\n";
            // Try a broader query to see what logs exist
            $debugSql = "SELECT l.type, l.subtype, l.stdmsgid, l.text FROM logs l WHERE l.msgid = ? ORDER BY l.timestamp DESC LIMIT 3";
            $debugResult = $dbhr->preQuery($debugSql, [$post['id']]);
            if (!empty($debugResult)) {
                echo "    → Debug: Found " . count($debugResult) . " log entries:\n";
                foreach ($debugResult as $i => $entry) {
                    echo "    → Debug [$i]: Type={$entry['type']} Subtype={$entry['subtype']} StdMsgId={$entry['stdmsgid']}\n";
                }
            } else {
                echo "    → Debug: No log entries found at all\n";
            }
        }
    }
    
    try {
        $result = $modbot->reviewPost($post['id'], $createMicrovolunteering, $debugMode, !$hasModRights);
        
        // Debug logging to see what we got back (remove when not needed)
        // error_log("Debug: ModBot result for post {$post['id']} (debugMode=$debugMode): " . json_encode($result));
        
        // Extract data from result (now always includes violations and cost_info)
        if (is_array($result)) {
            $violations = $result['violations'] ?? $result; // fallback for old format
            $costInfo = $result['cost_info'] ?? null;
            $debugInfo = $result['debug'] ?? null;
            
            // Track costs
            if ($costInfo) {
                $totalInputTokens += $costInfo['input_tokens'];
                $totalOutputTokens += $costInfo['output_tokens'];
                $estimatedCost += $costInfo['total_cost'];
            }
        } else {
            $violations = $result;
            $costInfo = null;
            $debugInfo = null;
        }
        
        if (is_array($violations) && isset($violations['error'])) {
            // Handle specific error cases with detailed info
            switch ($violations['error']) {
                case 'no_groups':
                    echo $statusLine . "⚠ SKIP (no groups)\n";
                    if ($searchRejected && $rejectionReason) {
                        echo "    → Originally rejected: " . $rejectionReason . "\n";
                    }
                    echo "    → Post is not associated with any groups\n";
                    break;
                case 'no_moderation_rights':
                    echo $statusLine . "⚠ SKIP (no mod rights)\n";
                    if ($searchRejected && $rejectionReason) {
                        echo "    → Originally rejected: " . $rejectionReason . "\n";
                    }
                    echo "    → ModBot lacks moderator privileges on associated groups\n";
                    break;
                case 'message_not_found':
                    echo $statusLine . "⚠ SKIP (not found)\n";
                    if ($searchRejected && $rejectionReason) {
                        echo "    → Originally rejected: " . $rejectionReason . "\n";
                    }
                    echo "    → Message not found in database\n";
                    break;
                default:
                    echo $statusLine . "⚠ SKIP (error)\n";
                    if ($searchRejected && $rejectionReason) {
                        echo "    → Originally rejected: " . $rejectionReason . "\n";
                    }
                    echo "    → " . $violations['message'] . "\n";
                    break;
            }
        } elseif ($violations === NULL) {
            echo $statusLine . "⚠ SKIP (unknown)\n";
            if ($searchRejected && $rejectionReason) {
                echo "    → Originally rejected: " . $rejectionReason . "\n";
            }
            echo "    → Unknown error occurred\n";
        } elseif (empty($violations)) {
            // Keep successful posts on single line
            echo $statusLine . "✓ OK\n";
            
            // Show rejection reason for rejected posts (always show)
            if ($searchRejected && $rejectionReason) {
                echo "    → Originally rejected: " . $rejectionReason . "\n";
            }
            
            // Show debug info for OK posts if verbose mode is on
            if ($debugMode) {
                if ($debugInfo) {
                    if (!empty($debugInfo['error'])) {
                        echo "    → Gemini error: " . $debugInfo['error'] . "\n";
                    } elseif (!empty($debugInfo['raw_violations'])) {
                        echo "    → Gemini analysis: ";
                        $rawInfo = [];
                        foreach ($debugInfo['raw_violations'] as $v) {
                            $rawInfo[] = ($v['rule'] ?? 'unknown') . ':' . number_format(($v['probability'] ?? 0) * 100, 1) . '%';
                        }
                        echo implode(', ', $rawInfo) . "\n";
                        
                        if (!empty($debugInfo['filtered_out'])) {
                            echo "    → Below threshold: " . implode(', ', $debugInfo['filtered_out']) . "\n";
                        }
                    } else {
                        echo "    → Gemini analysis: No response from API\n";
                    }
                } else {
                    echo "    → Debug info: Not available (result format issue)\n";
                }
                
                // Show cost info in debug mode
                if ($costInfo && ($costInfo['input_tokens'] > 0 || $costInfo['output_tokens'] > 0)) {
                    echo "    → Cost: \$" . number_format($costInfo['total_cost'], 6) . " ";
                    echo "(" . number_format($costInfo['input_tokens']) . " in + " . number_format($costInfo['output_tokens']) . " out tokens)\n";
                }
            }
        } else {
            $violationsFound++;
            $violationList = [];
            foreach ($violations as $violation) {
                $rule = $violation['rule'] ?? 'unknown';
                $probability = $violation['probability'] ?? 0;
                $violationList[] = $rule . ':' . number_format($probability * 100, 0) . '%';
            }
            echo $statusLine . "⚠ VIOLATIONS (" . implode(', ', $violationList) . ")\n";
            
            // Show rejection reason for rejected posts (always show first)
            if ($searchRejected && $rejectionReason) {
                echo "    → Originally rejected: " . $rejectionReason . "\n";
            }
            
            // Show detailed violation info
            echo "    From: " . $post['fromaddr'] . " | Date: " . $post['arrival'] . " | Type: " . $post['type'] . "\n";
            foreach ($violations as $violation) {
                $rule = $violation['rule'] ?? 'unknown';
                $probability = $violation['probability'] ?? 0;
                $reason = $violation['reason'] ?? 'No reason provided';
                
                echo "    → " . strtoupper($rule) . " (" . number_format($probability * 100, 1) . "%): $reason\n";
            }
            
            // Show debug info if verbose mode is on
            if ($debugMode && $debugInfo && !empty($debugInfo['raw_violations'])) {
                echo "    → Full Gemini analysis: ";
                $rawInfo = [];
                foreach ($debugInfo['raw_violations'] as $v) {
                    $rawInfo[] = ($v['rule'] ?? 'unknown') . ':' . number_format(($v['probability'] ?? 0) * 100, 1) . '%';
                }
                echo implode(', ', $rawInfo) . "\n";
                
                if (!empty($debugInfo['filtered_out']) && $debugMode) {
                    echo "    → Below threshold: " . implode(', ', $debugInfo['filtered_out']) . "\n";
                }
            }
        }
        
    } catch (Exception $e) {
        $errorCount++;
        $errorMessage = $e->getMessage();
        
        // Check for quota exhaustion - this is a fatal error
        if (strpos($errorMessage, 'exceeded your current quota') !== false || 
            strpos($errorMessage, 'FreeTier') !== false) {
            echo $statusLine . "✗ FATAL: API QUOTA EXHAUSTED\n";
            echo "    → Google Gemini daily quota limit reached\n";
            echo "    → Cannot safely continue - posts would be incorrectly marked as OK\n";
            echo "\n=== SCRIPT TERMINATED ===\n";
            echo "Reason: API quota exhausted - cannot analyze posts safely\n";
            echo "Posts processed before quota exhaustion: $processedCount\n";
            echo "ERROR: Continuing would mark unanalyzed posts as 'OK' which is unsafe\n";
            echo "Please wait for quota reset or upgrade API plan\n";
            exit(1);
        }
        
        // Check for other rate limit errors (temporary)
        $isRateLimit = strpos($errorMessage, '429') !== false || 
                      strpos($errorMessage, 'RESOURCE_EXHAUSTED') !== false ||
                      strpos($errorMessage, 'quota') !== false;
        
        if ($isRateLimit) {
            echo $statusLine . "✗ RATE LIMIT (waiting 5s...)\n";
            echo "    → API rate limit hit, retrying with delay\n";
            sleep(5);
            // Don't log rate limit errors - they're expected and handled
        } else {
            echo $statusLine . "✗ ERROR\n";
            echo "    → " . substr($errorMessage, 0, 100) . (strlen($errorMessage) > 100 ? '...' : '') . "\n";
            error_log("ModBot error processing post " . $post['id'] . ": " . $errorMessage);
        }
    }
    
    // Small delay to avoid overwhelming the API
    usleep(250000); // 250ms delay
}

echo "\n=== Review Summary ===\n";
echo "Posts processed: $processedCount\n";
echo "Violations found: $violationsFound\n";
echo "Errors encountered: $errorCount\n";
echo "Success rate: " . number_format((($processedCount - $errorCount) / $processedCount) * 100, 1) . "%\n";

if ($totalInputTokens > 0 || $totalOutputTokens > 0) {
    echo "\n=== Cost Estimation (Gemini 2.0 Flash-Lite) ===\n";
    echo "Input tokens: " . number_format($totalInputTokens) . "\n";
    echo "Output tokens: " . number_format($totalOutputTokens) . "\n";
    echo "Total tokens: " . number_format($totalInputTokens + $totalOutputTokens) . "\n";
    echo "Estimated cost: $" . number_format($estimatedCost, 6) . "\n";
    echo "Rate: \$0.10/1M input tokens, \$0.40/1M output tokens\n";
}

if ($createMicrovolunteering && $hasModRights) {
    echo "Microvolunteering entries created: " . ($processedCount - $errorCount) . "\n";
} elseif ($createMicrovolunteering && !$hasModRights) {
    echo "Microvolunteering entries created: 0 (no moderation rights)\n";
}

if ($violationsFound > 0) {
    if ($searchRejected) {
        echo "\nNote: AI found violations in rejected posts. This may validate the original rejection decisions.\n";
    } else {
        echo "\nNote: Posts with violations should be reviewed manually by moderators.\n";
    }
}

echo "\nReview completed.\n";
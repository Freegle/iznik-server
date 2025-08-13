<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$opts = getopt('d:t:vh');

if (isset($opts['h']) || count($opts) < 1) {
    echo "Usage: php stdmsg_analyze.php -d <days_back> [-t <threshold>] [-v] [-h]\n";
    echo "\n";
    echo "Options:\n";
    echo "  -d <days_back>    Number of days back to analyze (default: 90)\n";
    echo "  -t <threshold>    Improvement score threshold 0.0-1.0 (default: 0.3)\n";
    echo "                    Only show messages with scores >= threshold\n";
    echo "  -v                Verbose mode - show raw Gemini responses\n";
    echo "  -h                Show this help message\n";
    echo "\n";
    echo "This script analyzes standard messages used by moderators for quality issues\n";
    echo "and provides suggestions for improvement using AI analysis.\n";
    echo "\n";
    echo "Improvement scores: 0.0 = perfect, 1.0 = major improvements needed\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php stdmsg_analyze.php -d 90              # Show messages scoring >= 0.3\n";
    echo "  php stdmsg_analyze.php -d 30 -t 0.5 -v   # Show messages scoring >= 0.5 with verbose output\n";
    echo "  php stdmsg_analyze.php -d 7 -t 0.0       # Show all messages regardless of score\n";
    exit(0);
}

$daysBack = Utils::presdef('d', $opts, 90);
$threshold = (float) Utils::presdef('t', $opts, 0.3);
$verbose = isset($opts['v']);

// Validate threshold
if ($threshold < 0.0 || $threshold > 1.0) {
    echo "Error: Threshold must be between 0.0 and 1.0\n";
    exit(1);
}

echo "Starting standard message analysis for last $daysBack days...\n";
echo "Showing messages with improvement score >= $threshold\n\n";

try {
    $stdMsg = new StdMessage($dbhr, $dbhm);
    $results = $stdMsg->analyzeStandardMessages($daysBack, TRUE, $verbose, $threshold);
    
    if (empty($results)) {
        echo "No standard message usage found in the specified time period.\n";
        exit(0);
    }
    
    echo "\nAnalysis complete!\n\n";
    
    // Check if any messages met the threshold (already shown above)
    $filteredMessages = 0;
    $highestScore = 0.0;
    $highestMessage = null;
    $highestMod = null;
    
    foreach ($results as $modResult) {
        foreach ($modResult['messages'] as $message) {
            $score = $message['improvement_score'] ?? 0.0;
            if ($score >= $threshold) {
                $filteredMessages++;
            }
            if ($score > $highestScore) {
                $highestScore = $score;
                $highestMessage = $message;
                $highestMod = $modResult;
            }
        }
    }
    
    // If no messages met threshold, show the highest scoring one
    if ($filteredMessages == 0 && $highestMessage && $highestScore > 0.0) {
        $scorePercent = number_format($highestScore * 100, 1);
        echo "No messages exceeded threshold >= $threshold\n\n";
        echo "\033[93mHighest scoring message (Score: $scorePercent%):\033[0m\n";
        echo str_repeat("=", 45) . "\n";
        echo "\033[94mModerator:\033[0m {$highestMod['mod_name']} ({$highestMod['mod_email']})\n";
        echo "\033[94mStandard Message ID:\033[0m {$highestMessage['stdmsgid']}\n";
        echo "\033[94mTitle:\033[0m {$highestMessage['title']}\n";
        echo "\033[93mCurrent Text:\033[0m\n";
        echo wordwrap($highestMessage['old_text'], 70) . "\n\n";
        echo "\033[91mAnalysis:\033[0m {$highestMessage['analysis']}\n";
        echo "\033[92mSuggestion:\033[0m {$highestMessage['suggested_improvement']}\n";
        echo str_repeat("=", 80) . "\n\n";
    }
    
    $totalMods = count($results);
    $totalMessages = array_sum(array_map(function($mod) { return count($mod['messages']); }, $results));
    
    echo "Summary:\n";
    echo "- Analyzed $totalMessages unique standard messages from $totalMods moderators\n";
    if ($filteredMessages > 0) {
        echo "- $filteredMessages messages exceeded threshold >= $threshold (shown above)\n";
    } else {
        echo "- No messages exceeded threshold >= $threshold\n";
        if ($highestScore > 0.0) {
            echo "- Highest improvement score: " . number_format($highestScore * 100, 1) . "%\n";
        }
    }
    echo "- Time period: Last $daysBack days\n\n";
    
    // Generate HTML report
    $htmlFilename = generateHtmlReport($results, $threshold, $daysBack, $totalMessages, $totalMods, $filteredMessages, $highestScore);
    echo "\033[92mHTML report generated: $htmlFilename\033[0m\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function generateHtmlReport($results, $threshold, $daysBack, $totalMessages, $totalMods, $filteredMessages, $highestScore) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "stdmsg_analysis_report_$timestamp.html";
    
    // Function to truncate text with expand/collapse
    function truncateText($text, $maxLength = 150, $id = '') {
        if (strlen($text) <= $maxLength) {
            return htmlspecialchars($text);
        }
        
        $truncated = substr($text, 0, $maxLength);
        $remaining = substr($text, $maxLength);
        
        return '<span>' . htmlspecialchars($truncated) . 
               '<span id="dots' . $id . '">...</span>' .
               '<span id="more' . $id . '" style="display:none;">' . htmlspecialchars($remaining) . '</span>' .
               ' <button onclick="toggleText(\'' . $id . '\')" id="btn' . $id . '" class="toggle-btn">Show more</button></span>';
    }
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Standard Message Analysis Report</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .summary {
            background: #ecf0f1;
            padding: 20px;
            border-radius: 6px;
            margin: 20px 0;
        }
        .message-table {
            border: 1px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
        }
        .message-row {
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }
        .message-row:hover {
            background: #f8f9fa;
        }
        .message-row:last-child {
            border-bottom: none;
        }
        .row-above-threshold {
            background: #fdf2f2;
            border-left: 4px solid #e74c3c;
        }
        .row-above-threshold:hover {
            background: #fce4ec;
        }
        .row-below-threshold {
            background: #fff;
        }
        .message-summary {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            cursor: pointer;
            user-select: none;
        }
        .score-display {
            font-weight: bold;
            font-size: 0.9em;
            padding: 4px 8px;
            border-radius: 12px;
            min-width: 50px;
            text-align: center;
            margin-right: 12px;
        }
        .row-above-threshold .score-display {
            background: #e74c3c;
            color: white;
        }
        .row-below-threshold .score-display {
            background: #27ae60;
            color: white;
        }
        .message-title {
            flex: 1;
            font-weight: 500;
            margin-right: 12px;
        }
        .moderator-name {
            color: #666;
            font-size: 0.9em;
            margin-right: 12px;
        }
        .expand-arrow {
            font-size: 0.8em;
            color: #999;
            transition: transform 0.2s;
        }
        .expanded .expand-arrow {
            transform: rotate(180deg);
        }
        .field-label {
            font-weight: bold;
            color: #2c3e50;
            margin-top: 15px;
            margin-bottom: 5px;
        }
        .current-text {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #6c757d;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .analysis {
            color: #c0392b;
            padding: 10px;
            background: #fdf2f2;
            border-radius: 4px;
        }
        .suggestion {
            color: #27ae60;
            padding: 10px;
            background: #eafaf1;
            border-radius: 4px;
        }
        .toggle-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 2px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8em;
        }
        .toggle-btn:hover {
            background: #2980b9;
        }
        .message-details {
            border-top: 1px solid #eee;
            background: #fafafa;
            padding: 20px;
            animation: slideDown 0.3s ease-out;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .detail-section {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #eee;
        }
        .full-width {
            grid-column: 1 / -1;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 500px;
            }
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Standard Message Analysis Report</h1>
        
        <div class="summary">
            <h3>Summary</h3>
            <table>
                <tr><td><strong>Analysis Date:</strong></td><td>' . date('Y-m-d H:i:s') . '</td></tr>
                <tr><td><strong>Time Period:</strong></td><td>Last ' . $daysBack . ' days</td></tr>
                <tr><td><strong>Threshold:</strong></td><td>' . $threshold . ' (' . ($threshold * 100) . '%)</td></tr>
                <tr><td><strong>Total Messages Analyzed:</strong></td><td>' . $totalMessages . '</td></tr>
                <tr><td><strong>Total Moderators:</strong></td><td>' . $totalMods . '</td></tr>
                <tr><td><strong>Messages Above Threshold:</strong></td><td>' . $filteredMessages . '</td></tr>';
    
    if ($highestScore > 0) {
        $html .= '<tr><td><strong>Highest Score:</strong></td><td>' . number_format($highestScore * 100, 1) . '%</td></tr>';
    }
    
    $html .= '</table>
        </div>';
    
    if (!empty($results)) {
        $html .= '<h2>📋 Detailed Analysis</h2>';
        $html .= '<div class="message-table">';
        
        // Flatten messages with moderator info and sort by score descending
        $allMessages = [];
        foreach ($results as $modResult) {
            foreach ($modResult['messages'] as $message) {
                $message['mod_name'] = $modResult['mod_name'];
                $message['mod_email'] = $modResult['mod_email'];
                $allMessages[] = $message;
            }
        }
        
        // Sort by improvement score descending (highest scores first)
        usort($allMessages, function($a, $b) {
            $scoreA = $a['improvement_score'] ?? 0.0;
            $scoreB = $b['improvement_score'] ?? 0.0;
            return $scoreB <=> $scoreA; // Descending order
        });
        
        $counter = 0;
        foreach ($allMessages as $message) {
            $counter++;
            $score = $message['improvement_score'] ?? 1.0;
            $scorePercent = number_format($score * 100, 1);
            
            // Determine if above threshold
            $aboveThreshold = $score >= $threshold;
            $rowClass = $aboveThreshold ? 'row-above-threshold' : 'row-below-threshold';
            
            $html .= '<div class="message-row ' . $rowClass . '" onclick="toggleMessageDetails(\'msg' . $counter . '\')">';
            $html .= '<div class="message-summary">';
            $html .= '<span class="score-display">' . $scorePercent . '%</span>';
            $html .= '<span class="message-title">' . htmlspecialchars($message['title']) . '</span>';
            $html .= '<span class="moderator-name">(' . htmlspecialchars($message['mod_name']) . ')</span>';
            $html .= '<span class="expand-arrow">▼</span>';
            $html .= '</div>';
            
            $html .= '<div class="message-details" id="msg' . $counter . '" style="display: none;">';
            $html .= '<div class="detail-grid">';
            
            $html .= '<div class="detail-section">';
            $html .= '<div class="field-label">Moderator:</div>';
            $html .= '<div>' . htmlspecialchars($message['mod_name']) . ' (' . htmlspecialchars($message['mod_email']) . ')</div>';
            $html .= '</div>';
            
            $html .= '<div class="detail-section">';
            $html .= '<div class="field-label">Message ID:</div>';
            $html .= '<div>' . htmlspecialchars($message['stdmsgid']) . '</div>';
            $html .= '</div>';
            
            $html .= '<div class="detail-section full-width">';
            $html .= '<div class="field-label">Current Text:</div>';
            $html .= '<div class="current-text">' . htmlspecialchars($message['old_text']) . '</div>';
            $html .= '</div>';
            
            $html .= '<div class="detail-section full-width">';
            $html .= '<div class="field-label">Analysis:</div>';
            $html .= '<div class="analysis">' . htmlspecialchars($message['analysis']) . '</div>';
            $html .= '</div>';
            
            $html .= '<div class="detail-section full-width">';
            $html .= '<div class="field-label">Suggestion (Rewritten):</div>';
            $html .= '<div class="suggestion">' . htmlspecialchars($message['suggested_improvement']) . '</div>';
            $html .= '</div>';
            
            $html .= '</div></div></div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '
    </div>
    
    <script>
        function toggleMessageDetails(id) {
            var details = document.getElementById(id);
            var row = details.closest(\'.message-row\');
            
            if (details.style.display === "none" || details.style.display === "") {
                details.style.display = "block";
                row.classList.add(\'expanded\');
            } else {
                details.style.display = "none";
                row.classList.remove(\'expanded\');
            }
        }
        
        // Add keyboard navigation
        document.addEventListener(\'keydown\', function(e) {
            if (e.key === \'Escape\') {
                // Close all expanded details
                var expandedRows = document.querySelectorAll(\'.message-row.expanded\');
                expandedRows.forEach(function(row) {
                    var details = row.querySelector(\'.message-details\');
                    details.style.display = \'none\';
                    row.classList.remove(\'expanded\');
                });
            }
        });
    </script>
</body>
</html>';
    
    file_put_contents($filename, $html);
    return $filename;
}

echo "\nAnalysis complete.\n";
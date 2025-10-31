<?php

namespace Freegle\Iznik;

use GeminiAPI\Client;
use GeminiAPI\Resources\Parts\TextPart;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

class GitSummaryAI {
    private $timestampFile = '/etc/iznik_commits_last_run';
    private $maxDaysBack = 7;
    private $geminiClient;

    private $repositories = [
        [
            'name' => 'Freegle Direct (User Website)',
            'url' => 'https://github.com/Freegle/iznik-nuxt3.git',
            'branch' => 'production',
            'category' => 'FD'
        ],
        [
            'name' => 'ModTools (Volunteer Website)',
            'url' => 'https://github.com/Freegle/iznik-nuxt3.git',
            'branch' => 'modtools',
            'category' => 'ModTools'
        ],
        [
            'name' => 'API v2 (Fast Server)',
            'url' => 'https://github.com/Freegle/iznik-server-go.git',
            'branch' => 'master',
            'category' => 'Backend'
        ],
        [
            'name' => 'API v1 (PHP Server)',
            'url' => 'https://github.com/Freegle/iznik-server.git',
            'branch' => 'master',
            'category' => 'Backend'
        ]
    ];

    public function __construct() {
        $this->geminiClient = new Client(GOOGLE_GEMINI_API_KEY);
    }

    /**
     * Get the best available Gemini model dynamically
     * Queries the API to find the latest flash model for speed and cost efficiency
     */
    private function getGeminiModel() {
        static $cachedModel = NULL;

        if ($cachedModel !== NULL) {
            return $cachedModel;
        }

        try {
            // List all available models from the API
            $response = $this->geminiClient->listModels();

            $flashModels = [];
            foreach ($response->models as $model) {
                $modelName = $model->name;

                // Look for flash models that support text generation
                if (stripos($modelName, 'flash') !== FALSE &&
                    stripos($modelName, 'gemini') !== FALSE &&
                    !stripos($modelName, 'exp') && // Exclude experimental
                    !stripos($modelName, 'vision')) { // Exclude vision-only models

                    // Check if it supports text generation
                    if (isset($model->supportedGenerationMethods) &&
                        in_array('generateContent', $model->supportedGenerationMethods)) {

                        // Extract version number for sorting (e.g., 2.0, 1.5)
                        $version = 0;
                        if (preg_match('/gemini-(\d+)\.(\d+)-flash/i', $modelName, $matches)) {
                            $version = floatval($matches[1] . '.' . $matches[2]);
                        }

                        $flashModels[] = [
                            'name' => $modelName,
                            'display' => $model->displayName ?? $modelName,
                            'version' => $version
                        ];
                    }
                }
            }

            if (empty($flashModels)) {
                // Fallback if no flash models found
                error_log("No flash models found, using fallback");
                $cachedModel = 'gemini-pro';
                return $cachedModel;
            }

            // Sort by version descending to get the latest
            usort($flashModels, function($a, $b) {
                return $b['version'] <=> $a['version'];
            });

            $selectedModel = $flashModels[0];

            // Remove 'models/' prefix if present
            $modelName = $selectedModel['name'];
            if (strpos($modelName, 'models/') === 0) {
                $modelName = substr($modelName, 7);
            }

            echo "Using model: {$selectedModel['display']} ($modelName)\n";

            $cachedModel = $modelName;
            return $cachedModel;

        } catch (\Exception $e) {
            error_log("Error listing models, using fallback: " . $e->getMessage());
            $cachedModel = 'gemini-pro';
            return $cachedModel;
        }
    }

    /**
     * Get the last run timestamp, defaulting to max days back
     */
    private function getLastRunTime($override = NULL) {
        if ($override !== NULL) {
            return strtotime($override);
        }

        if (file_exists($this->timestampFile)) {
            $lastRun = (int)file_get_contents($this->timestampFile);

            // Ensure we don't go back more than max days
            $maxBack = time() - ($this->maxDaysBack * 24 * 60 * 60);
            if ($lastRun < $maxBack) {
                $lastRun = $maxBack;
            }

            return $lastRun;
        }

        // Default to max days back
        return time() - ($this->maxDaysBack * 24 * 60 * 60);
    }

    /**
     * Save the current run timestamp
     */
    private function saveRunTime() {
        file_put_contents($this->timestampFile, time());
    }

    /**
     * Get commits and diffs for a repository since a given time
     */
    private function getRepositoryChanges($repoUrl, $branch, $since) {
        $tempDir = sys_get_temp_dir() . '/iznik_git_' . uniqid();
        mkdir($tempDir);

        try {
            // Clone the repository
            $cloneCmd = sprintf(
                'git clone --single-branch --branch %s %s %s 2>&1',
                escapeshellarg($branch),
                escapeshellarg($repoUrl),
                escapeshellarg($tempDir)
            );
            exec($cloneCmd, $output, $return);

            if ($return !== 0) {
                error_log("Failed to clone $repoUrl: " . implode("\n", $output));
                return NULL;
            }

            chdir($tempDir);

            // Get commits since the timestamp
            $sinceDate = date('Y-m-d H:i:s', $since);
            $logCmd = sprintf(
                'git log --since=%s --pretty=format:"%%H|%%an|%%ad|%%s" --date=short 2>&1',
                escapeshellarg($sinceDate)
            );
            exec($logCmd, $commits, $return);

            if (empty($commits)) {
                return NULL;
            }

            // Get the diff for all commits combined
            $firstCommit = explode('|', $commits[count($commits) - 1])[0];
            $lastCommit = explode('|', $commits[0])[0];

            $diffCmd = sprintf(
                'git diff --stat %s^..%s 2>&1',
                escapeshellarg($firstCommit),
                escapeshellarg($lastCommit)
            );
            exec($diffCmd, $statOutput, $return);

            // Get full diff (limited to avoid token limits)
            $diffCmd = sprintf(
                'git diff %s^..%s 2>&1',
                escapeshellarg($firstCommit),
                escapeshellarg($lastCommit)
            );
            exec($diffCmd, $diffOutput, $return);

            // Limit diff size to avoid token limits (approx 50k characters)
            $fullDiff = implode("\n", $diffOutput);
            if (strlen($fullDiff) > 50000) {
                $fullDiff = substr($fullDiff, 0, 50000) . "\n\n... (diff truncated due to size)";
            }

            return [
                'commits' => array_map(function($commit) {
                    $parts = explode('|', $commit, 4);
                    return [
                        'hash' => $parts[0],
                        'author' => $parts[1],
                        'date' => $parts[2],
                        'message' => $parts[3]
                    ];
                }, $commits),
                'stat' => implode("\n", $statOutput),
                'diff' => $fullDiff
            ];
        } finally {
            // Cleanup
            chdir('/tmp');
            exec(sprintf('rm -rf %s', escapeshellarg($tempDir)));
        }
    }

    /**
     * Summarize all changes across repositories holistically
     * This avoids repeating the same feature description when it spans multiple repos
     */
    private function summarizeAllChanges($allChanges) {
        $prompt = "You are summarizing code changes for readers familiar with Freegle (a UK community reuse network similar to Freecycle where people give away unwanted items and help each other).\n\n";

        $prompt .= "IMPORTANT: Many features require changes across multiple code repositories (frontend + backend). ";
        $prompt .= "When you see the same feature or fix mentioned in multiple repositories, describe the OVERALL PURPOSE once, not each technical implementation separately.\n\n";

        $prompt .= "The following repositories were updated:\n\n";

        foreach ($allChanges as $change) {
            $prompt .= "=== {$change['repo']} ({$change['category']}) ===\n";
            $prompt .= "Commits:\n";
            foreach ($change['commits'] as $commit) {
                $prompt .= "- {$commit['date']}: {$commit['message']}\n";
            }
            $prompt .= "\nFiles changed:\n{$change['stat']}\n\n";
        }

        $prompt .= "\n\nPlease provide a structured summary organized by user impact.\n\n";
        $prompt .= "Start with a brief intro paragraph (2-3 sentences) that:\n";
        $prompt .= "- Explains this is an AI-generated summary to make code changes easier to understand than raw git commits\n";
        $prompt .= "- Uses British English spelling and phrasing (e.g., 'organised' not 'organized', 'whilst' is acceptable)\n";
        $prompt .= "- Has a straightforward, matter-of-fact tone - not overly enthusiastic or promotional\n\n";

        $prompt .= "Then organise the changes into these sections:\n\n";
        $prompt .= "## FREEGLE DIRECT (Main Website for Members)\n";
        $prompt .= "List changes that affect regular users of the Freegle website (3-5 bullet points).\n";
        $prompt .= "IMPORTANT: Sort items by impact - put changes that affect the most users most significantly at the top of the list.\n\n";

        $prompt .= "## MODTOOLS (Volunteer Website)\n";
        $prompt .= "List changes that affect volunteers/moderators (3-5 bullet points).\n";
        $prompt .= "IMPORTANT: Sort items by impact - put changes that affect the most volunteers most significantly at the top of the list.\n\n";

        $prompt .= "## BACKEND SYSTEMS (Behind the scenes)\n";
        $prompt .= "List technical improvements that don't directly change the user interface (2-3 bullet points).\n";
        $prompt .= "IMPORTANT: Sort items by impact - put changes with the biggest effect on system performance or reliability at the top.\n\n";

        $prompt .= "Guidelines:\n";
        $prompt .= "- When a feature spans multiple repos (e.g., frontend + API), describe it ONCE under the appropriate user-facing category\n";
        $prompt .= "- Use simple, direct language. Avoid formal business speak. Say 'makes it easier' not 'ensuring a smoother experience'\n";
        $prompt .= "- Use active, plain language: 'We fixed' not 'has been resolved', 'You can now' not 'functionality has been enhanced'\n";
        $prompt .= "- Use British English spelling and phrasing throughout\n";
        $prompt .= "- Keep tone casual and straightforward, like explaining to a friend - not overly formal or corporate\n";
        $prompt .= "- Focus on WHAT changed, not HOW it was implemented technically\n";
        $prompt .= "- Identify prototype/experimental/investigation code clearly (look for test files, 'investigate', 'analyse', 'simulation', 'prototype' in commit messages or file paths)\n";
        $prompt .= "- When describing prototype work, say 'investigating', 'prototyping', 'testing approaches for' rather than implying it's live\n";
        $prompt .= "- If a category has no changes, say 'No changes in this period'\n";
        $prompt .= "- Be specific but concise - get straight to the point\n";
        $prompt .= "- Use bullet points starting with '-'\n\n";
        $prompt .= "Do not include repository names in your summary - just describe the changes by user impact.";

        try {
            $model = $this->getGeminiModel();

            $response = $this->geminiClient
                ->withV1BetaVersion()
                ->generativeModel($model)
                ->generateContent(
                    new TextPart($prompt)
                );

            return $response->text();
        } catch (\Exception $e) {
            error_log("Gemini API error: " . $e->getMessage());
            return "Error generating summary: " . $e->getMessage();
        }
    }

    /**
     * Generate the full report
     */
    public function generateReport($sinceOverride = NULL) {
        $since = $this->getLastRunTime($sinceOverride);
        $sinceDate = date('Y-m-d', $since);

        echo "Analyzing changes since $sinceDate...\n\n";

        // Collect all changes first
        $allChanges = [];
        foreach ($this->repositories as $repo) {
            echo "Processing {$repo['name']}...\n";

            $changes = $this->getRepositoryChanges($repo['url'], $repo['branch'], $since);

            if ($changes === NULL) {
                echo "  No changes found.\n";
                continue;
            }

            echo "  Found " . count($changes['commits']) . " commits.\n";

            $allChanges[] = [
                'repo' => $repo['name'],
                'category' => $repo['category'],
                'commits' => $changes['commits'],
                'stat' => $changes['stat'],
                'diff' => $changes['diff']
            ];
        }

        if (empty($allChanges)) {
            echo "No changes found in any repository.\n";
            return $this->buildEmptyEmail($sinceDate);
        }

        // Summarize all changes together with AI to handle cross-repo features
        echo "\nGenerating holistic summary with AI...\n";
        $summary = $this->summarizeAllChanges($allChanges);

        // Build the email
        $email = $this->buildEmail($summary, $sinceDate);

        // Save the run time
        if ($sinceOverride === NULL) {
            $this->saveRunTime();
        }

        return $email;
    }

    /**
     * Build the email content with AI-generated summary
     */
    private function buildEmail($aiSummary, $sinceDate) {
        $generatedDate = date('l, j F Y \a\t H:i');

        // Process line by line to properly handle different elements
        $lines = explode("\n", $aiSummary);
        $htmlLines = [];
        $inList = FALSE;
        $currentParagraph = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $currentParagraph . '</p>';
                    $currentParagraph = '';
                }
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = FALSE;
                }
                continue;
            }

            // Handle headings
            if (preg_match('/^## (.+)$/', $line, $matches)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $currentParagraph . '</p>';
                    $currentParagraph = '';
                }
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = FALSE;
                }
                $htmlLines[] = '<h2 style="color: #2c5282; margin-top: 24px; margin-bottom: 16px; font-size: 20px; display: block;">' . htmlspecialchars($matches[1]) . '</h2>';
                $htmlLines[] = '<div style="height: 8px;"></div>'; // Add spacing after heading
                continue;
            }

            if (preg_match('/^# (.+)$/', $line, $matches)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $currentParagraph . '</p>';
                    $currentParagraph = '';
                }
                if ($inList) {
                    $htmlLines[] = '</ul>';
                    $inList = FALSE;
                }
                $htmlLines[] = '<h1 style="color: #1a365d; margin-top: 24px; margin-bottom: 20px; font-size: 24px; display: block;">' . htmlspecialchars($matches[1]) . '</h1>';
                $htmlLines[] = '<div style="height: 8px;"></div>'; // Add spacing after heading
                continue;
            }

            // Handle bullet points
            if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
                if ($currentParagraph) {
                    $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $currentParagraph . '</p>';
                    $currentParagraph = '';
                }
                if (!$inList) {
                    $htmlLines[] = '<ul style="margin: 12px 0; padding-left: 24px; line-height: 1.6;">';
                    $inList = TRUE;
                }
                $htmlLines[] = '<li style="margin-bottom: 8px;">' . $matches[1] . '</li>';
                continue;
            }

            // Regular paragraph text
            if ($inList) {
                $htmlLines[] = '</ul>';
                $inList = FALSE;
            }
            if ($currentParagraph) {
                $currentParagraph .= ' ';
            }
            $currentParagraph .= $line;
        }

        // Close any remaining open elements
        if ($currentParagraph) {
            $htmlLines[] = '<p style="line-height: 1.6; margin-bottom: 12px;">' . $currentParagraph . '</p>';
        }
        if ($inList) {
            $htmlLines[] = '</ul>';
        }

        $htmlSummary = implode("\n", $htmlLines);

        $email = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h1 style="color: #1a365d; border-bottom: 3px solid #4299e1; padding-bottom: 12px; margin-bottom: 24px; font-size: 28px;">
            Freegle Code Changes Summary
        </h1>

        <div style="background-color: #ebf8ff; border-left: 4px solid #4299e1; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
            <p style="margin: 0; line-height: 1.6;">
                <strong>Changes since:</strong> $sinceDate<br>
                <strong>Generated:</strong> $generatedDate
            </p>
        </div>

        <div style="color: #2d3748;">
            $htmlSummary
        </div>

        <hr style="border: none; border-top: 2px solid #e2e8f0; margin: 32px 0;">

        <p style="color: #718096; font-size: 14px; line-height: 1.6; margin: 0;">
            If you have any questions about these changes, please reply to this post.
        </p>
    </div>
</body>
</html>
HTML;

        return $email;
    }

    /**
     * Build an empty email when there are no changes
     */
    private function buildEmptyEmail($sinceDate) {
        $generatedDate = date('l, j F Y \a\t H:i');

        $email = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: white; border-radius: 8px; padding: 32px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h1 style="color: #1a365d; border-bottom: 3px solid #4299e1; padding-bottom: 12px; margin-bottom: 24px; font-size: 28px;">
            Freegle Code Changes Summary
        </h1>

        <div style="background-color: #ebf8ff; border-left: 4px solid #4299e1; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
            <p style="margin: 0; line-height: 1.6;">
                <strong>Changes since:</strong> $sinceDate<br>
                <strong>Generated:</strong> $generatedDate
            </p>
        </div>

        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
            <p style="margin: 0; line-height: 1.6;">
                No code changes were found in any repository during this period.
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        return $email;
    }

    /**
     * Send the report via email
     */
    public function sendReport($sinceOverride = NULL, $emailOverride = NULL) {
        $report = $this->generateReport($sinceOverride);

        $subject = date('d-m-Y') . ' Freegle Code Changes Summary (AI Generated)';
        $to = $emailOverride ?: 'discoursereplies+Tech@ilovefreegle.org';
        $from = 'geeks@ilovefreegle.org';

        // Show email preview (first 500 characters of text content)
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "EMAIL PREVIEW\n";
        echo str_repeat('=', 70) . "\n";
        $textPreview = strip_tags($report);
        $textPreview = preg_replace('/\s+/', ' ', $textPreview);
        $textPreview = substr($textPreview, 0, 500);
        echo wordwrap($textPreview, 70) . "...\n";
        echo str_repeat('=', 70) . "\n";
        echo "To: $to\n";
        echo "Subject: $subject\n";
        echo "Format: HTML\n";
        echo str_repeat('=', 70) . "\n\n";

        // Write report to temporary file for reliable piping
        $tmpFile = tempnam(sys_get_temp_dir(), 'git_summary_');
        file_put_contents($tmpFile, $report);

        // Use mail command with HTML content type
        $mailCmd = sprintf(
            'mail -s %s -a %s -a %s -r %s %s < %s',
            escapeshellarg($subject),
            escapeshellarg('From: ' . $from),
            escapeshellarg('Content-Type: text/html; charset=UTF-8'),
            escapeshellarg($from),
            escapeshellarg($to),
            escapeshellarg($tmpFile)
        );

        exec($mailCmd, $output, $return);

        // Cleanup temp file
        unlink($tmpFile);

        if ($return === 0) {
            echo "We sent the email successfully to $to!\n";
        } else {
            echo "Failed to send email. Return code: $return\n";
            echo implode("\n", $output) . "\n";
        }
    }
}

// CLI handling
$options = getopt('s:e:h', ['since:', 'email:', 'help', 'dry-run']);

if (isset($options['h']) || isset($options['help'])) {
    echo "Usage: php git_summary_ai.php [options]\n\n";
    echo "Options:\n";
    echo "  -s, --since <date>    Override the last run time (format: YYYY-MM-DD or relative like '-3 days')\n";
    echo "  -e, --email <address> Override the recipient email address\n";
    echo "  --dry-run             Generate report but don't send email or update timestamp\n";
    echo "  -h, --help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php git_summary_ai.php                              # Normal run (uses stored timestamp)\n";
    echo "  php git_summary_ai.php --since 2025-01-01           # Override since date\n";
    echo "  php git_summary_ai.php --since '-3 days'            # Relative date\n";
    echo "  php git_summary_ai.php --email test@example.com     # Send to different address\n";
    echo "  php git_summary_ai.php --dry-run                    # Test without sending\n";
    exit(0);
}

$summaryGenerator = new GitSummaryAI();

$sinceOverride = $options['s'] ?? $options['since'] ?? NULL;
$emailOverride = $options['e'] ?? $options['email'] ?? NULL;

if (isset($options['dry-run'])) {
    echo $summaryGenerator->generateReport($sinceOverride);
} else {
    $summaryGenerator->sendReport($sinceOverride, $emailOverride);
}

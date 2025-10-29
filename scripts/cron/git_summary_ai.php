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
        $prompt .= "List changes that affect regular users of the Freegle website (3-5 bullet points).\n\n";

        $prompt .= "## MODTOOLS (Volunteer Website)\n";
        $prompt .= "List changes that affect volunteers/moderators (3-5 bullet points).\n\n";

        $prompt .= "## BACKEND SYSTEMS (Behind the scenes)\n";
        $prompt .= "List technical improvements that don't directly change the user interface (2-3 bullet points).\n\n";

        $prompt .= "Guidelines:\n";
        $prompt .= "- When a feature spans multiple repos (e.g., frontend + API), describe it ONCE under the appropriate user-facing category\n";
        $prompt .= "- Use straightforward language. Explain technical terms when needed (e.g., 'database' = 'where information is stored')\n";
        $prompt .= "- Use British English spelling and phrasing throughout\n";
        $prompt .= "- Avoid American phrases and excessive enthusiasm - keep tone factual and straightforward\n";
        $prompt .= "- Focus on WHAT changed, not HOW it was implemented technically\n";
        $prompt .= "- Identify prototype/experimental/investigation code clearly (look for test files, 'investigate', 'analyse', 'simulation', 'prototype' in commit messages or file paths)\n";
        $prompt .= "- When describing prototype work, say 'investigating', 'prototyping', 'testing approaches for' rather than implying it's live\n";
        $prompt .= "- If a category has no changes, say 'No changes in this period'\n";
        $prompt .= "- Be specific but concise\n";
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
        $email = "Freegle Code Changes Summary\n";
        $email .= "Changes since: $sinceDate\n";
        $email .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $email .= str_repeat('=', 70) . "\n\n";

        // The AI summary already includes the intro and sections
        $email .= $aiSummary . "\n\n";

        $email .= str_repeat('=', 70) . "\n\n";
        $email .= "If you have any questions about these changes, please reply to this email.\n";

        return $email;
    }

    /**
     * Build an empty email when there are no changes
     */
    private function buildEmptyEmail($sinceDate) {
        $email = "Freegle Code Changes Summary\n";
        $email .= "Changes since: $sinceDate\n";
        $email .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $email .= str_repeat('=', 70) . "\n\n";
        $email .= "No code changes were found in any repository during this period.\n\n";
        $email .= str_repeat('=', 70) . "\n";
        return $email;
    }

    /**
     * Send the report via email
     */
    public function sendReport($sinceOverride = NULL) {
        $report = $this->generateReport($sinceOverride);

        $subject = date('d-m-Y') . ' Freegle Code Changes Summary (AI Generated)';
        $to = 'discoursereplies+Tech@ilovefreegle.org';
        $from = 'From: geeks@ilovefreegle.org';

        // Use mail command
        $mailCmd = sprintf(
            'echo %s | mail -s %s %s -a%s',
            escapeshellarg($report),
            escapeshellarg($subject),
            escapeshellarg($to),
            escapeshellarg($from)
        );

        exec($mailCmd, $output, $return);

        if ($return === 0) {
            echo "Email sent successfully!\n";
        } else {
            echo "Failed to send email. Return code: $return\n";
            echo implode("\n", $output) . "\n";
        }
    }
}

// CLI handling
$options = getopt('s:h', ['since:', 'help', 'dry-run']);

if (isset($options['h']) || isset($options['help'])) {
    echo "Usage: php git_summary_ai.php [options]\n\n";
    echo "Options:\n";
    echo "  -s, --since <date>    Override the last run time (format: YYYY-MM-DD or relative like '-3 days')\n";
    echo "  --dry-run             Generate report but don't send email or update timestamp\n";
    echo "  -h, --help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php git_summary_ai.php                    # Normal run (uses stored timestamp)\n";
    echo "  php git_summary_ai.php --since 2025-01-01 # Override since date\n";
    echo "  php git_summary_ai.php --since '-3 days'  # Relative date\n";
    echo "  php git_summary_ai.php --dry-run          # Test without sending\n";
    exit(0);
}

$summaryGenerator = new GitSummaryAI();

$sinceOverride = $options['s'] ?? $options['since'] ?? NULL;

if (isset($options['dry-run'])) {
    echo $summaryGenerator->generateReport($sinceOverride);
} else {
    $summaryGenerator->sendReport($sinceOverride);
}

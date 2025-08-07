<?php
namespace Freegle\Iznik;

use GeminiAPI\Client;
use GeminiAPI\Enums\MimeType;
use GeminiAPI\Resources\ModelName;
use GeminiAPI\Resources\Parts\TextPart;

class ModBot extends Entity
{
    /** @var  $dbhr LoggedPDO */
    /** @var  $dbhm LoggedPDO */
    private $user;
    private $geminiClient;
    
    // Gemini 2.0 Flash-Lite pricing (as of 2025)
    const INPUT_TOKEN_COST = 0.10 / 1000000;  // $0.10 per 1M input tokens
    const OUTPUT_TOKEN_COST = 0.40 / 1000000; // $0.40 per 1M output tokens

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        
        $this->user = User::get($this->dbhr, $this->dbhm);
        $botUserId = $this->user->findByEmail(MODBOT_USER);
        error_log("ModBot user ID: " . $botUserId);
        if ($botUserId) {
            $this->user = User::get($this->dbhr, $this->dbhm, $botUserId);
        }
        
        // Initialize Gemini client
        $this->geminiClient = new Client(GOOGLE_GEMINI_API_KEY);
    }

    public function reviewPost($postId, $createMicrovolunteering = false, $returnDebugInfo = false, $skipModRightsCheck = false)
    {
        try {
            // 1) Check if modbot is a mod on a group associated with the post via messages_groups
            $groupsSql = "SELECT DISTINCT mg.groupid FROM messages_groups mg WHERE mg.msgid = ?";
            $groups = $this->dbhr->preQuery($groupsSql, [$postId]);
            
            if (empty($groups)) {
                return ['error' => 'no_groups', 'message' => 'Post not associated with any groups'];
            }
            
            // Check if modbot has moderator privileges on any of these groups
            $hasModerationRights = false;
            foreach ($groups as $group) {
                if ($this->user->isModOrOwner($group['groupid'])) {
                    $hasModerationRights = true;
                    break;
                }
            }
            
            if (!$hasModerationRights && !$skipModRightsCheck) {
                // Don't proceed with expensive AI review if no moderation rights
                return ['error' => 'no_moderation_rights', 'message' => 'ModBot does not have moderator rights on associated group ' . implode(', ', array_column($groups, 'groupid'))];
            }
            
            // If we don't have mod rights but are skipping the check, disable microvolunteering
            if (!$hasModerationRights && $skipModRightsCheck) {
                $createMicrovolunteering = false; // Force disable microvolunteering when no mod rights
            }
            
            // 2) Get the subject and body of the message
            $messageSql = "SELECT subject, textbody FROM messages WHERE id = ?";
            $messageResult = $this->dbhr->preQuery($messageSql, [$postId]);
            
            if (empty($messageResult)) {
                return ['error' => 'message_not_found', 'message' => 'Message not found in database'];
            }
            
            $message = $messageResult[0];
            $subject = $message['subject'];
            $body = $message['textbody'];
            
            // 3) Get the rules JSON object from the group rules property
            $groupId = $groups[0]['groupid']; // Use first group for rules
            $g = Group::get($this->dbhr, $this->dbhm, $groupId);
            $groupData = $g->getPublic();
            $rules = json_decode($groupData['rules'], true);
            
            if (!$rules) {
                $rules = [];
            }
            
            // 4) Construct a prompt to detect whether or not the post breaches the rule
            $prompt = $this->constructRulePrompt($subject, $body, $rules);
            
            // 5) Pass the subject/body and rules into Gemini with retry logic
            $result = $this->callGeminiWithRetry($prompt);
            
            // Calculate cost estimation for this request
            $costInfo = $this->estimateCost($prompt, $result);
            
            // Clean up the response to extract JSON
            $result = trim($result);
            if (strpos($result, '```json') !== false) {
                $result = preg_replace('/```json\s*/', '', $result);
                $result = preg_replace('/\s*```/', '', $result);
            }
            
            // 6) Return array of possible rules breached with probability for each
            $violations = json_decode($result, true);
            
            if (!is_array($violations)) {
                return [];
            }
            
            // Debug logging for all rule probabilities (now we should get ALL rules)
            $debugInfo = [];
            if (!empty($violations)) {
                foreach ($violations as $violation) {
                    $rule = $violation['rule'] ?? 'unknown';
                    $prob = $violation['probability'] ?? 0;
                    $debugInfo[] = "$rule:" . number_format($prob * 100, 1) . "%";
                }
            } else {
                error_log("ModBot error for post $postId: Gemini returned empty array (no rule analysis)");
            }
            
            // Filter violations based on rule-specific thresholds
            $ruleConfig = $this->getRuleDescriptions();
            $filteredViolations = [];
            $filteredOut = [];
            
            foreach ($violations as $ruleAnalysis) {
                $ruleKey = $ruleAnalysis['rule'] ?? '';
                $probability = $ruleAnalysis['probability'] ?? 0;
                
                if (isset($ruleConfig[$ruleKey])) {
                    $threshold = $ruleConfig[$ruleKey]['threshold'];
                    
                    // Only include as violations those that meet or exceed their specific threshold
                    if ($probability >= $threshold) {
                        $filteredViolations[] = $ruleAnalysis;
                    } else {
                        // Log all below-threshold detections
                        $filteredOut[] = "$ruleKey:" . number_format($probability * 100, 1) . "%<" . number_format($threshold * 100, 0) . "%";
                    }
                }
            }
            
            if (!empty($filteredViolations)) {
                $passedInfo = [];
                foreach ($filteredViolations as $violation) {
                    $rule = $violation['rule'] ?? 'unknown';
                    $prob = $violation['probability'] ?? 0;
                    $passedInfo[] = "$rule:" . number_format($prob * 100, 1) . "%";
                }
                error_log("ModBot violations above threshold for post $postId: " . implode(', ', $passedInfo));
            }
            
            // Create microvolunteering entry if requested
            if ($createMicrovolunteering && $this->user->getId()) {
                $this->createMicrovolunteeringEntry($postId, $filteredViolations, $violations);
            }
            
            // Return debug info if requested
            if ($returnDebugInfo) {
                return [
                    'violations' => $filteredViolations,
                    'debug' => [
                        'raw_violations' => $violations,
                        'filtered_out' => $filteredOut
                    ],
                    'cost_info' => $costInfo
                ];
            }
            
            // Return violations with cost info
            return [
                'violations' => $filteredViolations,
                'cost_info' => $costInfo
            ];
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Log quota exhaustion errors clearly
            if (strpos($errorMessage, 'exceeded your current quota') !== false) {
                error_log("ModBot: Daily API quota exhausted for post $postId");
            } else {
                error_log("ModBot exception for post $postId (returnDebugInfo=$returnDebugInfo): " . $errorMessage);
            }
            
            // Don't log verbose rate limit errors
            $isRateLimit = strpos($errorMessage, '429') !== false || 
                          strpos($errorMessage, 'RESOURCE_EXHAUSTED') !== false ||
                          strpos($errorMessage, 'quota') !== false;
            
            if (!$isRateLimit) {
                error_log("ModBot error: " . $errorMessage);
            }
            
            // Return debug format if requested, even on error
            if ($returnDebugInfo) {
                return [
                    'violations' => [],
                    'debug' => [
                        'raw_violations' => [],
                        'filtered_out' => [],
                        'error' => $errorMessage
                    ],
                    'cost_info' => [
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'input_cost' => 0,
                        'output_cost' => 0,
                        'total_cost' => 0
                    ]
                ];
            }
            
            return [
                'violations' => [],
                'cost_info' => [
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'input_cost' => 0,
                    'output_cost' => 0,
                    'total_cost' => 0
                ]
            ];
        }
    }
    
    /**
     * Estimate token count for text (rough approximation)
     * Rule of thumb: ~4 characters per token for English text
     */
    private function estimateTokens($text) {
        return ceil(strlen($text) / 4);
    }
    
    /**
     * Calculate estimated cost for a request
     */
    public function estimateCost($inputText, $outputText) {
        $inputTokens = $this->estimateTokens($inputText);
        $outputTokens = $this->estimateTokens($outputText);
        
        $inputCost = $inputTokens * self::INPUT_TOKEN_COST;
        $outputCost = $outputTokens * self::OUTPUT_TOKEN_COST;
        
        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'total_cost' => $inputCost + $outputCost
        ];
    }
    
    private function callGeminiWithRetry($prompt, $maxRetries = 3)
    {
        $attempt = 0;
        $baseDelay = 1; // Start with 1 second delay
        
        while ($attempt < $maxRetries) {
            try {
                $response = $this->geminiClient->withV1BetaVersion()
                    ->generativeModel('gemini-2.0-flash-lite')
                    ->withSystemInstruction(
                        'You are a community moderator assistant analyzing posts for rule violations. ' .
                        'Analyze the provided post against the community rules and return your assessment in JSON format. ' .
                        'Be thorough but fair in your analysis. Consider context and intent, not just literal rule matching.'
                    )
                    ->generateContent(new TextPart($prompt));
                    
                return $response->text();
                
            } catch (\Exception $e) {
                $attempt++;
                $errorMessage = $e->getMessage();
                
                // Check if it's a rate limit error (429)
                $isRateLimit = strpos($errorMessage, '429') !== false || 
                              strpos($errorMessage, 'RESOURCE_EXHAUSTED') !== false ||
                              strpos($errorMessage, 'quota') !== false;
                
                if ($isRateLimit && $attempt < $maxRetries) {
                    // Check if it's a quota exhaustion (permanent until reset) vs rate limit (temporary)
                    if (strpos($errorMessage, 'exceeded your current quota') !== false || 
                        strpos($errorMessage, 'FreeTier') !== false) {
                        // Daily quota exhausted - no point retrying
                        error_log("ModBot: Daily API quota exhausted. No retries will help until quota resets.");
                        throw $e;
                    }
                    
                    // For temporary rate limits, use exponential backoff or suggested delay
                    $delay = $baseDelay * pow(2, $attempt - 1);
                    
                    // Check if API suggests a specific retry delay
                    if (preg_match('/"retryDelay":\s*"(\d+)s"/', $errorMessage, $matches)) {
                        $delay = max($delay, intval($matches[1]));
                    }
                    
                    // Just log a dot for rate limit retries to keep output clean
                    echo ".";
                    sleep($delay);
                    continue;
                }
                
                throw $e;
            }
        }
        
        // This shouldn't be reached, but just in case
        throw new \Exception("ModBot: Maximum retry attempts ($maxRetries) exceeded for Gemini API call");
    }
    
    private function constructRulePrompt($subject, $body, $rules)
    {
        $ruleConfig = $this->getRuleDescriptions();
        $activeRules = [];
        
        foreach ($rules as $ruleKey => $ruleValue) {
            if (isset($ruleConfig[$ruleKey]) && $ruleValue) {
                $activeRules[] = [
                    'key' => $ruleKey,
                    'description' => $ruleConfig[$ruleKey]['description'],
                    'value' => $ruleValue,
                    'threshold' => $ruleConfig[$ruleKey]['threshold']
                ];
            }
        }
        
        $prompt = "Please analyze this community post for potential rule violations:\n\n";
        $prompt .= "SUBJECT: " . $subject . "\n\n";
        $prompt .= "BODY: " . $body . "\n\n";
        $prompt .= "COMMUNITY RULES TO CHECK:\n";
        
        if (empty($activeRules)) {
            $prompt .= "No specific rules are configured for this group.\n\n";
        } else {
            foreach ($activeRules as $rule) {
                $thresholdPercent = number_format($rule['threshold'] * 100, 0);
                $prompt .= "- " . $rule['key'] . " (threshold: {$thresholdPercent}%): " . $rule['description'] . "\n";
            }
        }
        
        $prompt .= "\nAnalyze the post and estimate the probability that it violates each of the above rules.\n\n";
        $prompt .= "Please return a JSON array with one object for EACH rule listed above, containing:\n";
        $prompt .= "- 'rule': the exact rule key from the list above\n";
        $prompt .= "- 'probability': a number between 0.0 and 1.0 indicating likelihood of violation (0.0 = definitely no violation, 1.0 = definitely violates)\n";
        $prompt .= "- 'reason': brief explanation of your assessment\n\n";
        $prompt .= "You must return an entry for every rule listed above, even if the probability is 0.0.\n";
        $prompt .= "Be thorough but conservative - only assign high probabilities when you're confident there's a violation.\n";
        $prompt .= "Ignore test posts. Don't consider misinterpretations. Be accurate with your probability assessments.";
        
        return $prompt;
    }
    
    private function createMicrovolunteeringEntry($postId, $violations, $allRuleAnalysis = [])
    {
        try {
            // Determine result based on violations found
            $hasHighProbabilityViolations = false;
            $maxProbability = 0;
            $primaryViolation = '';
            
            foreach ($violations as $violation) {
                $probability = $violation['probability'] ?? 0;
                if ($probability > $maxProbability) {
                    $maxProbability = $probability;
                    $primaryViolation = $violation['rule'] ?? '';
                }
                if ($probability > 0.5) {
                    $hasHighProbabilityViolations = true;
                }
            }
            
            // Build rule probability summary for probabilities > 0
            $ruleProbabilities = [];
            foreach ($allRuleAnalysis as $analysis) {
                $rule = $analysis['rule'] ?? '';
                $prob = $analysis['probability'] ?? 0;
                if ($prob > 0) {
                    $ruleProbabilities[] = $rule . ':' . number_format($prob * 100, 1) . '%';
                }
            }
            $probSummary = !empty($ruleProbabilities) ? ' [' . implode(', ', $ruleProbabilities) . ']' : '';
            
            // Determine result and category
            if (empty($violations)) {
                $result = MicroVolunteering::RESULT_APPROVE;
                $msgcategory = null; // No category needed for approved posts
                $comments = "AI analysis found no rule violations. Post appears appropriate for the community." . $probSummary;
            } elseif ($hasHighProbabilityViolations) {
                $result = MicroVolunteering::RESULT_REJECT;
                $msgcategory = MicroVolunteering::MSGCATEGORY_SHOULDNT_BE_HERE;
                $comments = "AI analysis detected high-probability rule violations including: $primaryViolation (probability: " . number_format($maxProbability * 100, 1) . "%). Post may need moderation." . $probSummary;
            } elseif ($maxProbability > 0.2) {
                $result = MicroVolunteering::RESULT_REJECT;
                $msgcategory = MicroVolunteering::MSGCATEGORY_COULD_BE_BETTER;
                $comments = "AI analysis detected potential rule violations including: $primaryViolation (probability: " . number_format($maxProbability * 100, 1) . "%). Post could be improved or clarified." . $probSummary;
            } else {
                $result = MicroVolunteering::RESULT_APPROVE;
                $msgcategory = MicroVolunteering::MSGCATEGORY_NOT_SURE;
                $comments = "AI analysis detected minor potential issues but overall post appears acceptable. Manual review recommended if needed." . $probSummary;
            }
            
            // Create microvolunteering entry
            $this->dbhm->preExec(
                "INSERT INTO microactions (actiontype, userid, msgid, result, msgcategory, comments, version) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = ?, comments = ?, version = ?, msgcategory = ?;",
                [
                    MicroVolunteering::CHALLENGE_CHECK_MESSAGE,
                    $this->user->getId(),
                    $postId,
                    $result,
                    $msgcategory,
                    $comments,
                    MicroVolunteering::VERSION,
                    $result,
                    $comments,
                    MicroVolunteering::VERSION,
                    $msgcategory
                ]
            );
            
        } catch (\Exception $e) {
            error_log("ModBot microvolunteering entry creation error: " . $e->getMessage());
        }
    }
    
    private function getRuleDescriptions()
    {
        return [
//            'fullymoderated' => ['description' => 'All posts must be approved by moderators before appearing', 'threshold' => 0.5],
//            'requirefirstpostoffer' => ['description' => 'First post must be an offer, not a wanted', 'threshold' => 0.5],
//            'limitgroups' => ['description' => 'Limit number of groups a member can join', 'threshold' => 0.5],
//            'restrictcrossposting' => ['description' => 'Restrict posting the same item to multiple groups', 'threshold' => 0.5],
            'animalswanted' => ['description' => 'No wanted posts for animals/pets allowed; accessories are fine', 'threshold' => 0.7],
            'weapons' => ['description' => 'No weapons, knives, or dangerous items allowed.', 'threshold' => 0.8],
            'tickets' => ['description' => 'No ticket sales or transfers allowed', 'threshold' => 0.6],
            'medicationsprescription' => ['description' => 'No prescription medications allowed', 'threshold' => 0.8],
            'contactlenses' => ['description' => 'No contact lenses allowed', 'threshold' => 0.9],
            'tobacco' => ['description' => 'No tobacco products allowed', 'threshold' => 0.7],
            'alcohol' => ['description' => 'No alcohol allowed', 'threshold' => 0.6],
            'chineselanterns' => ['description' => 'No Chinese lanterns allowed', 'threshold' => 0.9],
            'underwear' => ['description' => 'No underwear or intimate items allowed', 'threshold' => 0.8],
            'cosmetics' => ['description' => 'No cosmetics or beauty products allowed', 'threshold' => 0.5],
            'food' => ['description' => 'No food items allowed', 'threshold' => 0.6],
            'businessads' => ['description' => 'No business advertising or commercial posts', 'threshold' => 0.7],
            'spam' => ['description' => 'No spam or repetitive posting', 'threshold' => 0.8],
            'offtopic' => ['description' => 'Posts must be related to giving/receiving items', 'threshold' => 0.6],
            'personal' => ['description' => 'No personal information sharing', 'threshold' => 0.8],
            'politics' => ['description' => 'No political content allowed', 'threshold' => 0.7],
            'religion' => ['description' => 'No religious content allowed', 'threshold' => 0.7],
            'charity' => ['description' => 'No charity fundraising posts', 'threshold' => 0.6],
            'money' => ['description' => 'No requests for money or financial assistance', 'threshold' => 0.8],
            'services' => ['description' => 'No service offers or requests', 'threshold' => 0.6],
            'jobs' => ['description' => 'No job postings or employment offers', 'threshold' => 0.7],
            'housing' => ['description' => 'No housing/accommodation offers or requests', 'threshold' => 0.7],
            'transport' => ['description' => 'No transport or travel arrangements', 'threshold' => 0.7],
            'lost' => ['description' => 'No lost or found items posts', 'threshold' => 0.8],
            'stolen' => ['description' => 'No posts about stolen items', 'threshold' => 0.9]
        ];
    }
}
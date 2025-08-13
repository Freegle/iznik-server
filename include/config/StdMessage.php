<?php
namespace Freegle\Iznik;


class StdMessage extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'configid', 'title', 'action', 'subjpref', 'subjsuff', 'body', 'rarelyused',
        'autosend', 'newmodstatus', 'newdelstatus', 'edittext', 'insert');

    var $settableatts = array('configid', 'title', 'action', 'subjpref', 'subjsuff', 'body', 'rarelyused',
        'autosend', 'newmodstatus', 'newdelstatus', 'edittext', 'insert');

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $fetched = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'mod_stdmsgs', 'stdmsg', $this->publicatts, $fetched);
        $this->log = new Log($dbhr, $dbhm);
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($title, $cid) {
        try {
            $rc = $this->dbhm->preExec("INSERT INTO mod_stdmsgs (title, configid) VALUES (?,?)", [$title,$cid]);
            $id = $this->dbhm->lastInsertId();
        } catch (\Exception $e) {
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'mod_stdmsgs', 'stdmsg', $this->publicatts);
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $createdby = $me ? $me->getId() : NULL;
            $this->log->log([
                'type' => Log::TYPE_CONFIG,
                'subtype' => Log::SUBTYPE_CREATED,
                'byuser' => $createdby,
                'configid' => $cid,
                'stdmsgid' => $id,
                'text' => "StdMsg: $title"
            ]);

            return($id);
        } else {
            return(NULL);
        }
    }

    public function setAttributes($settings) {
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        parent::setAttributes($settings);

        $this->log->log([
            'type' => Log::TYPE_STDMSG,
            'subtype' => Log::SUBTYPE_EDIT,
            'stdmsgid' => $this->id,
            'configid' => $this->stdmsg['configid'],
            'byuser' => $me ? $me->getId() : NULL,
            'text' => $this->getEditLog($settings)
        ]);
    }

    public function getPublic($stdmsgbody = TRUE) {
        $ret = $this->getAtts($this->publicatts);

        if (!$stdmsgbody) {
            # We want to save space.
            unset($ret['body']);
        }
        return($ret);
    }

    public function canModify() {
        $c = new ModConfig($this->dbhr, $this->dbhm, $this->stdmsg['configid']);
        return($c->canModify());
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM mod_stdmsgs WHERE id = ?;", [$this->id]);
        if ($rc) {
            $me = Session::whoAmI($this->dbhr, $this->dbhm);
            $this->log->log([
                'type' => Log::TYPE_STDMSG,
                'subtype' => Log::SUBTYPE_DELETED,
                'byuser' => $me ? $me->getId() : NULL,
                'configid' => $this->stdmsg['configid'],
                'stdmsgid' => $this->id,
                'text' => "StdMsg; " . $this->stdmsg['title'],
            ]);
        }

        return($rc);
    }

    public function analyzeStandardMessages($daysBack = 90, $showProgress = FALSE, $verbose = FALSE, $threshold = 0.3) {
        require_once(IZNIK_BASE . '/include/ai/ModBot.php');
        
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysBack} days"));
        
        $stdmsgUsageSql = "
            SELECT DISTINCT 
                l.stdmsgid,
                s.body,
                s.title,
                l.byuser as mod_id
            FROM logs l
            INNER JOIN mod_stdmsgs s ON l.stdmsgid = s.id
            WHERE l.timestamp >= ?
            AND l.stdmsgid IS NOT NULL
            AND l.byuser IS NOT NULL
            ORDER BY l.stdmsgid, l.byuser
        ";
        
        $stdmsgUsages = $this->dbhr->preQuery($stdmsgUsageSql, [$cutoffDate]);
        
        if ($showProgress) {
            $uniqueStdmsgids = array_unique(array_column($stdmsgUsages, 'stdmsgid'));
            $totalUniqueMessages = count($uniqueStdmsgids);
            echo "Found $totalUniqueMessages unique standard messages to analyze...\n";
        }
        
        // Group unique messages for parallel processing
        $uniqueMessages = [];
        $messageToMods = [];
        
        foreach ($stdmsgUsages as $usage) {
            $stdmsgid = $usage['stdmsgid'];
            
            if (!isset($uniqueMessages[$stdmsgid])) {
                $uniqueMessages[$stdmsgid] = [
                    'stdmsgid' => $stdmsgid,
                    'body' => $usage['body'],
                    'title' => $usage['title']
                ];
                $messageToMods[$stdmsgid] = [];
            }
            
            $messageToMods[$stdmsgid][] = [
                'id' => $usage['mod_id'],
                'name' => $this->getModName($usage['mod_id']),
                'email' => $this->getModEmail($usage['mod_id'])
            ];
        }
        
        if ($showProgress) {
            echo "Processing " . count($uniqueMessages) . " unique messages in parallel batches of 10...\n";
        }
        
        $results = [];
        $analysisResults = $this->analyzeMessagesInParallel(array_values($uniqueMessages), $verbose, $threshold, $showProgress);
        
        // Combine analysis results with moderator data
        $processedCount = 0;
        foreach ($uniqueMessages as $stdmsgid => $message) {
            $analysis = $analysisResults[$processedCount];
            $processedCount++;
            
            $score = $analysis['improvement_score'] ?? 1.0;
            
            if ($verbose && isset($analysis['raw_response'])) {
                echo "\n--- Debug Output for Message ID $stdmsgid ---\n";
                echo "Title: " . $message['title'] . "\n";
                echo "Current Text:\n" . wordwrap($message['body'], 70) . "\n\n";
                echo "Raw Gemini Response:\n";
                echo $analysis['raw_response'] . "\n";
                echo "--- End Debug Output ---\n\n";
            }
            
            // Output message immediately if it meets threshold
            if ($score >= $threshold) {
                $scorePercent = number_format($score * 100, 1);
                $firstMod = $messageToMods[$stdmsgid][0];
                
                echo "\n\033[91m🚨 NEEDS IMPROVEMENT (Score: $scorePercent%) 🚨\033[0m\n";
                echo "\033[94mModerator:\033[0m {$firstMod['name']} ({$firstMod['email']})\n";
                echo "\033[94mStandard Message ID:\033[0m $stdmsgid\n";
                echo "\033[94mTitle:\033[0m " . $message['title'] . "\n";
                echo "\033[93mCurrent Text:\033[0m\n";
                echo wordwrap($message['body'], 70) . "\n\n";
                echo "\033[91mAnalysis:\033[0m " . $analysis['analysis'] . "\n";
                echo "\033[92mSuggestion:\033[0m " . $analysis['suggestion'] . "\n";
                echo str_repeat("=", 80) . "\n";
            }
            
            $messageResult = [
                'stdmsgid' => $stdmsgid,
                'title' => $message['title'],
                'old_text' => $message['body'],
                'analysis' => $analysis['analysis'],
                'suggested_improvement' => $analysis['suggestion'],
                'improvement_score' => $score,
                'mods' => $messageToMods[$stdmsgid]
            ];
            
            if ($verbose && isset($analysis['raw_response'])) {
                $messageResult['raw_response'] = $analysis['raw_response'];
            }
            
            $results[$stdmsgid] = $messageResult;
        }
        
        if ($showProgress) {
            echo "Grouping results by moderator...\n";
        }
        
        $groupedResults = [];
        foreach ($results as $result) {
            foreach ($result['mods'] as $mod) {
                $modId = $mod['id'];
                if (!isset($groupedResults[$modId])) {
                    $groupedResults[$modId] = [
                        'mod_id' => $modId,
                        'mod_name' => $mod['name'],
                        'mod_email' => $mod['email'],
                        'messages' => []
                    ];
                }
                
                $messageData = [
                    'stdmsgid' => $result['stdmsgid'],
                    'title' => $result['title'],
                    'old_text' => $result['old_text'],
                    'analysis' => $result['analysis'],
                    'suggested_improvement' => $result['suggested_improvement'],
                    'improvement_score' => $result['improvement_score']
                ];
                
                if (isset($result['raw_response'])) {
                    $messageData['raw_response'] = $result['raw_response'];
                }
                
                $groupedResults[$modId]['messages'][] = $messageData;
            }
        }
        
        return $groupedResults;
    }

    private function analyzeMessageWithGemini($messageBody, $verbose = FALSE) {
        $modBot = new \Freegle\Iznik\ModBot($this->dbhr, $this->dbhm);
        
        $prompt = $this->constructQualityAnalysisPrompt($messageBody);
        
        try {
            $rawResponse = $modBot->callGemini($prompt);
            
            $response = trim($rawResponse);
            if (strpos($response, '```json') !== FALSE) {
                $response = preg_replace('/```json\s*/', '', $response);
                $response = preg_replace('/\s*```/', '', $response);
            }
            
            $analysis = json_decode($response, TRUE);
            
            if (!$analysis || !isset($analysis['analysis']) || !isset($analysis['suggestion'])) {
                $result = [
                    'analysis' => 'Unable to analyze message quality',
                    'suggestion' => 'Manual review recommended',
                    'improvement_score' => 1.0
                ];
                if ($verbose) {
                    $result['raw_response'] = $rawResponse;
                }
                return $result;
            }
            
            if ($verbose) {
                $analysis['raw_response'] = $rawResponse;
            }
            
            return $analysis;
            
        } catch (\Exception $e) {
            error_log("StdMessage Gemini analysis error: " . $e->getMessage());
            $result = [
                'analysis' => 'Error analyzing message: ' . $e->getMessage(),
                'suggestion' => 'Manual review required due to analysis error',
                'improvement_score' => 1.0
            ];
            if ($verbose) {
                $result['raw_response'] = 'Error: ' . $e->getMessage();
            }
            return $result;
        }
    }

    private function constructQualityAnalysisPrompt($messageBody) {
        $prompt = "Please analyze this standard message text that moderators send to community members:\n\n";
        $prompt .= "MESSAGE TEXT:\n";
        $prompt .= $messageBody . "\n\n";
        $prompt .= "IMPORTANT: Substitution strings using $ (like \$firstname, \$groupname, \$subject) are expected and will be replaced with actual values when sent. ";
        $prompt .= "These substitutions, especially when used in greetings (e.g., 'Hi \$firstname') or sign-offs (e.g., 'Thanks, \$moderatorname'), indicate a more friendly and personalized tone.\n\n";
        $prompt .= "CONTEXT: This is for Freegle, a UK-based community reuse network. References to 'Freegle', UK place names (cities, towns, regions), and British terminology are normal and appropriate. ";
        $prompt .= "These should not be flagged as confusing or inappropriate.\n\n";
        $prompt .= "DO NOT suggest including email addresses in messages - these are sent via the platform and email contact is handled separately.\n\n";
        $prompt .= "FREEGLE MESSAGE STYLE: Follow Freegle's philosophy that 'members appreciate a personal, friendly approach, not a corporate memo!' Based on analysis of actual Freegle sample messages, follow these patterns:\n\n";
        $prompt .= "OPENINGS: Use warm, informal greetings like 'Hi there', 'Hi \$firstname' - never formal business language\n";
        $prompt .= "TONE: Be empathetic first - acknowledge member intent/feelings before addressing issues\n";
        $prompt .= "APPROACH: Always solution-oriented - offer alternatives, next steps, or constructive guidance\n";
        $prompt .= "LANGUAGE: Use collaborative phrases like 'Would you like us to...' or 'Can I check whether...'\n";
        $prompt .= "BOUNDARIES: Explain policies gently without being harsh - 'I'm sorry, but...' then explain why\n";
        $prompt .= "GUIDANCE: Provide specific, actionable advice rather than vague instructions\n";
        $prompt .= "LANGUAGE SIMPLICITY: Use simple, everyday words that everyone understands - avoid formal or complex words when shorter, common alternatives exist (e.g. 'use' not 'utilize', 'help' not 'assist', 'show' not 'demonstrate')\n";
        $prompt .= "ACTIVE VOICE: Use active voice and direct requests - say what you're asking the person to do rather than describing abstract rules (e.g. 'Please edit your post' not 'Posts should be edited', 'You can try again tomorrow' not 'Posts may be resubmitted')\n";
        $prompt .= "COMMUNITY FOCUS: Reference group benefits, use group names, emphasize collective good\n";
        $prompt .= "CLOSINGS: Professional but warm - 'Kind regards', include volunteer/group names via substitutions\n\n";
        $prompt .= "Analyze this message for communication quality issues including:\n";
        $prompt .= "a) Too long for an email to a member of the public\n";
        $prompt .= "b) Confusingly worded or unclear language\n";
        $prompt .= "c) Missing empathetic acknowledgment of member intent before addressing issues\n";
        $prompt .= "d) Uses jargon, technical terms, or unnecessarily complex words when simpler alternatives exist\n";
        $prompt .= "e) Lacks solution-oriented approach - no alternatives, next steps, or constructive guidance\n";
        $prompt .= "f) Missing collaborative language like 'Would you like us to...' or 'Can I check...'\n";
        $prompt .= "g) Too harsh when explaining boundaries - should be gentle with 'I'm sorry, but...' approach\n";
        $prompt .= "h) Vague guidance instead of specific, actionable advice\n";
        $prompt .= "i) Uses passive voice or abstract rules instead of direct requests (e.g. 'Posts should be...' instead of 'Please...')\n";
        $prompt .= "j) Doesn't emphasize community benefit or collective good\n";
        $prompt .= "k) Contains grammatical errors or typos\n";
        $prompt .= "l) Structure makes it hard to scan or understand quickly\n\n";
        
        $prompt .= "Please respond with a JSON object containing:\n";
        $prompt .= "- 'analysis': A human-readable paragraph explaining any problems found\n";
        $prompt .= "- 'suggestion': Either a complete rewritten version that follows Freegle's style (warm opening, empathetic acknowledgment, solution-oriented approach, collaborative language, gentle boundaries, specific guidance, community focus, warm closing), or 'No improvements needed' if the message is fine. IMPORTANT: Format suggested messages with separate paragraphs for better readability - use double line breaks (\\n\\n) between paragraphs.\n";
        $prompt .= "- 'improvement_score': A decimal number from 0.0 to 1.0 indicating how much improvement is needed (0.0 = perfect, no improvement needed; 1.0 = major improvements required)\n\n";
        $prompt .= "Focus on Freegle's authentic sample message patterns: empathetic first, solution-oriented always, collaborative language, gentle boundary-setting, and community-focused messaging.\n";
        $prompt .= "Be constructive and specific in your feedback. Remember the core principle: 'members appreciate a personal, friendly approach, not a corporate memo!'";
        
        return $prompt;
    }

    private function getModName($userId) {
        $user = new User($this->dbhr, $this->dbhm, $userId);
        return $user->getName();
    }

    private function getModEmail($userId) {
        $user = new User($this->dbhr, $this->dbhm, $userId);
        return $user->getEmailPreferred();
    }

    private function analyzeMessagesInParallel($messages, $verbose = FALSE, $threshold = 0.3, $showProgress = FALSE) {
        $batchSize = 10;
        $results = [];
        $totalMessages = count($messages);
        
        // Process messages in batches of 10
        for ($i = 0; $i < $totalMessages; $i += $batchSize) {
            $batch = array_slice($messages, $i, $batchSize);
            $batchNumber = intval($i / $batchSize) + 1;
            $totalBatches = ceil($totalMessages / $batchSize);
            
            if ($showProgress) {
                echo "Processing batch $batchNumber/$totalBatches (" . count($batch) . " messages)...\n";
            }
            
            // Process batch using curl_multi for parallel requests
            $batchResults = $this->processBatchWithCurl($batch, $verbose);
            
            // Add batch results to overall results
            $results = array_merge($results, $batchResults);
            
            if ($showProgress) {
                $completed = min($i + $batchSize, $totalMessages);
                echo "Completed $completed/$totalMessages messages\n";
            }
            
            // Small delay between batches to be respectful to the API
            if ($i + $batchSize < $totalMessages) {
                usleep(500000); // 0.5 second delay between batches
            }
        }
        
        return $results;
    }

    private function processBatchWithCurl($messages, $verbose = FALSE) {
        $modBot = new \Freegle\Iznik\ModBot($this->dbhr, $this->dbhm);
        $results = [];
        
        // For now, process sequentially but with the structure for parallel processing
        // This can be enhanced with actual curl_multi implementation
        foreach ($messages as $message) {
            $analysis = $this->analyzeMessageWithGemini($message['body'], $verbose);
            $results[] = $analysis;
            
            // Small delay between individual requests within batch
            usleep(100000); // 0.1 second delay between requests
        }
        
        return $results;
    }
}
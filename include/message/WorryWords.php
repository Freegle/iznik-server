<?php
namespace Freegle\Iznik;



use GeoIp2\Database\Reader;
use LanguageDetection\Language;

class WorryWords {
    CONST TYPE_REGULATED = 'Regulated';     // UK regulated substance
    CONST TYPE_REPORTABLE = 'Reportable';   // UK reportable substance
    CONST TYPE_MEDICINE = 'Medicine';       // Medicines/supplements.
    CONST TYPE_REVIEW = 'Review';           // Just needs looking at.
    CONST TYPE_ALLOWED = 'Allowed';           // Just needs looking at.

    /** @var  $dbhr LoggedPDO */
    private $dbhr;

    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $groupid = NULL;

    private $words = NULL;

    function __construct($dbhr, $dbhm, $groupid = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->groupid = $groupid;
        $this->log = new Log($this->dbhr, $this->dbhm);
    }

    public function checkMessage($id, $fromuser, $subject, $textbody, $log = TRUE) {
        $this->getWords();
        $ret = NULL;
        $foundword = [];

        foreach ([ $subject, $textbody ] as $scan) {
            foreach ($this->words as $worryword) {
                if ($worryword['type'] === WorryWords::TYPE_ALLOWED) {
                    $scan = str_ireplace($worryword['keyword'], '', $scan);
                }
            }

            # Check for literal occurrences of worrywords with spaces, which are phrases.
            foreach ($this->words as $worryword) {
                if ($worryword['type'] !== WorryWords::TYPE_ALLOWED &&
                    strpos($worryword['keyword'], ' ') !== false &&
                    (stripos($subject, $worryword['keyword']) !== false || stripos(
                            $textbody,
                            $worryword['keyword']
                        ) !== false)
                ) {
                    if ($log) {
                        $this->log->log(
                            [
                                'type' => Log::TYPE_MESSAGE,
                                'subtype' => Log::SUBTYPE_WORRYWORDS,
                                'user' => $fromuser,
                                'msgid' => $id,
                                'text' => "Found '{$worryword['keyword']}' type {$worryword['type']}"
                            ]
                        );
                    }

                    if ($ret === null) {
                        $ret = [];
                    }

                    $ret[] = [
                        'word' => $scan,
                        'worryword' => $worryword,
                    ];

                    $foundword[$worryword['keyword']] = true;
                }
            }

            $words = preg_split("/\b/", $scan);

            foreach ($words as $word) {
                $word = trim($word);

                foreach ($this->words as $worryword) {
                    if (!Utils::pres($worryword['keyword'], $foundword) && $worryword['type'] !== WorryWords::TYPE_ALLOWED && strlen($worryword['keyword'])) {
                        # Check that words are roughly the same length, and allow more fuzziness as the word length increases.
                        $ratio = strlen($word) / strlen($worryword['keyword']);

                        # Fuzzy matching is causing more problems than it's worth.
                        # $len = strlen($word);
                        # $threshold =  ($len > 7) ? 3 : ($len > 4 ? 2 : 1);
                        $threshold = 1;

                        if (($ratio >= 0.75 && $ratio <= 1.25) && @levenshtein(strtolower($worryword['keyword']), strtolower($word)) < $threshold) {
                            # Close enough to be worrying.
                            if ($log) {
                                $this->log->log([
                                    'type' => Log::TYPE_MESSAGE,
                                    'subtype' => Log::SUBTYPE_WORRYWORDS,
                                    'user' => $fromuser,
                                    'msgid' => $id,
                                    'text' => "Found '{$worryword['keyword']}' type {$worryword['type']} in '$word'"
                                ]);
                            }

                            if ($ret === NULL) {
                                $ret = [];
                            }

                            $ret[] = [
                                'word' => $word,
                                'worryword' => $worryword,
                            ];

                            $foundword[$worryword['keyword']] = TRUE;
                        }
                    }
                }
            }

            if ($ret) {
                $ret = array_unique($ret, SORT_REGULAR);
            }
        }

        return($ret);
    }

    private function getWords() {
        if (!$this->words) {
            $this->words = $this->dbhr->preQuery("SELECT * FROM worrywords;");

            if ($this->groupid) {
                # Get the group-specific worry words.
                $g = Group::get($this->dbhr, $this->dbhm, $this->groupid);
                $spammers = $g->getSetting('spammers', NULL);

                if ($spammers && Utils::pres('worrywords', $spammers)) {
                    $words = explode(',', $spammers['worrywords']);

                    foreach ($words as $word) {
                        $this->words[] = [
                            'type' => WorryWords::TYPE_REVIEW,
                            'keyword' => strtolower(trim($word))
                        ];
                    }
                }
            }
        }
    }
}
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

    private $words = NULL;

    function __construct($dbhr, $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
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

            $words = preg_split("/[\s,]+/", $scan);

            foreach ($words as $word) {
                foreach ($this->words as $worryword) {
                    if (!Utils::pres($worryword['keyword'], $foundword) && $worryword['type'] !== WorryWords::TYPE_ALLOWED) {
                        # Check that words are roughly the same length, and allow more fuzziness as the word length increases.
                        $ratio = strlen($word) / strlen($worryword['keyword']);
                        $len = strlen($word);

                        # Fuzzy matching is causing more problems than it's worth.
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
        }
    }
}
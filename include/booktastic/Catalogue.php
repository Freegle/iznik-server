<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
use Elasticsearch\ClientBuilder;

class Catalogue
{
    /** @var  $dbhr LoggedPDO */
    var $dbhr;
    /** @var  $dbhm LoggedPDO */
    var $dbhm;

    const CONFIDENCE = 75;
    const BLUR = 10;

    private $client = NULL;
    private $start = NULL;
    private $logging = FALSE;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->start = microtime(TRUE);

        foreach (['lewis', 'lawrence', 'lanchester'] AS $name) {
            $dbhm->preExec("INSERT IGNORE INTO booktastic_lastnames (lastname) VALUES (?);", [
                $name
            ]);
        }

        foreach (['john'] AS $name) {
            $dbhm->preExec("INSERT IGNORE INTO booktastic_firstnames (firstname) VALUES (?);", [
                $name
            ]);
        }

        $this->client = ClientBuilder::create()
            ->setHosts([
                'db-1.ilovefreegle.org:9200'
            ])
            ->build();
    }

    private function log($str) {
        if ($this->logging) {
            error_log($str);
        }
    }
    
    public function doOCR(Attachment $a, $data, $video) {
        return $a->ocr($data, TRUE, $video);
    }

    public function doObject(Attachment $a, $data) {
        return $a->objects($data);
    }

    public function extractPossibleBooks($data, $uid) {
        # If we have a uid then we might have seen this before.  This is used in UT to avoid hitting
        # Google all the time.
        $already = [];

        if ($uid) {
            $already = $this->dbhr->preQuery("SELECT id, objects FROM booktastic_objects WHERE uid = ?;", [
                $uid
            ], FALSE, FALSE);
        }

        #$this->log("Check for uid $uid = " . count($already));
        if (!count($already)) {
            $a = new Attachment($this->dbhr, $this->dbhm);
            $objects = $this->doObject($a, $data);
            $this->dbhm->preExec("INSERT INTO booktastic_objects (data, objects, uid) VALUES (?, ?, ?);", [
                $data,
                json_encode($objects),
                $uid
            ]);

            $id = $this->dbhm->lastInsertId();
        } else {
            $id = $already[0]['id'];
            $objects = $already[0]['objects'];
        }

        return [ $id, $objects ];
    }

    public function ocr($data, $uid = NULL, $video = FALSE) {
        # If we have a uid then we might have seen this before.  This is used in UT to avoid hitting
        # Google all the time.
        $already = [];

        if ($uid) {
            $already = $this->dbhr->preQuery("SELECT id, text FROM booktastic_ocr WHERE uid = ?;", [
                $uid
            ], FALSE, FALSE);
        }

        #$this->log("Check for uid $uid = " . count($already));
        if (!count($already)) {
            #$this->log("Not got it");
            $a = new Attachment($this->dbhr, $this->dbhm);
            $text = $this->doOCR($a, $data, $video);
            $this->dbhm->preExec("INSERT INTO booktastic_ocr (data, text, uid) VALUES (?, ?, ?);", [
                $data,
                json_encode($text),
                $uid
            ]);

            $id = $this->dbhm->lastInsertId();
        } else {
            $id = $already[0]['id'];
            $text = $already[0]['text'];
            #$this->log("Already got $id");
        }

        return [ $id, $text ];
    }

    public function getOverallOrientation($fragments) {
        # Work out whether the orientation of the image is horizontal(ish) or vertical(ish).
        # Which way is this oriented determines which way we project.
        $xtot = 0;
        $ytot = 0;
        $minx = PHP_INT_MAX;
        $maxx = -PHP_INT_MAX;
        $miny = PHP_INT_MAX;
        $maxy = -PHP_INT_MAX;



        foreach ($fragments as $j) {
            $vertices = $j['boundingPoly']['vertices'];

            $xtot += abs(presdef('x', $vertices[1], 0) - presdef('x', $vertices[0], 0));
            $ytot += abs(presdef('y', $vertices[1], 0) - presdef('y', $vertices[0], 0));

            $minx = min($minx, presdef('x', $vertices[0], 0));
            $maxx = max($maxx, presdef('x', $vertices[0], 0));
            $miny = min($miny, presdef('y', $vertices[0], 0));
            $maxy = max($maxy, presdef('y', $vertices[0], 0));
        }

        $horizontalish = $xtot >= $ytot;

        $height = $horizontalish ? ($maxx - $minx) : ($maxy - $miny);

        return [ $horizontalish, $height ];
    }

    public function identifySpinesFromOCR($id) {
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT * FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);

        foreach ($ocrdata as $o) {
            # The first item is a Google's assembly of the text.  This is useful because it puts \n between
            # items which it thinks are less related, and it does a good job of it.  That means that we
            # have a head start on what is likely to be on a single spine.  The rest of the entries are the
            # individual words.
            $fragments = json_decode($o['text'], TRUE);

            $summary = $fragments[0]['description'];
            $lines = explode("\n", $summary);

            array_shift($fragments);

            # Annotate the fragments with whether they are related.
            $fragindex = 0;

            for($spineindex = 0; $spineindex < count($lines) && $fragindex < count($fragments); $spineindex++) {
                $words = explode(' ', $lines[$spineindex]);

                foreach ($words as $word) {
                    if (strcmp($word, $fragments[$fragindex]['description']) === 0) {
                        $this->log("{$fragments[$fragindex]['description']} in spine $spineindex");
                        $fragments[$fragindex++]['spineindex'] = $spineindex;
                    } else {
                        error_log("Mismatch $word vs " . json_encode($fragments[$fragindex]));
                    }
                }
            }

            $spines = [];
            foreach ($lines as $line) {
                $spines[] = [
                    'spine' => $line,
                    'author' => NULL,
                    'title' => NULL
                ];
            }

            $ret = [ $spines, $fragments ];
        }

        $this->log("Spines " . var_export($ret, TRUE));
        return $ret;
    }

    private function compare($str1, $str2) {
        if (strlen($str1) > 255 || strlen($str2) > 255) {
            # Too long.
            return 0;
        }

        $lenratio = strlen($str1) / strlen($str2);

        if (
            (strpos($str1, $str2) !== FALSE || strpos($str2, $str1) !== FALSE) &&
            ($lenratio >= 0.5 && $lenratio <= 2))
        {
            # One inside the other is pretty good as long as they're not too different in length.
            $pc = self::CONFIDENCE;
        } else {
            # See how close they are as strings.
            $dist = levenshtein($str1, $str2);
            $pc = 100 - 100 * $dist / max(strlen($str1), strlen($str2));
        }

        return $pc;
    }

    private function canonTitle($title) {
        # The catalogues are erratic about whether articles are included.  Remove them for comparison.
        $origtitle = $title;

        foreach (['the', 'a'] as $word) {
            $title = preg_replace('/(\b|$)' . preg_quote($word) . '(\b|$)/i', '', $title);
        }

        # Remove all except alphabetic.
        $title = preg_replace('/[^a-zA-Z]/', '', $title);

        $this->log("$origtitle => $title");
        return $title;
    }

    private function search($author, $title, $fuzziness = 0, $sorted = FALSE) {
        $authkey = ($sorted ? 'sortedauthor' : 'author');
        $titkey = ($sorted ? 'sortedtitle' : 'title');

        # Any numbers in an author are junk.
        $author2 = trim(preg_replace('/[0-9]/', '', $author));

        if (strlen($author2) < 5) {
            # Implausibly short.  Reject.
            $this->log("Reject too short author $author");
            return NULL;
        } else {
            $author = $author2;
        }

        # There are some titles which are very short, but they are more likely to just be false junk.
        if (strlen($title) < 4) {
            $this->log("Reject as too short title $title");
            return NULL;
        }

        # Sometimes we get a dash where it should be a dot, which confuses things.
        $author = str_replace('-', ' ', strtolower($author));
        $title = strtolower($title);

        # First search on the assumption that the OCR is pretty good, and we just have a little fuzziness.  Allow
        # significantly more on the title because it can get cluttered up with publisher info, and it's also
        # longer so there will be more errors.
        $res = $this->client->search([
            'index' => 'booktastic',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                                [ 'fuzzy' => [ $authkey => [ 'value' => $author, 'fuzziness' => 3 ] ] ],
                                [ 'fuzzy' => [ $titkey => [ 'value' => $title, 'fuzziness' => 20 ] ] ],
                        ]
                    ]
                ]
            ],
            'size' => 50,
        ]);

        if ($res['hits']['total'] > 0) {
            $this->log("Found very close match ");
            $ret = $res['hits']['hits'][0];
        } else {
            # Now search just by author, and do our own custom matching.
            $res = $this->client->search([
                'index' => 'booktastic',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                [ 'fuzzy' => [ $authkey => [ 'value' => $author, 'fuzziness' => 2] ] ]
                            ]
                        ]
                    ]
                ],
                'size' => 500,
            ]);

            $ret = NULL;
            $titlebest = 0;

            $this->log("Search for $author, $title returned " . json_encode($res));

            if ($res['hits']['total'] > 0) {
                foreach ($res['hits']['hits'] as $hit) {
                    $hitauthor = strtolower($hit['_source'][$authkey]);
                    $hittitle = strtolower($hit['_source'][$titkey]);

                    if (strlen($hitauthor) > 5 && strlen($hittitle)) {
                        # If one appears inside the other then it's a match.  This can happen if there's extra
                        # stuff like publisher info.  The title is more likely to have that because the author
                        # generally comes first.
                        if (strpos("$author $title", "$hitauthor $hittitle") !== FALSE) {
                            $this->log("Search for $author - $title returned ");
                            $this->log("...matched inside $hitauthor - $hittitle");
                            $ret = $hit;
                        } else {
                            $authperc = $this->compare($author, $hitauthor);
                            $this->log("Searched for $author - $title, Consider author $hitauthor $authperc% $hittitle");

                            if ($authperc >= self::CONFIDENCE) {
                                # Looks like the author.  Don't find the best author match - this is close enough
                                # for it to be worth us scanning the books.
                                $authbest = $authperc;

                                # The title we have OCR'd is more likely to have guff on the end, such as the
                                # publisher.  So compare upto the length of the candidate title.
                                $canontitle = $this->canonTitle($title);
                                $canonhittitle = $this->canonTitle($hittitle);

                                if (strlen($canontitle) && strlen($canonhittitle)) {
                                    $titperc = $this->compare($canontitle, $canonhittitle);
                                    $p = strpos("$canontitle", "$canonhittitle");
                                    $this->log("...$hitauthor - $hittitle $titperc%, $p");

                                    if ($titperc >= self::CONFIDENCE && $titperc >= $titlebest) {
                                        $titlebest = $titperc;
                                        $this->log("Search for $author - $title returned ");
                                        $this->log("...matched $hitauthor - $hittitle $titperc%");
                                        $ret = $hit;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $ret;
    }

    public function searchForSpines($id, &$spines, &$fragments, $flag = TRUE) {
        # We want to search for the spines in ElasticSearch, where we have a list of authors and books.
        #
        # The spine will normally in in the format "Author Title" or "Title Author".  So we can work our
        # way along the words in the spine searching for matches on this.
        $ret = [];

        for ($spineindex = 0; $spineindex < count($spines); $spineindex++) {
            $spine = $spines[$spineindex];
            
            $res = NULL;

            $words = explode(' ', $spine['spine']);
            $this->log((microtime(TRUE) - $this->start) . " Consider spine {$spine['spine']} words " . count($words));

            for ($i = 0; !$res && $i < count($words) - 1; $i++) {
                # Try matching assuming the author is at the start.
                $author = trim(implode(' ', array_slice($words, 0, $i + 1)));
                $title = trim(implode(' ', array_slice($words, $i + 1)));
                $res = $this->search($author, $title, 2);

                if ($res) {
                    #$this->log("...no author title matches");
                }
            }

            for ($i = 0; !$res && $i < count($words) - 1; $i++) {
                # Try matching assuming the author is at the end.
                $title = trim(implode(' ', array_slice($words, 0, $i + 1)));
                $author = trim(implode(' ', array_slice($words, $i + 1)));
                $res = $this->search($author, $title, 2);

                if (!$res) {
                    #$this->log("...no title author matches");
                }
            }

            if ($res) {
                # We found one for this spine.
                $spines[$spineindex]['author'] = $res['_source']['author'];
                $spines[$spineindex]['title'] = $res['_source']['title'];
                $spines[$spineindex]['viafid'] = $res['_source']['viafid'];
                $this->log("FOUND: {$spines[$spineindex]['author']} - {$spines[$spineindex]['title']}");

                if ($flag) {
                    $this->flagUsed($fragments, $spineindex);
                }
            }
        }
    }

    public function searchForPermutedSpines($id, $spines, &$fragments) {
        # Search the different orders of these these spines.
        $this->log("Search for permuted spines " . json_encode($spines));
        $permuted = $this->permute($spines);

        # We have a string with lots of words.  We want to search for all the different author title combinations
//        # this might have.  We have sortedauthor/sortedtitle in the db, so we only care about the combinations
//        # with unique values of those.
//        $words = explode(' ', $text);
//        $this->log("Filter words");
//        $words = array_filter($words, function($a) {
//            return strlen($a);
//        });
//        $this->log((microtime(TRUE) - $this->start) . " Permute words");
//        $permuted = $this->permute($words);
//        $this->log((microtime(TRUE) - $this->start) . " Permuted");

        $searched = [];
        $search = 0;
        $skipped = 0;
        $res = NULL;

        foreach ($permuted as $permute) {
            $words = explode(' ', implode(' ', $permute));
            $this->log("Filter words");
            $words = array_filter($words, function($a) {
                return strlen($a);
            });

            $this->log("Consider permutation " . json_encode($words));

            for ($i = 0; !$res && $i < count($words) - 1; $i++) {
                $author = trim(implode(' ', array_slice($words, 0, $i + 1)));
                $title = trim(implode(' ', array_slice($words, $i + 1)));
                $sortedauthor = $this->sortstring($author);
                $sortedtitle = $this->sortstring($title);

                $key = "$sortedauthor - $sortedtitle";
                $this->log("Consider permute search $key");
                if (!array_key_exists($key, $searched)) {
                    $this->log((microtime(TRUE) - $this->start) . " New search $key");
                    $search++;
                    $searched[$key] = TRUE;
                    $ret = $this->search($author, $title, 2);

                    if ($ret) {
                        $res = $ret['_source'];
                        $res['spine'] = "{$res['author']} {$res['title']}";
                    }
                } else {
                    $this->log("Already searched");
                    $skipped++;
                }
            }
        }

        $this->log("Generated $search and skipped $skipped and found " . json_encode($res));
        return $res;
    }

    private function flagUsed(&$fragments, $spineindex) {
        $this->log("flag used $spineindex");
        for ($fragindex = 0; $fragindex < count($fragments); $fragindex++) {
            if ($fragments[$fragindex]['spineindex'] == $spineindex) {
                $this->log("...found in {$fragments[$fragindex]['description']}");
                $fragments[$fragindex]['used'] = TRUE;
            }
        }
    }

    // From https://stackoverflow.com/questions/10222835/get-all-permutations-of-a-php-array
    private function permute($items, $perms = [],&$ret = []) {
        if (empty($items)) {
            $ret[] = $perms;
        } else {
            for ($i = count($items) - 1; $i >= 0; --$i) {
                $newitems = $items;
                $newperms = $perms;
                list($foo) = array_splice($newitems, $i, 1);
                array_unshift($newperms, $foo);
                $this->permute($newitems, $newperms,$ret);
            }
        }

        return $ret;
    }

    private function sortstring($string,$unique = false) {
        $string = str_replace('.', '', $string);
        $array = explode(' ',strtolower($string));
        if ($unique) $array = array_unique($array);
        sort($array);
        return implode(' ',$array);
    }

    public function searchForBrokenSpines($id, &$spines, &$fragments) {
        # Up to this point we've relied on what Google returns on a single line.  We will have found
        # some books via that route.  But it's common to have the author on one line, and the book on another,
        # or other variations which result in text on a single spine being split.
        #
        # Ideally we'd search all permutations of all the words.  But this is expensive - if we had 7
        #
        # So loop through all cases where we have adjacent spines not matched.  Do this initially for 2 adjacent
        # spines, then increase - we've seen some examples where a single title can end up on several lines.
        $ret = [];

        for ($adjacent = 2; $adjacent <= 3; $adjacent++) {
            $i = 0;

            while ($i < count($spines) - 1) {
                $thisone = $spines[$i];
                $this->log("Consider broken spine {$thisone['spine']} at $i length $adjacent");

                $blank = TRUE;
                $healed = [];

                for ($j = $i; $j < count($spines) && $j - $i + 1 <= $adjacent; $j++) {
                    if (pres('used', $spines[$j]) || $spines[$j]['author']) {
                        $blank = FALSE;
                        break;
                    } else {
                        $healed[] = $spines[$j]['spine'];
                    }
                }

                if ($blank) {
                    # We want to search all possible orders of these words, which would be quite a lot of searches.
                    # But in elastic we have sortedauthor and sorted title, which allows us to shortcut.
                    $this->log("Enough blanks");
                    $comspined = $this->searchForPermutedSpines($id, $healed, $fragments);

                    if ($comspined) {
                        # It worked.  Use these slots up.
                        $this->log("Merge spines as $i length $adjacent for {$comspined['author']}");
                        $this->mergeSpines($spines, $fragments, $comspined, $i, $adjacent);
                        $this->log("Merged, flag");
                        $this->flagUsed($fragments, $i);
                    }
                } else {
                    $this->log("Not enough blanks");
                }

                $i++;
            }
        }
    }

    private function mergeSpines(&$spines, &$fragments, $comspined, $start, $length) {
        # We have combined multiple adjacent spines into a single one, possibly with some
        # reordering of text.
        $spines[$start] = $comspined;

        # Renumber the spine indexes in the fragments we are removing..
        for ($fragindex = 0; $fragindex < count($fragments); $fragindex++) {
            if ($fragments[$fragindex]['spineindex'] > $start && $fragments[$fragindex]['spineindex'] <= $start + $length - 1) {
                # These are the ones we're merging.
                $this->log("Fragment {$fragments[$fragindex]['description']} is part of merge");
                $fragments[$fragindex]['spineindex'] = $start;
            } else if ($fragments[$fragindex]['spineindex'] > $start + $length - 1) {
                # These are above.
                $fragments[$fragindex]['spineindex'] -= ($length - 1);
                $this->log("Fragment {$fragments[$fragindex]['description']} is above merge");
            }
        }

        array_splice($spines, $start + 1, $length - 1);
    }

    public function recordResults($id, $spines) {
        $score = 0;

        foreach ($spines as $r) {
            if ($r['author']) {
                $score++;
            }
        }

        $this->dbhm->preExec("INSERT INTO booktastic_results (ocrid, results, score) VALUES (?, ?, ?);", [
            $id,
            json_encode($spines),
            count($spines) ? round(100 * $score / count($spines)) : 0
        ]);
    }

    public function validAuthor($name) {
        # First check in our local cache of known authors.
        $knowns = $this->dbhr->preQuery("SELECT * FROM booktastic_authors WHERE name LIKE ?;", [
            $name
        ]);

        if (count($knowns)) {
            return TRUE;
        }

        # Also look (more slowly) for minor issues with OCR which get a character or two wrong.
        $knowns = $this->dbhr->preQuery("SELECT * FROM booktastic_authors WHERE damlevlim(LOWER(name), ?, " . strlen($name) . ") < 2;", [
            $name
        ]);

        if (count($knowns)) {
            return TRUE;
        }

        # Look for existing queries.
        $existing = $this->dbhr->preQuery("SELECT id, results FROM booktastic_search_author WHERE search LIKE ?;", [
            $name
        ]);

        if (count($existing)) {
            # We have a cached copy.
            $data = $existing[0]['results'];
        } else {
            # We need to query.
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "https://api2.isbndb.com/author/" . urlencode($name) . "?page=1&pageSize=1000");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: " . ISBNDB_KEY));

            $data = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($status == 200 && $data) {
                $this->dbhm->preExec("INSERT INTO booktastic_search_author (search, results) VALUES (?, ?);", [
                    $name,
                    $data
                ]);
            }
        }

        # Sometimes we get backslash characters back which make us fail.
        $data = str_replace('\\', '', $data);

        # Sometimes the quoting is wrong, e.g. "publisher":"Izd-vo "Shchit-M"",
        $data = preg_replace_callback('/(.*\:")(.*)(",)/', function ($m) {
            #$this->log("Callback " . var_export($m, true));
            return $m[1] . str_replace('"', '\"', $m[2]) . $m[3];
        }, $data);

        $json = json_decode($data, TRUE);

        if (!$json) {
            $this->log("JSON decode for $name failed " . json_last_error_msg());
        }

        return pres('author', $json);
    }
}

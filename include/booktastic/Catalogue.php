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

    public function clean($str) {
        # Clean a string to remove OCR errors by matching against known names and words.
        $words = explode(' ', $str);
        
        foreach ($words as $index => $word) {
            if (strlen($word)) {
                $knownwords = $this->dbhr->preQuery("SELECT * FROM booktastic_words WHERE word LIKE ?;", [
                    $word
                ], FALSE, FALSE);

                if (count($knownwords)) {
                    continue;
                }

                $firstnames = $this->dbhr->preQuery("SELECT * FROM booktastic_firstnames WHERE firstname LIKE ?;", [
                    $word
                ], FALSE, FALSE);

                if (count($firstnames)) {
                    continue;
                }

                $lastnames = $this->dbhr->preQuery("SELECT * FROM booktastic_lastnames WHERE lastname LIKE ?;", [
                    $word
                ], FALSE, FALSE);

                if (count($lastnames)) {
                    continue;
                }

                # Didn't match exactly.  Look for a close match.
                $knownwords = $this->dbhr->preQuery("SELECT booktastic_words.*, DAMLEVLIM(word, " . $this->dbhr->quote($word) . ", " . strlen($word) . ") AS dist FROM `booktastic_words` HAVING dist < 2 ORDER BY dist ASC, LENGTH(word) ASC;", NULL, FALSE, FALSE);

                if (count($knownwords)) {
                    $words[$index] = $knownwords[0]['word'];
                    continue;
                }

                $firstnames = $this->dbhr->preQuery("SELECT booktastic_firstnames.*, DAMLEVLIM(firstname, " . $this->dbhr->quote($word) . ", " . strlen($word) . ") AS dist FROM `booktastic_firstnames` HAVING dist < 2 ORDER BY dist ASC, LENGTH(firstname) ASC;", NULL, FALSE, FALSE);

                if (count($firstnames)) {
                    $words[$index] = $firstnames[0]['firstname'];
                    continue;
                }

                $lastnames = $this->dbhr->preQuery("SELECT booktastic_lastnames.*, DAMLEVLIM(lastname, " . $this->dbhr->quote($word) . ", " . strlen($word) . ") AS dist FROM `booktastic_lastnames` HAVING dist < 2 ORDER BY dist ASC, LENGTH(lastname) ASC;", NULL, FALSE, FALSE);

                if (count($lastnames)) {
                    $words[$index] = $lastnames[0]['lastname'];
                    continue;
                }
            }
        }

        $ret = implode(' ', $words);
        if ($ret != $str) {
            $this->log("Cleaned $str => $ret");
        }

        return $ret;
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
        if (!count($already) || $video) {
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

            foreach ($lines as $key => $line) {
                #$lines[$key] = $this->clean($line);
            }

            $spines = [];
            foreach ($lines as $line) {
                foreach (['"', "'"] as $remove) {
                    $line = str_replace($remove, '', $line);
                }

                $spines[] = [
                    'spine' => $line,
                    'author' => NULL,
                    'title' => NULL
                ];
            }

            $spines = array_filter($spines, function($s) {
                return strlen($s['spine']);
            });

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

        #error_log("Compare $str1 vs $str2 returns $pc");

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

    private function removeArticles($title) {
        foreach ([ 'the ', 'a '] as $article) {
            $title = str_ireplace($article, '', $title);
        }

        return trim($title);
    }

    private function normalizeTitle($title) {
        # Remove any articles at the start of the title, as the catalogues are inconsistent about whether they
        # are included.
        $title = $this->removeArticles($title);

        # Some books have a subtitle, and the catalogues are inconsistent about whether that's included.
        $p = strpos($title, ':');

        if ($p !== FALSE && $p > 0 && $p < strlen($title)) {
            $title = trim(substr($title, 0, $p - 1));
        }

        return $title;
    }

    private function normalizeAuthor($author) {
        # Any numbers in an author are junk.
        $author = trim(preg_replace('/[0-9]/', '', $author));

        # Remove Dr. as this isn't always present.
        $author = trim(preg_replace('/Dr./', '', $author));
        return $author;
    }

    private function search($author, $title, $fuzziness = 0, $sorted = FALSE) {
        $ret = NULL;
        $authkey = ($sorted ? 'sortedauthor' : 'author');
        $titkey = ($sorted ? 'sortedtitle' : 'title');

        $author2 = $this->normalizeAuthor($author);

        $authwords = explode(' ', $author2);

        # Require an author to have one part of their name which isn't very short.  Probably discriminates against
        # Chinese people who use initials, so not ideal.
        $longenough = FALSE;

        foreach ($authwords as $word) {
            if (strlen($word) > 3) {
                $longenough = TRUE;
            }
        }

        if (!$longenough) {
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
        $title = $this->normalizeTitle($title);

        # First search on the assumption that the OCR is pretty good, and we just have a little fuzziness.
        $fuzauthor = round(strlen($author) / 10 + 1);
        $fuztitle = round(strlen($title) / 10 + 1);

        $res = $this->client->search([
            'index' => 'booktastic',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [ 'fuzzy' => [ $authkey => [ 'value' => $author, 'fuzziness' => $fuzauthor ] ] ],
                            [ 'fuzzy' => [ $titkey => [ 'value' => $title, 'fuzziness' => $fuztitle ] ] ],
                        ]
                    ]
                ]
            ],
            'size' => 5,
        ]);

        if ($res['hits']['total'] > 0) {
            $this->log("Found very close match ");
            $ret = $res['hits']['hits'][0];
        } else {
            # Now search just by author, and do our own custom matching.
            $params = [
                'index' => 'booktastic',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                [ 'fuzzy' => [ $authkey => [ 'value' => $author, 'fuzziness' => 2] ] ]
                            ],
                            'should' => [
                                [ 'fuzzy' => [ $titkey => [ 'value' => $title, 'fuzziness' => $fuztitle ] ] ],
                            ]
                        ]
                    ]
                ],
                'size' => 100
            ];

            $this->log("No close match, search for author " . json_encode($params));
            $res = $this->client->search($params);

            $titlebest = 0;

            $this->log("Search for $author - $title returned " . json_encode($res));

            if ($res['hits']['total'] > 0) {
                foreach ($res['hits']['hits'] as $hit) {
                    $hitauthor = $this->normalizeAuthor(strtolower($hit['_source'][$authkey]));
                    $hittitle = $this->normalizeTitle(strtolower($hit['_source'][$titkey]));

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
                            $this->log((microtime(TRUE) - $this->start) . " searched for $author - $title, Consider author $hitauthor $authperc% $hittitle");

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

    public function searchForSpines($id, &$spines, &$fragments, $flag = TRUE)
    {
        # We want to search for the spines in ElasticSearch, where we have a list of authors and books.
        #
        # The spine will normally in in the format "Author Title" or "Title Author".  So we can work our
        # way along the words in the spine searching for matches on this.
        #
        # Order our search by longest spine first.  This is because the longer the spine is, the more likely
        # it is to have both and author and a subject, and therefore match.  Matching gets it out of the way
        # but also gives us a known author, which can be used to good effect to improve matching on other
        # spines.
        $order = [];

        for ($spineindex = 0; $spineindex < count($spines); $spineindex++) {
            $order[] = [
                $spineindex,
                strlen($spines[$spineindex]['spine'])
            ];
        }

        usort($order, function($a, $b) {
            return $b[1] - $a[1];
        });

        $this->log("Sorted by length " . json_encode($order));

        $ret = [];

        foreach ($order as $ord) {
            $spineindex = $ord[0];

            if ($spineindex < count($spines)) {
                $spine = $spines[$spineindex];

                if (!$spine['author']) {
                    $res = NULL;

                    $words = explode(' ', $spine['spine']);
                    $this->log((microtime(TRUE) - $this->start) . " Consider spine {$spine['spine']} words " . count($words));

                    # Most authors have two words, so try first for those to save time.
                    $author = trim(implode(' ', array_slice($words, 0, 2)));
                    $title = trim(implode(' ', array_slice($words, 2)));
                    $res = $this->search($author, $title, 2);

                    if (!$res) {
                        $title = trim(implode(' ', array_slice($words, 0, count($words) - 2)));
                        $author = trim(implode(' ', array_slice($words, count($words) - 2)));
                        $res = $this->search($author, $title, 2);
                    }

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

                        $this->extractKnownAuthors($spines, $fragments);
                    }
                }
            }
        }
    }

    private function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return (strcasecmp(substr($haystack, 0, $length), $needle) === 0);
    }

    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }

        return (strcasecmp(substr($haystack, -$length), $needle) === 0);
    }

    public function extractKnownAuthors(&$spines, &$fragments) {
        # People often file books from the same author together.  If we check the authors we have in hand so far
        # then we can ensure that no known author is split across multiple spines.  That can happen sometimes in
        # the Google results.  This means that we will find the author when we are checking broken spines.
        #
        # It also avoids some issues where we can end up using the author from the wrong spine because the correct
        # author is split across more spines than we are currently looking at.
        $authors = array_filter(array_column($spines, 'author'));
        $this->log("Currently known authors " . json_encode($authors));
        foreach ($authors as $author) {
            $this->log("Search for known author $author");
            $authorwords = explode(' ', $author);
            $wordindex = 0;

            for ($spineindex = 0; $spineindex < count($spines) - 1; $spineindex++) {
                $this->log("Looking at #$spineindex " . json_encode($spines[$spineindex]));

                if (!$spines[$spineindex]['author']) {
                    #$this->log("Check spine at $spineindex");
                    $wi = $wordindex;
                    $si = $spineindex;

                    $spinewords = explode(' ', $spines[$si]['spine']);

                    for ($startword = 0; $startword < count($spinewords); $startword++) {
                        $swi = $startword;

                        while ($wi < count($authorwords) &&
                            $si < count($spines) &&
                            $this->compare($spinewords[$swi], $authorwords[$wi]) >= self::CONFIDENCE) {
                            $this->log("Found possible author match {$authorwords[$wi]} from $author in {$spines[$si]['spine']} at $si starting from $startword");
                            $wi++;
                            $swi++;

                            if ($wi >= count($authorwords)) {
                                break;
                            } else if ($swi >= count($spinewords)) {
                                $swi = 0;
                                $si++;

                                if ($spines[$si]['author']) {
                                    # Already sorted - stop.
                                    break;
                                }

                                $spinewords = explode(' ', $spines[$si]['spine']);
                            }
                        }

                        if ($wi >= count($authorwords)) {
                            # We reached the end of the author.
                            $this->log("Found end of author at startword $startword spine word $swi");
                            $this->log("Spines before " . json_encode($spines));

                            if ($startword > 0) {
                                # Part way along.  First split the spines at the start of the author.
                                $this->log("Split");
                                $this->splitSpines($spines, $fragments, $spineindex, $startword);
                                $this->log("Spines after split" . json_encode($spines));
                            } else if ($swi === count($spinewords)) {
                                # Only want to merge if the end of the author is at the end of the spine, as if we
                                # have other words in the spine they may be a title.
                                $this->log("Merge at $spineindex len $si - $spineindex");
                                $comspined = $spines[$spineindex];
                                $comspined['spine'] .= " {$spines[$spineindex + 1]['spine']}";
                                $this->mergeSpines($spines, $fragments, $comspined, $spineindex, $si - $spineindex + 1);
                                $spineindex++;
                            }

                            $this->log("Spines now " . json_encode($spines));
                            break;
                        } else {
                            #$this->log("No author match");
                        }
                    }
                }
            }
        }

        #$this->log("Checked authors");
    }

    public function searchForPermutedSpines($id, $spines, &$fragments) {
        # Search the different orders of these these spines.
        #
        # The most likely case is that each spine we have in hand is an author or a title - the fact that Google
        # split it at that point tells us something.  So first search using those spine breakpoints.
        $this->log("Search for permuted spines " . json_encode($spines));

        $permuted = $this->permute($spines);

        $searched = [];
        $res = NULL;

        foreach ($permuted as $permute) {
            for ($element = 0; $element < count($permute) - 1; $element++) {
                $author = trim(implode(' ', array_slice($permute, 0, $element + 1)));
                $title = trim(implode(' ', array_slice($permute, $element + 1)));
                $sortedauthor = $this->sortstring($author);
                $sortedtitle = $this->sortstring($title);

                $key = "$sortedauthor - $sortedtitle";
                #$this->log("Consider permute search $key");
                if (!array_key_exists($key, $searched)) {
                    $this->log((microtime(TRUE) - $this->start) . " New search $key");
                    $searched[$key] = TRUE;
                    $ret = $this->search($author, $title, 2);

                    if ($ret) {
                        $res = $ret['_source'];
                        $res['spine'] = "{$res['author']} {$res['title']}";
                        $this->log("Found {$res['spine']} using Google breakpoints");
                    }
                } else {
                    #$this->log("Already searched");
                }
            }
        }

        if (!$res) {
            # Well, that didn't work.  But sometimes Google breaks in the wrong place.  So now search using
            # each word as a breakpoint.
            foreach ($permuted as $permute) {
                $words = explode(' ', implode(' ', $permute));
                $this->log("Filter words");
                $words = array_filter($words, function($a) {
                    return strlen($a);
                });

                $this->log("Consider permutation " . json_encode($words));

                # Most authors have two words, so order our loop to search for that first, at the start or end.
                $order = range(0, count($words) - 1);

                if (count($words) > 2) {
                    array_unshift($order,array_pop($order));
                    $order[1] = 1;
                    $order[2] = 0;
                }

                foreach ($order as $i) {
                    $author = trim(implode(' ', array_slice($words, 0, $i + 1)));
                    $title = trim(implode(' ', array_slice($words, $i + 1)));
                    $sortedauthor = $this->sortstring($author);
                    $sortedtitle = $this->sortstring($title);

                    $key = "$sortedauthor - $sortedtitle";
                    $this->log("Consider permute search $key");
                    if (!array_key_exists($key, $searched)) {
                        $this->log((microtime(TRUE) - $this->start) . " New search $key");
                        $searched[$key] = TRUE;
                        $ret = $this->search($author, $title, 2);

                        if ($ret) {
                            $res = $ret['_source'];
                            $res['spine'] = "{$res['author']} {$res['title']}";
                            $this->log("Found {$res['spine']} using word breakpoints");
                        }
                    } else {
                        $this->log("Already searched");
                    }
                }
            }
        }

        return $res;
    }

    private function flagUsed(&$fragments, $spineindex) {
        $this->log("flag used $spineindex ");
        for ($fragindex = 0; $fragindex < count($fragments); $fragindex++) {
            if ($fragments[$fragindex]['spineindex'] == $spineindex) {
                $this->log("...found in {$fragments[$fragindex]['description']} #$fragindex");
                $fragments[$fragindex]['used'] = TRUE;
            }
        }
    }

    // From https://stackoverflow.com/questions/10222835/get-all-permutations-of-a-php-array
    private function permute($items, $perms = [],&$ret = []) {
        if (!count($perms)) {
            $this->log("Permute " . json_encode($items));
        }
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

        if (!count($perms)) {
            $this->log("...returning " . json_encode($ret));
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

        for ($adjacent = 2; $adjacent <= 5; $adjacent++) {
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
                    $this->log("Enough blanks");
                    $comspined = $this->searchForPermutedSpines($id, $healed, $fragments);

                    if ($comspined) {
                        # It worked.  Use these slots up.
                        $this->log("Merge spines as $i length $adjacent for {$comspined['author']}");
                        $this->log("spines before " . json_encode($spines));
                        $this->mergeSpines($spines, $fragments, $comspined, $i, $adjacent);
                        $this->log("spines now " . json_encode($spines));
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

    private function splitSpines(&$spines, &$fragments, $start, $wordindex) {
        # Renumber the spine indexes in the fragments we are removing.
        for ($fragindex = 0; $fragindex < count($fragments); $fragindex++) {
            if ($fragments[$fragindex]['spineindex'] == $start) {
                if ($wordindex < 0) {
                    $this->log("Splitting wordindex $wordindex at " . json_encode($fragments[$fragindex]));
                    $fragments[$fragindex]['spineindex'] = $start;
                } else {
                    $fragments[$fragindex]['spineindex'] = $start + 1;
                }

                $wordindex--;
            } else if ($fragments[$fragindex]['spineindex'] > $start) {
                # These are the ones we're merging.
                #$this->log("Fragment {$fragments[$fragindex]['description']} is part of merge");
                $fragments[$fragindex]['spineindex']++;
            }
        }

        $words = explode(' ', $spines[$start]['spine']);
        $first = implode(' ', array_slice($words, 0, $wordindex));
        $second = implode(' ', array_slice($words, $wordindex));
        $this->log("Split {$spines[$start]['spine']} into $first and $second");

        $newspines = array_slice($spines, 0, $start);

        $newspines[] = [
            'spine' => $first,
            'author' => NULL,
            'title' => NULL
        ];
        $newspines[] = [
            'spine' => $second,
            'author' => NULL,
            'title' => NULL
        ];

        $newspines = array_merge($newspines, array_slice($spines, $start + 1));

        $spines = $newspines;
    }

    private function mergeSpines(&$spines, &$fragments, $comspined, $start, $length) {
        # We have combined multiple adjacent spines into a single one, possibly with some
        # reordering of text.
        $spines[$start] = $comspined;

        # Renumber the spine indexes in the fragments we are removing.
        for ($fragindex = 0; $fragindex < count($fragments); $fragindex++) {
            if ($fragments[$fragindex]['spineindex'] > $start && $fragments[$fragindex]['spineindex'] <= $start + $length - 1) {
                # These are the ones we're merging.
                #$this->log("Fragment {$fragments[$fragindex]['description']} is part of merge");
                $fragments[$fragindex]['spineindex'] = $start;
            } else if ($fragments[$fragindex]['spineindex'] > $start + $length - 1) {
                # These are above.
                $fragments[$fragindex]['spineindex'] -= ($length - 1);
                #$this->log("Fragment {$fragments[$fragindex]['description']} is above merge");
            }
        }

        array_splice($spines, $start + 1, $length - 1);
    }

    public function recordResults($id, $spines, $fragments) {
        $score = 0;

        foreach ($spines as $r) {
            if ($r['author']) {
                $score++;
            }
        }

        $this->dbhm->preExec("INSERT INTO booktastic_results (ocrid, spines, fragments, score) VALUES (?, ?, ?, ?);", [
            $id,
            json_encode($spines),
            json_encode($fragments),
            count($spines) ? round(100 * $score / count($spines)) : 0
        ]);

        $this->dbhm->preExec("UPDATE booktastic_ocr SET processed = 1 WHERE id = ?;", [
            $id
        ]);
    }

    public function getResult($id) {
        $spines = NULL;
        $fragments = NULL;

        $results = $this->dbhr->preQuery("SELECT * FROM booktastic_results WHERE ocrid = ?;", [
            $id
        ], FALSE, FALSE);

        foreach ($results as $result) {
            $spines = json_decode($result['spines'], TRUE);
            $fragments = json_decode($result['fragments'], TRUE);
        }

        return [ $spines, $fragments ];
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

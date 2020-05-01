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

    private $client = NULL;
    private $start = NULL;
    private $logging = FALSE;
    private $searchAuthors = [];
    private $searchTitles = [];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->start = microtime(TRUE);

        $this->client = ClientBuilder::create()
            ->setHosts([
                'bulk3.ilovefreegle.org:9200'
            ])
            ->build();
    }

    public function clean($str) {
        $sstr = $str;

        # ISBNs often appear on spines.
        $str = trim(str_replace('ISBN', '', $str));

        # Remove digit sequences which are likely part of ISBN.
        #
        # Anything with digits separated by dots can't be a real word.
        $str = trim(preg_replace('/\d+\.\d+/', '', $str));

        # Anything with leading zeros can't either.
        $str = trim(preg_replace('/0\d+/', '', $str));

        # Remove all words that are 1, 2 or 3 digits.  These could legitimately be in some titles but
        # much more often they are ISBN junk.
        $str = trim(preg_replace('/\b\d{1,3}\b/', '', $str));

        # Nothing good starts with a dash.
        $str = trim(preg_replace('/\s-\w+(\b|$)/', '', $str));

        # # is not a word
        $str = trim(preg_replace('/\b\#\b/', '', $str));

        # Quotes confuse matters.
        $str = preg_replace('/"/', '', $str);

        # Collapse multiple spaces.
        $str = preg_replace('/\s+/', ' ', $str);

        $str = trim($str);

        if ($sstr != $str) {
            $this->log("Cleaned $sstr => $str");
        }

        return $str;
    }

    public function fuzzy($str) {
        # We want to correct OCR errors by checking against our DB of known names/words which appear in actual books.
        $ret = preg_replace_callback('/([a-zA-Z\'-]*)/', function($matches) use ($str) {
            $word = strtolower($matches[1]);
            $newword = $word;

            if (strlen(trim($word))) {
                $found = $this->lookup('words', $word, 0);

                if (!$found) {
                    # Not a known title word.
                    $found = $this->lookup('names', $word, 0);

                    if (!$found) {
                        # Not a known author word.
                        $known = $this->lookup('words', $word, 2);

                        if ($known && !preg_match('/[^a-zA-Z\'-]/', $known['_source']['word'])) {
                            $this->log("Correct $word in $str => word {$known['_source']['word']}");
                            $newword = trim($known['_source']['word']);
                        } else {
                            $name = $this->lookup('names', $word, 2);

                            if ($name && !preg_match('/[^a-zA-Z\'-]/', $name['_source']['word'])) {
                                $this->log("Correct $word in $str => name {$known['_source']['word']}");
                                $newword = trim($known['_source']['word']);
                            }
                        }
                    }
                }
            }

            return $newword;
        }, $str);

        if ($str != $ret) {
            $this->log("Fuzzy $str => $ret");
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

    private function getMaxDimension($fragment) {
        $vertices = $fragment['boundingPoly']['vertices'];

        $x = abs(presdef('x', $vertices[0], 0) - presdef('x', $vertices[3], 0));
        $y = abs(presdef('y', $vertices[0], 0) - presdef('y', $vertices[3], 0));
        $ret = max($x, $y);
        #$this->log($fragment['description'] . " max dimension $ret from " . json_encode($vertices));

        return $ret;
    }

    private function pruneSmallText(&$lines, &$fragments) {
        # Very small text on spines is likely to be publishers, ISBN numbers, stuff we've read from the front at an angle,
        # or otherwise junk.  So let's identify the typical letter height, and prune out stuff that's much smaller.
        $heights = [];

        foreach ($fragments as $fragment) {
            $heights[] = $this->getMaxDimension($fragment);
        }

        $mean = array_sum($heights) / count($heights);
        $this->log("Mean height $mean");
        $newlines = [];
        $newfragments = [];
        $fragindex = 0;

        foreach ($lines as $lindinex => $line) {
            $linewords = explode(' ', trim($line));
            $newlinewords = [];

            foreach ($linewords as $word) {
                if (strlen($word)) {
                    $this->log("Consider $word line $lindinex, fragment $fragindex vs " . count($fragments));
                    if ($fragments[$fragindex]['description'] !== $word) {
                        $this->log("ERROR: mismatch spine/fragment");
                    } else {
                        $thismax = $this->getMaxDimension($fragments[$fragindex]);
                        if ($thismax < 0.25 * $mean) {
                            $this->log("Prune small text " . $fragments[$fragindex]['description'] . " size $thismax vs $mean");
                        } else {
                            #$this->log("Keep larger text " . $fragments[$fragindex]['description'] . " size $thismax vs $mean");
                            $newlinewords[] = $fragments[$fragindex]['description'];
                            $newfragments[] = $fragments[$fragindex];
                        }

                        $fragindex++;
                    }
                }
            }

            $newlines[] = implode(' ', $newlinewords);
        }

        $lines = $newlines;
        $fragments = $newfragments;
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
            $this->log("Google summary $summary");
            $lines = explode("\n", $summary);

            array_shift($fragments);

            $this->pruneSmallText($lines, $fragments);

            # Annotate the fragments with whether they are related.
            $fragindex = 0;

            for($spineindex = 0; $spineindex < count($lines) && $fragindex < count($fragments); $spineindex++) {
                $words = explode(' ', trim($lines[$spineindex]));

                foreach ($words as $word) {
                    if (strcmp($word, $fragments[$fragindex]['description']) === 0) {
                        #$this->log("{$fragments[$fragindex]['description']} in spine $spineindex");
                        $fragments[$fragindex++]['spineindex'] = $spineindex;
                    } else {
                        #error_log("Mismatch $word vs " . json_encode($fragments[$fragindex]));
                    }
                }
            }

            foreach ($lines as $key => $line) {
                $lines[$key] = $this->clean($line);
            }

            $spines = [];
            foreach ($lines as $line) {
                foreach (['"', "'"] as $remove) {
                    $line = str_replace($remove, '', $line);
                }

                if (strlen($line)) {
                    $spines[] = [
                        'spine' => $line,
                        'author' => NULL,
                        'title' => NULL
                    ];
                }
            }

            $ret = [ $spines, $fragments ];
        }

        foreach ($spines as $r) {
            if (pres('spine', $r)) {
                $this->log($r['spine']);
            }
        }

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

    private function removeArticles($title) {
        foreach ([ 'the ', 'a ', 'an '] as $article) {
            $title = str_ireplace($article, '', $title);
        }

        return trim($title);
    }

    public function normaliseTitle($title) {
        # Remove any articles at the start of the title, as the catalogues are inconsistent about whether they
        # are included.
        $title = $this->removeArticles($title);

        # Some books have a subtitle, and the catalogues are inconsistent about whether that's included.
        $p = strpos($title, ':');

        if ($p !== FALSE && $p > 0 && $p < strlen($title)) {
            $title = trim(substr($title, 0, $p - 1));
        }

        # Anything in brackets should be removed - ditto.
        $title = preg_replace('/(.*)\(.*\)(.*)/', '$1$2', $title);

        # Remove anything which isn't alphanumeric.
        $title = preg_replace('/[^a-z0-9 ]+/i', '', $title);

        $title = trim(strtolower($title));

        $title = $this->removeShortWords($title);

        #error_log("Normalised title $title");

        return $title;
    }

    public function normaliseAuthor($author) {
        # Any numbers in an author are junk.
        $author = trim(preg_replace('/[0-9]/', '', $author));

        # Remove Dr. as this isn't always present.
        $author = trim(preg_replace('/Dr./', '', $author));

        # Anything in brackets should be removed - not part of the name, could be "(writing as ...)".
        $author = preg_replace('/(.*)\(.*\)(.*)/', '$1$2', $author);

        # Remove anything which isn't alphabetic.
        $author = preg_replace('/[^a-z ]+/i', '', $author);

        $author = trim(strtolower($author));

        $author = $this->removeShortWords($author);

        #error_log("Normalised author $author");

        return $author;
    }

    private function removeShortWords($str) {
        # Short words are common, or initials, and very vulnerable to OCR errors.  Remove them, and hope that there
        # is a longer word in the author/title.  Which there normally is.
        $words = explode(' ', $str);
        $ret = [];

        foreach ($words as $word) {
            $w = trim($word);

            if (strlen($word) > 3) {
                $ret[] = $w;
            }
        }

        return implode(' ', $ret);
    }

    private function sanityCheck(&$ret) {
        $hitauthor = $ret['_source']['normalauthor'];
        $hittitle = $ret['_source']['normaltitle'];

        if (stripos($hittitle, $hitauthor) !== FALSE || stripos($hitauthor, $hittitle) !== FALSE) {
            $this->log("Sanity check failed $hittitle, $hitauthor");
            $ret = NULL;
        }
    }

    private function searchAuthorTitle($author, $title) {
        $ret = NULL;
        $this->log("Author $author title $title");

        if (strlen($author) && strlen($title)) {
            # Empirical testing shows that using a fuzziness of 2 for author all the time gives good results.
            $res = $this->client->search([
                'index' => 'booktastic',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                [ 'fuzzy' => [ 'normalauthor' => [ 'value' => $author, 'fuzziness' => 2 ] ] ],
                                [ 'fuzzy' => [ 'normaltitle' => [ 'value' => $title, 'fuzziness' => 2 ] ] ],
//                            [ 'match' => [ 'normalauthor' => $author ] ],
//                            [ 'match' => [ 'normaltitle' => $title ] ],
                            ]
                        ]
                    ]
                ],
                'size' => 5,
            ]);

            if ($res['hits']['total']['value'] > 0) {
                $hit = $res['hits']['hits'][0];
                $hitauthor = $hit['_source']['normalauthor'];
                $hittitle = $hit['_source']['normaltitle'];
                $authperc = $this->compare($author, $hitauthor);
                $titperc = $this->compare($title, $hittitle);
                $this->log("Author + title match $authperc, $titperc, $author, $title vs $hitauthor, $hittitle");

                if ($authperc >= self::CONFIDENCE && $titperc >= self::CONFIDENCE) {
                    $this->log("FOUND: author + title match $authperc, $titperc" . json_encode($res));
                    $ret = $hit;
                }
            }
        } else {
            $this->log("Blank");
        }


        $this->sanityCheck($ret);

        return $ret;
    }

    private function searchAuthor($author, $title) {
        # Search just by author, and do our own custom matching.
        $ret = NULL;

        if (strlen($author) && strlen($title)) {
            $res = presdef($author, $this->searchAuthors, NULL);

            if (!$res) {
                $params = [
                    'index' => 'booktastic',
                    'body' => [
                        'query' => [
                            'bool' => [
                                'must' => [
                                    ['fuzzy' => ['normalauthor' => ['value' => $author, 'fuzziness' => 2]]]
                                ],
                                'should' => [
                                    ['fuzzy' => ['normaltitle' => ['value' => $title, 'fuzziness' => 0]]],
                                ]
                            ]
                        ]
                    ],
                    'size' => 100
                ];

                $this->log("Search for author " . json_encode($params));
                $res = $this->client->search($params);
                $this->searchAuthors[$author] = $res;
            }

            $titlebest = 0;

            #$this->log("Search for $author returned " . json_encode($res));

            if ($res['hits']['total']['value'] > 0) {
                foreach ($res['hits']['hits'] as $hit) {
                    $hitauthor = $hit['_source']['normalauthor'];
                    $hittitle = $hit['_source']['normaltitle'];

                    if (strlen($hitauthor) > 5 && strlen($hittitle)) {
                        # If one appears inside the other then it's a match.  This can happen if there's extra
                        # stuff like publisher info.  The title is more likely to have that because the author
                        # generally comes first.
                        if (strpos("$author $title", "$hitauthor $hittitle") !== FALSE) {
                            $this->log("Search for $author - $title returned ");
                            $this->log("...matched inside $hitauthor - $hittitle");
                            #error_log("Search by author for $author - $title found in $hitauthor - $hittitle");
                            $ret = $hit;
                        } else {
                            $authperc = $this->compare($author, $hitauthor);
                            $this->log((microtime(TRUE) - $this->start) . " searched for $author - $title, Consider author $hitauthor $authperc% $hittitle");

                            if ($authperc >= self::CONFIDENCE) {
                                # Looks like the author.  Don't find the best author match - this is close enough
                                # for it to be worth us scanning the books.
                                #
                                # The title we have OCR'd is more likely to have guff on the end, such as the
                                # publisher.  So compare upto the length of the candidate title.
                                $titperc = $this->compare($title, $hittitle);
                                $p = strpos("$title", "$hittitle");
                                $this->log("...$hitauthor - $hittitle $titperc%, $p");

                                if ($titperc >= self::CONFIDENCE && $titperc >= $titlebest) {
                                    $titlebest = $titperc;
                                    $this->log("Search for $author - $title returned ");
                                    $this->log("...matched $hitauthor - $hittitle $titperc%");
                                    #error_log("Search by author for $author - $title matched in $hitauthor - $hittitle with fuzziness $fuzauthor");
                                    $ret = $hit;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->sanityCheck($ret);

        return $ret;
    }

    private function searchTitle($author, $title) {
        # Search just by title, and do our own custom matching.
        $ret = NULL;

        if (strlen($author) && strlen($title)) {
            $res = presdef($title, $this->searchTitles, NULL);

            if (!$res) {
                $params = [
                    'index' => 'booktastic',
                    'body' => [
                        'query' => [
                            'bool' => [
                                'must' => [
                                    ['fuzzy' => ['normaltitle' => ['value' => $title, 'fuzziness' => 2]]]
                                ],
                                'should' => [
                                    ['fuzzy' => ['normalauthor' => ['value' => $author, 'fuzziness' => 0]]],
                                ]
                            ]
                        ]
                    ],
                    'size' => 100
                ];

                $this->log("Search for title " . json_encode($params));
                $res = $this->client->search($params);
                $this->searchTitles[$title] = $res;
            }

            $authorbest = 0;

            #$this->log("Search for $title returned " . json_encode($res));

            if ($res['hits']['total']['value'] > 0) {
                foreach ($res['hits']['hits'] as $hit) {
                    $hitauthor = $hit['_source']['normalauthor'];
                    $hittitle = $hit['_source']['normaltitle'];

                    if (strlen($hitauthor) > 5 && strlen($hittitle)) {
                        # If one appears inside the other then it's a match.  This can happen if there's extra
                        # stuff like publisher info.  The title is more likely to have that because the author
                        # generally comes first.
                        if (strpos("$author $title", "$hitauthor $hittitle") !== FALSE) {
                            $this->log("Search for $author - $title returned ");
                            $this->log("...matched inside $hitauthor - $hittitle");
                            error_log("Search by title for $author - $title found in $hitauthor - $hittitle");
                            $ret = $hit;
                        } else {
                            $titperc = $this->compare($title, $hittitle);
                            $this->log((microtime(TRUE) - $this->start) . " searched for $author - $title, Consider title $hittitle $titperc% $hitauthor");

                            if ($titperc >= self::CONFIDENCE) {
                                # Looks like the title.  Don't find the best title match - this is close enough
                                # for it to be worth us scanning the books.
                                $authperc = $this->compare($author, $hitauthor);
                                $p = strpos("$author", "$hitauthor");
                                $this->log("...$hitauthor - $hittitle $authperc%, $p");

                                if ($authperc >= self::CONFIDENCE && $authperc >= $authorbest) {
                                    $authorbest = $authperc;
                                    $this->log("Search for $author - $title returned ");
                                    $this->log("...matched $hitauthor - $hittitle $authperc%");
                                    #error_log("Search by title for $author - $title matched in $hitauthor - $hittitle");
                                    $ret = $hit;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->sanityCheck($ret);

        return $ret;
    }

    private function searchTitleOnly($title) {
        # Search just by title.  Must be pretty close.
        $ret = NULL;

        # This is only reliable for pretty long titles.
        if (strlen($this->normaliseTitle($title)) > 20) {
            $params = [
                'index' => 'booktastic',
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => [
                                ['fuzzy' => ['normaltitle' => ['value' => $title, 'fuzziness' => 2]]]
                            ]
                        ]
                    ]
                ],
                'size' => 1
            ];

            $this->log("Search for title " . json_encode($params));
            $res = $this->client->search($params);

            $this->log("Search for $title only returned " . json_encode($res));

            if ($res['hits']['total']['value'] > 0) {
                $this->log("FOUND: title only match " . json_encode($res));
                $ret = $res['hits']['hits'][0];
            }
        }

        $this->sanityCheck($ret);

        return $ret;
    }

    private function search($author, $title, $authorplustitle) {
        $ret = NULL;
        try {
            $author2 = $this->normaliseAuthor($author);

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

            $title = $this->normaliseTitle($title);

            $this->log("Search for $author - $title");

            if (!$authorplustitle) {
                $this->log("author title");
                $ret = $this->searchAuthorTitle($author, $title);
            } else {
                $this->log("author only");
                $ret = $this->searchAuthor($author, $title);

                if (!$ret) {
                    $this->log("title only");
                    $ret = $this->searchTitle($author, $title);
                }
            }
        } catch (Exception $e) {
            $this->log("Failed " . $e->getMessage());
        }

        return $ret;
    }

    public function searchForSpines($id, &$spines, &$fragments, $fuzzy, $authorstart, $authorplustitle, $mangled)
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
        $this->log("Search for spines fuzzy = $fuzzy authorstart = $authorstart authorplustitle = $authorplustitle mangled = $mangled");
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

        if ($fuzzy) {
            # Fuzzy match using DB of known words.  This has the frequency values in it and is therefore likely to
            # yield a better result than what happens within an each ElasticDB search, though we still do that
            # for authors as it works better.
            foreach ($spines as $spineindex => $spine) {
                if (!array_key_exists('original', $spine) && !$spine['author']) {
                    $spines[$spineindex]['original'] = $spine['spine'];
                    $spine[$spineindex]['spine'] = $this->fuzzy($spine['spine']);
                }
            }
        }

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
                    if ($authorstart) {
                        $author = trim(implode(' ', array_slice($words, 0, 2)));
                        $title = trim(implode(' ', array_slice($words, 2)));
                        $this->log("Author start $author - $title");
                        $res = $this->search($author, $title, $authorplustitle);

                        for ($i = 0; !$res && $i < count($words) - 1; $i++) {
                            # Try matching assuming the author is at the start.
                            $author = trim(implode(' ', array_slice($words, 0, $i + 1)));
                            $title = trim(implode(' ', array_slice($words, $i + 1)));
                            $this->log("Author rest $author - $title");
                            $res = $this->search($author, $title, $authorplustitle);
                        }
                    } else {
                        $title = trim(implode(' ', array_slice($words, 0, count($words) - 2)));
                        $author = trim(implode(' ', array_slice($words, count($words) - 2)));
                        $this->log("Author end $author - $title");
                        $res = $this->search($author, $title, $authorplustitle);

                        for ($i = 0; !$res && $i < count($words) - 1; $i++) {
                            # Try matching assuming the author is at the end.
                            $title = trim(implode(' ', array_slice($words, 0, $i + 1)));
                            $author = trim(implode(' ', array_slice($words, $i + 1)));
                            $this->log("Author rest $author - $title");
                            $res = $this->search($author, $title, $authorplustitle);
                        }
                    }

                    if ($res) {
                        # We found one for this spine.
                        $spines[$spineindex]['author'] = $res['_source']['author'];
                        $spines[$spineindex]['title'] = $res['_source']['title'];
                        $spines[$spineindex]['viafid'] = $res['_source']['viafid'];
                        $this->log("FOUND: {$spines[$spineindex]['author']} - {$spines[$spineindex]['title']}");

                        $this->checkAdjacent($spines, $spineindex, $author, $title);
                        $this->flagUsed($fragments, $spineindex);
                        $this->extractKnownAuthors($spines, $fragments);
                    }
                }
            }
        }

        $this->searchForBrokenSpines($id, $spines, $fragments, $authorplustitle, $mangled);
    }

    private function checkAdjacent(&$spines, $spineindex, $author, $title) {
        # We might have matched on part of a title and have the rest of it in an adjacent spine.  If so it's
        # good to remove it to avoid it causing false matches.
        $residual = trim(str_ireplace($title, '', $spines[$spineindex]['title']));
        $this->log("Residual after removing $title => $residual");

        if (strlen($residual)) {
            $cmp = [];

            if ($spineindex > 0) {
                $cmp[] = $spineindex - 1;
            }

            if ($spineindex < count($spines) - 1) {
                $cmp[] = $spineindex + 1;
            }

            foreach ($cmp as $c) {
                if (!$spines[$c]['author'] && stripos($spines[$c]['spine'], $residual) !== FALSE) {
                    $this->log("Remove from " . $spines[$c]['spine']);
                    $spines[$c]['spine'] = trim(str_ireplace($residual, '', $spines[$c]['spine']));
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
        $authors = array_unique(array_filter(array_column($spines, 'author')));
        $this->log("Currently known authors " . json_encode($authors));
        foreach ($authors as $author) {
            $this->log("Search for known author $author");
            $authorwords = explode(' ', $author);
            $wordindex = 0;

            for ($spineindex = 0; $spineindex < count($spines) - 1; $spineindex++) {
                $this->log("Looking at #$spineindex " . json_encode($spines[$spineindex]));

                if (!$spines[$spineindex]['author'] && strlen($spines[$spineindex]['spine'])) {
                    #$this->log("Check spine at $spineindex");
                    $wi = $wordindex;
                    $si = $spineindex;

                    $spinewords = explode(' ', $spines[$si]['spine']);

                    # We only want to start checking at the start of a spine.  If there are other words earlier in the
                    # spine they may be a title and by merging with the next spine we might combine two titles.
                    $swi = 0;

                    while ($wi < count($authorwords) &&
                        $si < count($spines) &&
                        $this->compare($spinewords[$swi], $authorwords[$wi]) >= self::CONFIDENCE) {
                        $this->log("Found possible author match {$authorwords[$wi]} from $author in {$spines[$si]['spine']} at $si");
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

                    if ($wi >= count($authorwords) && $si > $spineindex) {
                        # Found author split across spines.
                        $this->log("Found end of author in spine $si vs $spineindex spine upto word $swi");
                        $this->log("Spines before " . json_encode($spines));

                        $this->log("Merge at $spineindex len $si - $spineindex");
                        $comspined = $spines[$spineindex];
                        $comspined['spine'] .= " {$spines[$spineindex + 1]['spine']}";
                        $this->mergeSpines($spines, $fragments, $comspined, $spineindex, $si - $spineindex + 1);
                        $this->log("Spines now " . json_encode($spines));
                    } else {
                        #$this->log("No author match");
                    }
                }
            }
        }

        #$this->log("Checked authors");
    }

    public function searchForPermutedSpines($id, $spines, &$fragments, $authorplustitle) {
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
                    $ret = $this->search($author, $title, $authorplustitle);

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

        return $res;
    }

    public function searchForMangledSpines($id, $spines, &$fragments) {
        # Search the different orders of these these spines.
        #
        # The most likely case is that each spine we have in hand is an author or a title - the fact that Google
        # split it at that point tells us something.  So first search using those spine breakpoints.
        $this->log("Search for mangled spines " . json_encode($spines));

        $permuted = $this->permute($spines);

        $searched = [];
        $res = NULL;

        # Sometimes Google breaks in the wrong place.  So now search using each word as a breakpoint.
        foreach ($permuted as $permute) {
            $words = explode(' ', implode(' ', $permute));
            $this->log("Filter words");
            $words = array_filter($words, function ($a) {
                return strlen($a);
            });

            $this->log("Consider mangling " . json_encode($words));

            # Most authors have two words, so order our loop to search for that first, at the start or end.
            $order = range(0, count($words) - 1);

            if (count($words) > 2) {
                array_unshift($order, array_pop($order));
                $order[1] = 1;
                $order[2] = 0;
            }

            foreach ($order as $i) {
                $author = trim(implode(' ', array_slice($words, 0, $i + 1)));
                $title = trim(implode(' ', array_slice($words, $i + 1)));
                $key = "$author - $title";
                $sortedauthor = $this->sortstring($author);
                $sortedtitle = $this->sortstring($title);
                #$key = "$sortedauthor - $sortedtitle";

                $this->log("Consider mangled search $key");

                if (!array_key_exists($key, $searched)) {
                    $this->log((microtime(TRUE) - $this->start) . " New search $key");
                    $searched[$key] = TRUE;
                    #$ret = $this->search($sortedauthor, $sortedtitle, 2, TRUE);
                    $ret = $this->search($author, $title, 2, FALSE);

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

    public function searchForBrokenSpines($id, &$spines, &$fragments, $authorplustitle, $mangle) {
        # Up to this point we've relied on what Google returns on a single line.  We will have found
        # some books via that route.  But it's common to have the author on one line, and the book on another,
        # or other variations which result in text on a single spine being split.
        #
        # Ideally we'd search all permutations of all the words.  But this is expensive.  3 chunks allows for an
        # author and a split title or vice-versa.
        #
        # So loop through all cases where we have adjacent spines not matched.  Do this initially for 2 adjacent
        # spines, then increase - we've seen some examples where a single title can end up on several lines.
        $this->log("Broken spines");
        foreach ($spines as $spine) {
            if ($spine['author']) {
                $this->log('-');
            } else {
                $this->log($spine['spine']);
            }
        }

        # Mangled searches are slower so we do them second, and because we will split inside a spine we don't
        # need to try as many spines.
        for ($adjacent = 2; $adjacent <= ($mangle ? 2 : 4); $adjacent++) {
            $i = 0;

            while ($i < count($spines) - 1) {
                $thisone = $spines[$i];

                if (strlen($thisone['spine'])) {
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

                        if (!$mangle) {
                            $comspined = $this->searchForPermutedSpines($id, $healed, $fragments, $authorplustitle);
                        } else {
                            $comspined = $this->searchForMangledSpines($id, $healed, $fragments, $authorplustitle);
                        }

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

    public function recordResults($id, $spines, $fragments, $phase) {
        $score = 0;

        foreach ($spines as $r) {
            if ($r['author']) {
                $score++;
            }
        }

        $this->dbhm->preExec("INSERT INTO booktastic_results (ocrid, spines, fragments, score, phase) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE spines = ?, fragments = ?, score = ?, phase = ?;", [
            $id,
            json_encode($spines),
            json_encode($fragments),
            count($spines) ? round(100 * $score / count($spines)) : 0,
            $phase,
            json_encode($spines),
            json_encode($fragments),
            count($spines) ? round(100 * $score / count($spines)) : 0,
            $phase
        ]);
    }

    private function countSuccess($spines) {
        $count = 0;

        foreach ($spines as $spine) {
            if ($spine['author']) {
                $count++;
            } else {
                #error_log("...{$spine['spine']}");
            }
        }

        return $count;
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

    public function rate($id, $rating) {
        $this->dbhm->preExec("UPDATE booktastic_results SET rating = ? WHERE ocrid = ?;", [
            $rating,
            $id
        ]);
    }

    public function process($id) {
        $this->start($id);

        $ocrdata = $this->dbhm->preQuery("SELECT * FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);

        $spines = [];
        $fragments = [];

        foreach ($ocrdata as $o) {
            # The first item is a Google's assembly of the text.  This is useful because it puts \n between
            # items which it thinks are less related, and it does a good job of it.  That means that we
            # have a head start on what is likely to be on a single spine.  The rest of the entries are the
            # individual words.
            $fn = tempnam('/tmp/', 'booktastic_');
            file_put_contents($fn, $o['text']);
            $cmd = "/root/bin/booktastic -i $fn -o $fn.out";
            system($cmd);
            $res = file_get_contents("$fn.out");
            unlink($fn);
            unlink("$fn.out");

            if ($res) {
                $json = json_decode($res, TRUE);
                $spines = $json['spines'];
                $fragments = $json['fragments'];
                $this->recordResults($id, $spines, $fragments, 0);
                $this->complete($id);
            }
        }

        return [ $spines, $fragments ];
    }

    public function start($id) {
        $this->dbhr->preExec("UPDATE booktastic_results SET started = NOW() WHERE ocrid = ?;", [
            $id
        ]);
    }

    public function complete($id) {
        $this->dbhr->preExec("UPDATE booktastic_results SET completed = NOW() WHERE ocrid = ?;", [
            $id
        ]);

        $this->dbhm->preExec("UPDATE booktastic_ocr SET processed = 1 WHERE id = ?;", [
            $id
        ]);
    }

    public function phase($id, $phase) {
        $this->dbhr->preExec("UPDATE booktastic_results SET phase = ? WHERE ocrid = ?;", [
            $phase,
            $id
        ]);
    }

    private function lookup($index, $word, $fuzziness)
    {
        $ret = NULL;

        $res = $this->client->search([
            'index' => $index,
            'body' => [
                'query' => [
                    'fuzzy' => [ 'word' => [ 'value' => $word, 'fuzziness' => $fuzziness ] ]
                ],
                'sort' => [
                    'frequency' => [
                        'order' => 'desc'
                    ]
                ]
            ],
            'size' => 1,
        ]);

        if ($res['hits']['total']['value'] > 0) {
            $ret = $res['hits']['hits'][0];
        }

        #$this->log("Looked up $word in $index found " . json_encode($ret));
        return $ret;
    }
}

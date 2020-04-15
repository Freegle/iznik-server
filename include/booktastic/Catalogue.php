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
    private $logging = TRUE;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

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

    private function getDistance($vertices, $nvertices) {
        $x = (presdef('x', $nvertices[0], 0) - presdef('x', $vertices[1], 0));
        $y = (presdef('y', $nvertices[0], 0) - presdef('y', $vertices[1], 0));
        $rdist = sqrt($x * $x + $y * $y);

        $x = (presdef('x', $nvertices[1], 0) - presdef('x', $vertices[0], 0));
        $y = (presdef('y', $nvertices[1], 0) - presdef('y', $vertices[0], 0));
        $ldist = sqrt($x * $x + $y * $y);

        return [ $ldist, $rdist ];
    }

    private function orient($vertices, $horizontalish) {
        if ($horizontalish) {
            return abs(presdef('x', $vertices[1], 0) - presdef('x', $vertices[0], 0)) >=
                abs(presdef('y', $vertices[3], 0) - presdef('y', $vertices[0], 0));
        } else {
            return abs(presdef('x', $vertices[3], 0) - presdef('x', $vertices[0], 0)) >=
                abs(presdef('y', $vertices[1], 0) - presdef('y', $vertices[0], 0));
        }
    }

    private function isWord($word) {
        if (preg_match('/^[0-9]{4}-[0-9]{2,4}/', $word)) {
            # Allow date ranges
            return true;
        }

        $t = preg_replace('/[^a-zA-Z]/', '', $word);

        foreach (['ISBN'] as $ignore) {
            $t = str_ireplace($ignore, '', $t);
        }

        return strlen($t);
    }

    private function height($vertices, $horizontalish) {
        if ($horizontalish) {
            return abs(presdef('y', $vertices[0], 0) - presdef('y', $vertices[3], 0));
        } else {
            return abs(presdef('x', $vertices[0], 0) - presdef('x', $vertices[3], 0));
        }
    }

    public function identifySpinesFromOCR($id) {
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT * FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);


        foreach ($ocrdata as $o) {
            $json = json_decode($o['text'], TRUE);

            # First item is summary - ignore that.
            array_shift($json);

            # Give each entry an id so we can find them in different orders.
            $id = 0;
            for ($i = 0; $i < count($json); $i ++) {
                $json[$i]['id'] = $id++;
            }

            # Work out whether the orientation of the image is horizontal(ish) or vertical(ish).
            $xtot = 0;
            $ytot = 0;

            foreach ($json as $j) {
                $vertices = $j['boundingPoly']['vertices'];

                # Which way is this oriented determines which way we project.
                $xtot += abs(presdef('x', $vertices[1], 0) - presdef('x', $vertices[0], 0));
                $ytot += abs(presdef('y', $vertices[1], 0) - presdef('y', $vertices[0], 0));
            }

            $horizontalish = $xtot >= $ytot;

            do {
                $merged = FALSE;
                #$this->log("Scan " . count($json));

                for ($j = 0; !$merged && $j < count($json); $j++) {
                    $entry = $json[$j];
                    #$this->log("Consider {$entry['description']} at $j of " . count($json));
                    if ($this->isWord($entry['description'])) {
                        $poly = presdef('boundingPoly', $entry, NULL);
                        $vertices = $poly['vertices'];
                        $orient = $this->orient($vertices, $horizontalish);
                        $height = $this->height($vertices, $horizontalish);

                        if ($poly) {
                            # Get the middle of the bounding box.
                            $midy1 = (presdef('y', $vertices[0],0) + presdef('y', $vertices[3],0)) / 2;
                            $midy2 = (presdef('y', $vertices[2],0) + presdef('y', $vertices[1],0)) / 2;
                            $midx1 = (presdef('x', $vertices[0],0) + presdef('x', $vertices[3],0)) / 2;
                            $midx2 = (presdef('x', $vertices[2],0) + presdef('x', $vertices[1],0)) / 2;

                            $grad = ($midx2 - $midx1 > 0) ? ($midy2 - $midy1) / ($midx2 - $midx1) : PHP_INT_MAX;
                            #$this->log("Gradient $grad from $midx1, $midy1 to $midx2, $midy2");

                            # If we project this line outwards, we should meet the other text which is in a line with this
                            # one on the same spine.
                            $others = [];

                            for ($k = 0; !$merged && $k < count($json); $k++) {
                                if ($j !== $k && $this->isWord($json[$k]['description'])) {
                                    $nentry = $json[$k];
                                    #$this->log("Look at " . json_encode($nentry));
                                    $npoly = presdef('boundingPoly', $nentry, NULL);
                                    $nvertices = $npoly['vertices'];
                                    $norient = $this->orient($nvertices, $horizontalish);
                                    $nheight = $this->height($nvertices, $horizontalish);

                                    # The text should only be combined if it's the same orientation.  The publisher
                                    # is often a different orientation.
                                    #$this->log("{$entry['description']} horizontal $horizontalish vs {$nentry['description']} $nhorizontalish");
                                    if ($orient == $norient && $nheight) {
                                        # We only want to merge if the font size is similar.  The publisher is often
                                        # much smaller font.
                                        $ratio = $height / $nheight;

                                        #$this->log("Compare sizes {$entry['description']} vs {$nentry['description']} $height, $nheight,  $ratio");

                                        # Ratio comparison doesn't seem to help.
                                        #if ($ratio >= 0.5 && $ratio <= 1.5)
                                        {
                                            # Which way is this oriented determines which way we project.
                                            $xgap = presdef('x', $nvertices[0], 0) - presdef('x', $vertices[1], 0);
                                            $ygap = presdef('y', $nvertices[0], 0) - presdef('y', $vertices[1], 0);

                                            if ($horizontalish) {
                                                # Horizontalish.
                                                $projecty = $midy2 + $grad * $xgap;

                                                #$this->log("Projected y = $projecty from $midy2, $grad, {$nvertices[0]['x']}, {$vertices[1]['x']}");
                                                #$this->log("Compare projected horizontally from {$entry['description']} $projecty to {$nvertices[0]['y']} and {$nvertices[3]['y']} for {$nentry['description']}");

                                                if ($projecty <= presdef('y', $nvertices[3], 0) &&
                                                    $projecty >= presdef('y', $nvertices[0], 0) ||
                                                    $projecty <= presdef('y', $nvertices[0], 0) &&
                                                    $projecty >= presdef('y', $nvertices[3], 0)) {
                                                    # The projected line passes through.  Merge these together.
                                                    $others[] = $nentry;
                                                }
                                            } else {
                                                # Verticalish.
                                                $projectx = $midx2 + ($grad ? ($ygap / $grad) : 0);

                                                #$this->log("Projected y = $projecty from $midy2, $grad, {$nvertices[0]['x']}, {$vertices[1]['x']}");
                                                #$this->log("Compare projected vertically from {$entry['description']} $projectx to {$nvertices[0]['x']} and {$nvertices[3]['x']} for {$nentry['description']}");

                                                if ($projectx <= presdef('x', $nvertices[3], 0) &&
                                                    $projectx >= presdef('x', $nvertices[0], 0) ||
                                                    $projectx <= presdef('x', $nvertices[0], 0) &&
                                                    $projectx >= presdef('x', $nvertices[3], 0)) {
                                                    # The projected line passes through.  Merge these together.
                                                    $others[] = $nentry;
                                                    #$this->log("Include projected vertically from {$entry['description']} orient $orient $projectx to {$nvertices[0]['x']} and {$nvertices[3]['x']} for {$nentry['description']} orient $norient");
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            # Now we have the words that are in a line with this one.  We want to merge them together.  Sort by
                            # distance from this one.
//                        $this->log("Found in line with {$entry['description']}:");
//                        foreach ($others as $o) {
//                            $this->log("...{$o['description']}");
//                        }

                            usort($others, function($a, $b) use ($vertices) {
                                list ($ldist, $rdist) = $this->getDistance($vertices, $a['boundingPoly']['vertices']);
                                $adist = min($ldist, $rdist);
                                list ($ldist, $rdist) = $this->getDistance($vertices, $b['boundingPoly']['vertices']);
                                $bdist = min($ldist, $rdist);

                                #$this->log("{$a['description']} {$b['description']} $adist, $bdist");
                                return ($adist - $bdist);
                            });

//                        $this->log("Sorted by closeness to {$entry['description']}:");
//                        foreach ($others as $o) {
//                            $this->log("...{$o['description']}");
//                        }

                            for ($k = 0; !$merged && $k < count($others); $k++) {
                                # Which side is this?
                                $other = $others[$k];
                                $nvertices = $others[$k]['boundingPoly']['vertices'];
                                list ($ldist, $rdist) = $this->getDistance($vertices, $nvertices);

                                if ($rdist < $ldist) {
                                    # It's on the right.
                                    $json[$j]['vertices'] = [
                                        $vertices[0],
                                        $nvertices[1],
                                        $nvertices[2],
                                        $vertices[3]
                                    ];

                                    $json[$j]['description'] .= " {$other['description']}";
                                } else {
                                    $json[$j]['vertices'] = [
                                        $nvertices[0],
                                        $vertices[1],
                                        $vertices[2],
                                        $nvertices[3]
                                    ];

                                    $json[$j]['description'] = "{$other['description']} {$json[$j]['description']}" ;
                                }

                                #$this->log("Passes through {$other['description']}, now {$json[$j]['description']}, $ldist, $rdist");

                                # Remove the one we've merged.
                                $json = array_values(array_filter($json, function($a) use ($other) {
                                    return $a['id'] !== $other['id'];
                                }));

                                $merged = TRUE;
                            }
                        }
                    }
                }
            } while ($merged);

            foreach ($json as $entry) {
                # Take anything containing letters and multiword.
                if ($this->isWord($entry['description']) && strpos($entry['description'], ' ') !== FALSE) {
                    $ret[] = $entry['description'];
                    #$this->log($entry['description']);
                }
            }
        }

        return $ret;
    }

    private function search($author, $title, $fuzziness = 0) {
        $author = strtolower($author);
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
                                [ 'fuzzy' => [ 'author' => [ 'value' => $author, 'fuzziness' => 2 ] ] ],
                                [ 'fuzzy' => [ 'title' => [ 'value' => $title, 'fuzziness' => 20 ] ] ],
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
                                [ 'fuzzy' => [ 'author' => [ 'value' => $author, 'fuzziness' => 2] ] ]
                            ]
                        ]
                    ]
                ],
                'size' => 500,
            ]);

            $ret = NULL;
            $authbest = 0;
            $titlebest = 0;

            $this->log("Search for $author, $title returned " . json_encode($res));

            if ($res['hits']['total'] > 0) {
                foreach ($res['hits']['hits'] as $hit) {
                    $hitauthor = strtolower($hit['_source']['author']);
                    $hittitle = strtolower($hit['_source']['title']);

                    # Ignore blankish entries.
                    if (strlen($hitauthor) > 5 && strlen($hittitle)) {
                        # If one appears inside the other then it's a match.  This can happen if there's extra
                        # stuff like publisher info.  The title is more likely to have that because the author
                        # generally comes first.
                        if (strpos("$author $title", "$hitauthor $hittitle") !== FALSE) {
                            $this->log("Search for $author - $title returned ");
                            $this->log("...matched inside $hitauthor - $hittitle");
                            $ret = $hit;
                        } else {
                            # We can get different confidence values different ways round - be pessimistic.
                            similar_text($author, $hitauthor, $authperc1);
                            similar_text($hitauthor, $author, $authperc2);
                            $authperc = min($authperc1, $authperc2);
                            $this->log("Searched for $author - $title, Consider author $hitauthor $authperc% $hittitle");

                            if ($authperc > self::CONFIDENCE && $authperc >= $authbest) {
                                # Looks like the author.
                                $authbest = $authperc;

                                # The title we have OCR'd is more likely to have guff on the end, such as the
                                # publisher.  So compare upto the length of the canidate title.
                                similar_text(substr($title, 0, strlen($hittitle)), $hittitle, $titperc1);
                                similar_text($hittitle, substr($title, 0, strlen($hittitle)), $titperc2);
                                $titperc = min($titperc1, $titperc2);
                                $p = strpos("$title", "$hittitle");
                                $this->log("...$hitauthor - $hittitle $titperc%, $titperc1, $titperc2, $p");

                                if ($p !== FALSE) {
                                    $this->log("...matched $title inside title $hittitle");
                                    $ret = $hit;
                                } else if ($titperc > self::CONFIDENCE && $titperc >= $titlebest) {
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

        return $ret;
    }

    public function searchForSpines($id, $spines) {
        # We want to search for the spines in ElasticSearch, where we have a list of authors and books.
        #
        # The spine will normally in in the format "Author Title" or "Title Author".  So we can work our
        # way along the words in the spine searching for matches on this.
        $score = 0;

        foreach ($spines as $spine) {
            $res = NULL;

            $words = explode(' ', $spine);
            $this->log("Consider spine $spine words " . count($words));

            for ($i = 0; !$res && $i < count($words) - 1; $i++) {
                # Try matching assuming the author is at the start.
                $author = trim(implode(' ', array_slice($words, 0, $i + 1)));
                $title = trim(implode(' ', array_slice($words, $i + 1)));
                $res = $this->search($author, $title, 2);

                if (!$res) {
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


            $ret[] = [
                'spine' => $spine,
                'author' => $res ? $res['_source']['author'] : NULL,
                'title' => $res ? $res['_source']['title'] : NULL
            ];

            $score += $res ? 1 : 0;
        }


        $this->dbhm->preExec("INSERT INTO booktastic_results (ocrid, results, score) VALUES (?, ?, ?);", [
            $id,
            json_encode($ret),
            round(100 * $score / count($ret))
        ]);

        return $ret;
    }

    public function extractKnownAuthors($id, $spines) {
        $ret = [];

        # Scan known authors and see if they appear.  Use the longest version we can find.
        $authors = $this->dbhr->preQuery("SELECT name FROM booktastic_authors ORDER BY LENGTH(name) DESC;");

        foreach ($spines as $spine) {
            $text = trim(strtolower($spine));
            $found = FALSE;

            foreach ($authors as $author) {
                $aname = trim(strtolower($author['name']));

                if (strlen($aname) > 10 && strpos($text, $aname) !== FALSE) {
                    #$this->log("Found known author $aname in $text");
                    $ret[] = [
                        'spine' => $spine,
                        'author' => $aname,
                        'title' => trim(str_ireplace($aname, '', $spine))
                    ];

                    $found = TRUE;
                    break;
                }
            }

            if (!$found) {
                $ret[] = [
                    'spine' => $spine,
                    'author' => NULL,
                    'title' => NULL
                ];
            }
        }

        return $ret;
    }

    public function extractPossibleAuthors($id, $spines) {
        $ret = [];

        foreach ($spines as $spine) {
            # Work up the array looking for adajcent entries which are either initials or names.
            $gotfirstname = 0;
            $gotlastname = 0;
            $gotinitials = 0;
            $gotauthor = FALSE;
            $currentauthor = '';

            if (!$spine['author']) {
                $fs = strtolower(trim($spine['spine']));

                if (strlen($fs)) {
                    $words = explode(' ', $fs);

                    foreach ($words as $f) {
                        if (strlen(trim($f))) {
                            $isinitial = FALSE;
                            $isfirst = FALSE;
                            $islast = FALSE;

                            if (!$gotinitials) {
                                $initial = str_replace('.', '', $f);
                                #$this->log("Consider initial $initial");

                                if (strlen($initial) > 0 && strlen($initial) <= 2) {
                                    # Single or double letter, possibly with dots.  Likely to be an initial
                                    $this->log("...looks like initial $initial");

                                    # Ignore very common short words which look like initials.
                                    if (!in_array($initial, ['of', 'in'])) {
                                        $isinitial = TRUE;
                                    }
                                }
                            }

                            if (!$isinitial && !$gotfirstname) {
                                # See if it's a first name.
                                $firsts = $this->dbhr->preQuery("SELECT id FROM booktastic_firstnames WHERE firstname = ? AND enabled = 1;", [
                                    $f
                                ], FALSE, FALSE);

                                if (count($firsts)) {
                                    $this->log("...$f looks like a first name");
                                    $isfirst = TRUE;
                                    $gotfirstname++;
                                }
                            }

                            if (!$isinitial && !$isfirst && ($gotinitials || $gotfirstname)) {
                                # See if it's a last name.
                                $lasts = $this->dbhr->preQuery("SELECT id FROM booktastic_lastnames WHERE lastname = ? AND enabled = 1;", [
                                    $f
                                ], FALSE, FALSE);

                                if (count($lasts)) {
                                    $this->log("...$f looks like a last name");
                                    $islast = TRUE;
                                }
                            }

                            # We have a possible author if:
                            # - two parts to it
                            # - firstname and lastname
                            # - two firstnames
                            # - two lastnames
                            # - lastname + 1-2 initials
                            //                            $this->log("$f, " .
                            //                                ($islast ? " is last " : " not last ") .
                            //                                ($isfirst ? " is first " : " not first ") .
                            //                                ($isinitial ? " is initial " : " not initial ") .
                            //                                ($gotlastname ? " got last " : " not got last ") .
                            //                                ($gotfirstname ? " got first " : " not got first ") .
                            //                                ($gotinitials ? " got initials " : " not got initials "));

                            if (strpos($currentauthor, ' ') !== FALSE) {
                                #$this->log("Consider complete");
                                if ($gotinitials && $islast) {
                                    $this->log("Got initials && last => author $currentauthor");
                                    $gotauthor = TRUE;
                                } else if ($islast && $gotfirstname) {
                                    $this->log("Got first && last => author $currentauthor");
                                    $gotauthor = TRUE;
                                }
                            }

                            if ($gotauthor) {
                                $currentauthor = "$currentauthor $f";
                                $currentauthor = trim(str_replace('  ', ' ', $currentauthor));

                                # Check if this author exists.
                                #$this->log("Check valid author $currentauthor");
                                if ($this->validAuthor($currentauthor)) {
                                    break;
                                }

                                # Not valid - keep looking for something else.
                                $gotfirstname = 0;
                                $gotlastname = 0;
                                $gotinitials = 0;
                                $gotauthor = FALSE;
                                $currentauthor = '';
                            } else {
                                # We don't have one yet.  Keep looking.
                                if ($isfirst) {
                                    $gotfirstname++;
                                    $currentauthor .= " $f";
                                } else if ($islast) {
                                    $gotlastname++;
                                    $currentauthor .= " $f";
                                } else if ($isinitial) {
                                    $gotinitials += strlen($f);
                                    $currentauthor .= " $f";
                                }

                                #$this->log("Keep looking $currentauthor");
                            }

                            $this->log("Current name $currentauthor, $gotfirstname, $gotlastname, $gotinitials");
                        }
                    }
                }

                $ret[] = [
                    'spine' => $fs,
                    'author' => $currentauthor ? $currentauthor : NULL,
                    'title' => $currentauthor ? trim(str_ireplace($currentauthor, '', $fs)) : NULL
                ];
            }
        }

        return $ret;
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
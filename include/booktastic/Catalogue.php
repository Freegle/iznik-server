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
    private $logging = FALSE;

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

    private function getMid($vertices) {
        # Get the middle of the bounding box on the right hand side.
        $midy1 = (presdef('y', $vertices[0],0) + presdef('y', $vertices[3],0)) / 2;
        $midy2 = (presdef('y', $vertices[2],0) + presdef('y', $vertices[1],0)) / 2;
        $midx1 = (presdef('x', $vertices[0],0) + presdef('x', $vertices[3],0)) / 2;
        $midx2 = (presdef('x', $vertices[2],0) + presdef('x', $vertices[1],0)) / 2;

        $grad = ($midx2 - $midx1 > 0) ? ($midy2 - $midy1) / ($midx2 - $midx1) : PHP_INT_MAX;

        return [ $midx2, $midy2, $grad ];
    }

    private function intersects($project, $vertices, $axis)
    {
        return ($project <= presdef($axis, $vertices[3], 0) &&
                $project >= presdef($axis, $vertices[0], 0) ||
                $project <= presdef($axis, $vertices[0], 0) &&
                $project >= presdef($axis, $vertices[3], 0));
    }

    private function sortByDistance(&$others, $vertices) {
        usort($others, function($a, $b) use ($vertices) {
            list ($ldist, $rdist) = $this->getDistance($vertices, $a['boundingPoly']['vertices']);
            $adist = min($ldist, $rdist);
            list ($ldist, $rdist) = $this->getDistance($vertices, $b['boundingPoly']['vertices']);
            $bdist = min($ldist, $rdist);

            #$this->log("{$a['description']} {$b['description']} $adist, $bdist");
            return ($adist - $bdist);
        });
    }

    private function getInfo($entry, $horizontalish) {
        $poly = presdef('boundingPoly', $entry, NULL);
        $vertices = $poly['vertices'];
        $orient = $this->orient($vertices, $horizontalish);
        $height = $this->height($vertices, $horizontalish);
        return [ $poly, $vertices, $orient, $height ];
    }

    private function merge(&$merged, $others, $vertices, &$fragments, $index) {
        for ($k = 0; !$merged && $k < count($others); $k++) {
            # Which side is this?
            $other = $others[$k];
            $nvertices = $others[$k]['boundingPoly']['vertices'];
            list ($ldist, $rdist) = $this->getDistance($vertices, $nvertices);

            if ($rdist < $ldist) {
                # It's on the right.
                $fragments[$index]['vertices'] = [
                    $vertices[0],
                    $nvertices[1],
                    $nvertices[2],
                    $vertices[3]
                ];

                $fragments[$index]['description'] .= " {$other['description']}";
            } else {
                $fragments[$index]['vertices'] = [
                    $nvertices[0],
                    $vertices[1],
                    $vertices[2],
                    $nvertices[3]
                ];

                $fragments[$index]['description'] = "{$other['description']} {$fragments[$index]['description']}" ;
            }

            #$this->log("Passes through {$other['description']}, now {$json[$j]['description']}, $ldist, $rdist");

            # Remove the one we've merged.
            $fragments = array_values(array_filter($fragments, function($a) use ($other) {
                return $a['id'] !== $other['id'];
            }));

            $merged = TRUE;
        }
    }

    public function getOverallOrientation($fragments) {
        # Work out whether the orientation of the image is horizontal(ish) or vertical(ish).
        $xtot = 0;
        $ytot = 0;

        foreach ($fragments as $j) {
            $vertices = $j['boundingPoly']['vertices'];

            # Which way is this oriented determines which way we project.
            $xtot += abs(presdef('x', $vertices[1], 0) - presdef('x', $vertices[0], 0));
            $ytot += abs(presdef('y', $vertices[1], 0) - presdef('y', $vertices[0], 0));
        }

        $horizontalish = $xtot >= $ytot;

        return $horizontalish;
    }

    private function addIds(&$fragments) {
        # Give each entry an id so we can find them in different orders.
        $id = 0;
        for ($i = 0; $i < count($fragments); $i ++) {
            $fragments[$i]['id'] = $id++;
        }
    }

    public function identifySpinesFromOCR($id) {
        # The overall aim here is:
        # - assume we have a picture of a single shelf
        # - use the bounding boxes to get the overall orientation of the text (i.e. direction of the spines)
        # - find fragments which are in the same orientation (i.e. on the same spine)
        # - merge them together
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT * FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);

        foreach ($ocrdata as $o) {
            # First item is summary - ignore that.
            $fragments = json_decode($o['text'], TRUE);
            array_shift($fragments);

            $this->addIds($fragments);
            $horizontalish = $this->getOverallOrientation($fragments);

            do {
                # Once we merge we've messed up the array a bit so we bail out the loops and keep going.  This code
                # could be more efficient but it'll do for now.
                $merged = FALSE;
                #$this->log("Scan " . count($fragments));

                for ($j = 0; !$merged && $j < count($fragments); $j++) {
                    # For each fragment that we have, we want to project to find other fragments which overlap.
                    #
                    # The simple case is:
                    #
                    # TEXT1  TEXT2
                    #
                    # ...where projecting from the middle of TEXT1 will hit TEXT2, and we should merge.
                    $entry = $fragments[$j];
                    #$this->log("Consider {$entry['description']} at $j of " . count($fragments));
                    if ($this->isWord($entry['description'])) {
                        list ($poly, $vertices, $orient, $height) = $this->getInfo($entry, $horizontalish);

                        if ($poly) {
                            list ($midx2, $midy2, $grad) = $this->getMid($vertices);
                            $others = [];

                            for ($k = 0; !$merged && $k < count($fragments); $k++) {
                                if ($j !== $k && $this->isWord($fragments[$k]['description'])) {
                                    $nentry = $fragments[$k];
                                    list ($npoly, $nvertices, $norient, $nheight) = $this->getInfo($nentry, $horizontalish);

                                    # The text should only be combined if it's the same orientation.  The publisher
                                    # is often a different orientation.
                                    #$this->log("{$entry['description']} horizontal $horizontalish vs {$nentry['description']} $nhorizontalish");
                                    if ($orient == $norient && $nheight) {
                                        # If we project this line outwards, we should meet the other text which is in a line with this
                                        # one on the same spine.
                                        #
                                        # Which way the image is oriented determines which way we project.
                                        $xgap = presdef('x', $nvertices[0], 0) - presdef('x', $vertices[1], 0);
                                        $ygap = presdef('y', $nvertices[0], 0) - presdef('y', $vertices[1], 0);

                                        if ($horizontalish) {
                                            # Horizontalish.
                                            $projecty = $midy2 + $grad * $xgap;

                                            #$this->log("Projected y = $projecty from $midy2, $grad, {$nvertices[0]['x']}, {$vertices[1]['x']}");
                                            #$this->log("Compare projected horizontally from {$entry['description']} $projecty to {$nvertices[0]['y']} and {$nvertices[3]['y']} for {$nentry['description']}");

                                            if ($this->intersects($projecty, $nvertices, 'y')) {
                                                # The projected line passes through.  Merge these together.
                                                $others[] = $nentry;
                                            }
                                        } else {
                                            # Verticalish.
                                            $projectx = $midx2 + ($grad ? ($ygap / $grad) : 0);

                                            #$this->log("Projected y = $projecty from $midy2, $grad, {$nvertices[0]['x']}, {$vertices[1]['x']}");
                                            #$this->log("Compare projected vertically from {$entry['description']} $projectx to {$nvertices[0]['x']} and {$nvertices[3]['x']} for {$nentry['description']}");

                                            if ($this->intersects($projectx, $nvertices, 'x')) {
                                                # The projected line passes through.  Merge these together.
                                                $others[] = $nentry;
                                                #$this->log("Include projected vertically from {$entry['description']} orient $orient $projectx to {$nvertices[0]['x']} and {$nvertices[3]['x']} for {$nentry['description']} orient $norient");
                                            }
                                        }
                                    }
                                }
                            }

                            # Now we have the words that are in a line with this one.  We want to merge them together.
                            # Sort by distance from this one.
                            $this->sortByDistance($others, $vertices);
                            $this->merge($merged, $others, $vertices, $fragments, $j);
                        }
                    }
                }
            } while ($merged);

            foreach ($fragments as $entry) {
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
                                [ 'fuzzy' => [ 'author' => [ 'value' => $author, 'fuzziness' => 3 ] ] ],
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
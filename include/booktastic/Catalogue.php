<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');

class Catalogue
{
    /** @var  $dbhr LoggedPDO */
    var $dbhr;
    /** @var  $dbhm LoggedPDO */
    var $dbhm;

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
    }

    public function doOCR(Attachment $a, $data) {
        return $a->ocr($data, TRUE);
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

        error_log("Check for uid $uid = " . count($already));
        if (!count($already)) {
            error_log("Not got it");
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

    public function ocr($data, $uid = NULL) {
        # If we have a uid then we might have seen this before.  This is used in UT to avoid hitting
        # Google all the time.
        $already = [];

        if ($uid) {
            $already = $this->dbhr->preQuery("SELECT id, text FROM booktastic_ocr WHERE uid = ?;", [
                $uid
            ], FALSE, FALSE);
        }

        error_log("Check for uid $uid = " . count($already));
        if (!count($already)) {
            error_log("Not got it");
            $a = new Attachment($this->dbhr, $this->dbhm);
            $text = $this->doOCR($a, $data);
            $this->dbhm->preExec("INSERT INTO booktastic_ocr (data, text, uid) VALUES (?, ?, ?);", [
                $data,
                json_encode($text),
                $uid
            ]);

            $id = $this->dbhm->lastInsertId();
        } else {
            $id = $already[0]['id'];
            $text = $already[0]['text'];
            error_log("Already got $id");
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

    public function identifySpinesFromOCR($id) {
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT * FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);


        foreach ($ocrdata as $o) {
            # Get the height and width of the image.  Note that this is not necessarily the same as the orientation
            # returned by the OCR, which is rotated to match the direction of text.  See
            # https://cloud.google.com/vision/docs/reference/rest/v1/AnnotateImageResponse#EntityAnnotation
            $i = new Image(base64_decode($o['data']));
            $w = $i->width();
            $h = $i->height();

            $json = json_decode($o['text'], TRUE);

            # First item is summary - ignore that.
            array_shift($json);

            # Give each entry an id so we can find them in different orders.
            $id = 0;
            for ($i = 0; $i < count($json); $i ++) {
                $json[$i]['id'] = $id++;
            }

            do {
                $merged = FALSE;
                #error_log("Scan " . count($json));

                for ($j = 0; !$merged && $j < count($json); $j++) {
                    $entry = $json[$j];
                    #error_log("Consider {$entry['description']} at $j of " . count($json));
                    $poly = presdef('boundingPoly', $entry, NULL);
                    $vertices = $poly['vertices'];

                    if ($poly) {
                        # Get the middle of the bounding box on the y axis.
                        $midy1 = (presdef('y', $vertices[0],0) + presdef('y', $vertices[3],0)) / 2;
                        $midy2 = (presdef('y', $vertices[2],0) + presdef('y', $vertices[1],0)) / 2;
                        $midx1 = (presdef('x', $vertices[0],0) + presdef('x', $vertices[3],0)) / 2;
                        $midx2 = (presdef('x', $vertices[2],0) + presdef('x', $vertices[1],0)) / 2;

                        if ($midx2 - $midx1 > 0) {
                            $grad = ($midy2 - $midy1) / ($midx2 - $midx1);
                            #error_log("Gradient $grad from $midx1, $midy1 to $midx2, $midy2");

                            # If we project this line outwards, we should meet the other text which is in a line with this
                            # one on the same spine.
                            $others = [];

                            for ($k = 0; !$merged && $k < count($json); $k++) {
                                if ($j !== $k) {
                                    $nentry = $json[$k];
                                    #error_log("Look at " . json_encode($nentry));
                                    $npoly = presdef('boundingPoly', $nentry, NULL);
                                    $nvertices = $npoly['vertices'];

                                    $projecty = $midy2 + $grad * (presdef('x', $nvertices[0], 0) - presdef('x', $vertices[1], 0));

                                    #error_log("Projected y = $projecty from $midy2, $grad, {$nvertices[0]['x']}, {$vertices[1]['x']}");
                                    error_log("Compare projected from {$entry['description']} $projecty to {$nvertices[0]['y']} and {$nvertices[3]['y']} for {$nentry['description']}");

                                    if ($projecty <= presdef('y', $nvertices[3], 0) &&
                                        $projecty >= presdef('y', $nvertices[0], 0)) {
                                        # The projected line passes through.  Merge these together.
                                        $others[] = $nentry;
                                    }
                                }
                            }

                            # Now we have the words that are in a line with this one.  We want to merge them together.  Sort by
                            # distance from this one.
                        error_log("Found in line with {$entry['description']}:");
                        foreach ($others as $o) {
                            error_log("...{$o['description']}");
                        }

                            usort($others, function($a, $b) use ($vertices) {
                                list ($ldist, $rdist) = $this->getDistance($vertices, $a['boundingPoly']['vertices']);
                                $adist = min($ldist, $rdist);
                                list ($ldist, $rdist) = $this->getDistance($vertices, $b['boundingPoly']['vertices']);
                                $bdist = min($ldist, $rdist);

                                #error_log("{$a['description']} {$b['description']} $adist, $bdist");
                                return ($adist - $bdist);
                            });

//                        error_log("Sorted by closeness to {$entry['description']}:");
//                        foreach ($others as $o) {
//                            error_log("...{$o['description']}");
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

                                #error_log("Passes through {$other['description']}, now {$json[$j]['description']}, $ldist, $rdist");

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
                $ret[] = $entry['description'];
                #error_log($entry['description']);
            }
        }

        return $ret;
    }

    public function extractPossibleAuthors($id) {
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT * FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);

        foreach ($ocrdata as $o) {
            $json = json_decode($o['text'], TRUE);

            #error_log("Returned {$o['text']}");
            if (count($json) && pres('description', $json[0])) {
                # Google is good at assembling the fragments into a piece of text and getting the alignment
                # right.  So for now we go with what it returns.  Possibly we could do better by using
                # individual works and their position to identify groups of words (authors or titles) that were
                # on a single book (adjacent spatially).
                $full = $json[0]['description'];
                $fragments = explode("\n", $full);

                # Work up the array looking for adajcent entries which are either initials or names.
                $gotfirstname = 0;
                $gotlastname = 0;
                $gotinitials = 0;
                $gotauthor = FALSE;
                $currentauthor = '';

                for ($i = 0; $i < count($fragments); $i++) {
                    $fs = strtolower(trim($fragments[$i]));

                    if (strlen($fs)) {
                        $words = explode(' ', $fs);

                        foreach ($words as $f) {
                            $isinitial = FALSE;
                            $isfirst = FALSE;
                            $islast = FALSE;

                            $initial = str_replace('.', '', $f);
                            #error_log("Consider initial $initial");

                            if (strlen($initial) <= 2) {
                                # Single or double letter, possibly with dots.  Likely to be an initial
                                #error_log("...looks like one");

                                # Ignore very common short words which look like initials.
                                if (!in_array($initial, [ 'of', 'in' ])) {
                                    $isinitial = TRUE;
                                }
                            }

                            # See if it's a last name.
                            $lasts = $this->dbhr->preQuery("SELECT id FROM booktastic_lastnames WHERE lastname = ? AND enabled = 1;", [
                                $f
                            ], FALSE, FALSE);

                            if (count($lasts)) {
                                #error_log("...$f looks like a last name");
                                $islast = TRUE;
                            }

                            # See if it's a first name.
                            $firsts = $this->dbhr->preQuery("SELECT id FROM booktastic_firstnames WHERE firstname = ? AND enabled = 1;", [
                                $f
                            ], FALSE, FALSE);

                            if (count($firsts)) {
                                #error_log("...$f looks like a first name");
                                $isfirst = TRUE;
                                $gotfirstname++;
                            }

                            # We have a possible author if:
                            # - two parts to it
                            # - firstname and lastname
                            # - two firstnames
                            # - two lastnames
                            # - lastname + 1-2 initials
//                            error_log("$f, " .
//                                ($islast ? " is last " : " not last ") .
//                                ($isfirst ? " is first " : " not first ") .
//                                ($isinitial ? " is initial " : " not initial ") .
//                                ($gotlastname ? " got last " : " not got last ") .
//                                ($gotfirstname ? " got first " : " not got first ") .
//                                ($gotinitials ? " got initials " : " not got initials "));

                            if (strpos($currentauthor, ' ') !== FALSE) {
                                #error_log("Consider complete");
                                if ($gotinitials && $islast) {
                                    #error_log("Got initials && last => author $currentauthor");
                                    $gotauthor = TRUE;
                                } else if ($islast && $gotfirstname) {
                                    #error_log("Got first && last => author $currentauthor");
                                    $gotauthor = TRUE;
                                }
                            }

                            if ($gotauthor) {
                                $currentauthor = "$currentauthor $f";
                                $currentauthor = trim(str_replace('  ', ' ', $currentauthor));

                                # Check if this author exists.
                                #error_log("Check valid author $currentauthor");
                                if ($this->validAuthor($currentauthor)) {
                                    $ret[] = $currentauthor;
                                }

                                $currentauthor = '';
                                $gotauthor = FALSE;
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

                                #error_log("Keep looking $currentauthor");
                            }

                            #error_log("Current name $currentauthor, $gotfirstname, $gotlastname, $gotinitials");
                        }
                    }
                }
            }
        }

        return $ret;
    }

    public function validAuthor($name) {
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
        $json = json_decode($data, TRUE);

        if (!$json) {
            error_log("JSON decode for $name failed " . json_last_error_msg());
        }

        return pres('author', $json);
    }

    public function extricateAuthors($id, $authors) {
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT text FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);

        foreach ($ocrdata as $o) {
            $json = json_decode($o['text'], TRUE);

            if (count($json) && pres('description', $json[0])) {
                $text = trim(strtolower(str_replace("\n", ' ', $json[0]['description'])));
                #error_log("Start with $text");

                do {
                    # Find the first author
                    $minpos = PHP_INT_MAX;
                    $minauthor = NULL;

                    foreach ($authors as $author) {
                        $p = strpos($text, $author);

                        if ($p !== FALSE && $p < $minpos) {
                            $minpos = $p;
                            $minauthor = $author;
                        }
                    }

                    if ($minpos === PHP_INT_MAX) {
                        # Erm...
                        break;
                    }

                    if ($minpos === 0) {
                        # Author at start.  Find the next author after that and assume the title is what's in
                        # between
                        $ret[] = [
                            'type' => 'author',
                            'value' => $minauthor
                        ];

                        $nextpos = PHP_INT_MAX;
                        $nextauthor = NULL;

                        foreach ($authors as $author) {
                            $p = strpos($text, $author, 1);

                            if ($p !== FALSE && $p < $nextpos) {
                                $nextpos = $p;
                                $nextauthor = $author;
                            }
                        }

                        #error_log("Author at start, next at $nextpos");

                        if ($nextpos === PHP_INT_MAX) {
                            # No next one - assume rest of string is title.
                            $ret[] = [
                                'type' => 'title',
                                'value' => trim(substr($text, strlen($minauthor), $nextpos - strlen($minauthor)))
                            ];

                            $text = trim(substr($text, strlen($minauthor)));

                            #error_log("Purported title at end, no next, now $text");
                        } else {
                            $ret[] = [
                                'type' => 'title',
                                'value' => trim(substr($text, strlen($minauthor), $nextpos - strlen($minauthor)))
                            ];

                            $text = trim(substr($text, $nextpos));
                            #error_log("Purported title at end, next at $nextpos, now $text");
                        }
                    } else {
                        # Purported title at start
                        $ret[] = [
                            'type' => 'author',
                            'value' => $minauthor
                        ];

                        $ret[] = [
                            'type' => 'title',
                            'value' => trim(substr($text, 0, $minpos))
                        ];

                        $text = trim(substr($text, $minpos + strlen($minauthor)));
                        #error_log("Purported title at start, now $text");
                    }
                } while (strlen($text));
            }
        }

        #error_log("Extricated " . var_export($ret, TRUE));
        return $ret;
    }
}
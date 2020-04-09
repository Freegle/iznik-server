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

    public function doOCR($a, $data) {
        return $a->ocr($data, TRUE);
    }

    public function ocr($data) {
        $a = new Attachment($this->dbhr, $this->dbhm);
        $text = $this->doOCR($a, $data);
        $this->dbhm->preExec("INSERT INTO booktastic_ocr (data, text) VALUES (?, ?);", [
            $data,
            json_encode($text)
        ]);

        $id = $this->dbhm->lastInsertId();

        return [ $id, $text ];
    }

    public function extractPossibleAuthors($id) {
        $ret = [];

        $ocrdata = $this->dbhm->preQuery("SELECT text FROM booktastic_ocr WHERE id = ?;", [
            $id
        ]);

        foreach ($ocrdata as $o) {
            $json = json_decode($o['text'], TRUE);

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
                $gottitle = FALSE;
                $currentauthor = '';
                $currenttitle = '';
                $done = FALSE;

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
                            #error_log("$f, $islast, $isfirst, $isinitial, $gotlastname, $gotfirstname, $gotinitials");
                            if (strpos($currentauthor, ' ') !== FALSE) {
                                if ($gotinitials && $islast) {
                                    $gotauthor = TRUE;
                                } else if ($isfirst && $gotlastname) {
                                    $gotauthor = TRUE;
                                }
                            }

                            if ($gotauthor) {
                                $currentauthor = "$currentauthor $f";
                                $currentauthor = trim(str_replace('  ', ' ', $currentauthor));

                                # Check if this author exists.
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
                error_log("Start with $text");

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

                        error_log("Author at start, next at $nextpos");

                        if ($nextpos === PHP_INT_MAX) {
                            # No next one - assume rest of string is title.
                            $ret[] = [
                                'type' => 'title',
                                'value' => trim(substr($text, strlen($minauthor), $nextpos - strlen($minauthor)))
                            ];

                            $text = trim(substr($text, strlen($minauthor)));

                            error_log("Purported title at end, no next, now $text");
                        } else {
                            $ret[] = [
                                'type' => 'title',
                                'value' => trim(substr($text, strlen($minauthor), $nextpos - strlen($minauthor)))
                            ];

                            $text = trim(substr($text, $nextpos));
                            error_log("Purported title at end, next at $nextpos, now $text");
                        }
                    } else {
                        # Purported title at start
                        $ret[] = [
                            'type' => 'title',
                            'value' => trim(substr($text, 0, $minpos))
                        ];

                        $ret[] = [
                            'type' => 'author',
                            'value' => $minauthor
                        ];

                        $text = trim(substr($text, $minpos + strlen($minauthor)));
                        error_log("Purported title at start, now $text");
                    }
                } while (strlen($text));
            }
        }

        error_log("Extricated " . var_export($ret, TRUE));
        return $ret;
    }
}
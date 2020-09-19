<?php
namespace Freegle\Iznik;

// @codeCoverageIgnoreStart
// This is a proof of concept for another project, it isn't tested as part of Freegle.



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
            ], FALSE);
        }

        #$this->log("Check for uid $uid = " . count($already));
        if (!count($already) || $video) {
            #$this->log("Not got it");
            $a = new Attachment($this->dbhr, $this->dbhm);
            $text = $this->doOCR($a, $data, $video);
            $this->dbhm->preExec("INSERT INTO booktastic_ocr (text, uid) VALUES (?, ?);", [
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

    public function normaliseTitle($title, $allowempty = FALSE) {
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

        $stitle = $this->removeShortWords($title);

        if (!strlen($stitle)) {
            if ($allowempty) {
                $title = $stitle;
            }
        } else {
            $title = $stitle;
        }

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

    public function getResult($id) {
        $spines = NULL;
        $fragments = NULL;

        $results = $this->dbhr->preQuery("SELECT * FROM booktastic_results WHERE ocrid = ?;", [
            $id
        ], FALSE);

        foreach ($results as $result) {
            $spines = json_decode($result['spines'], TRUE);
            $fragments = json_decode($result['fragments'], TRUE);

            foreach ($spines as $i => $spine) {
                if (Utils::pres('author', $spine)) {
                    $spines[$i]['isbndb'] = $this->findISBN($spine['author'], $spine['title']);
                    $spines[$i]['author'] = ucfirst($spines[$i]['author']);
                    $spines[$i]['title'] = ucfirst($spines[$i]['title']);
                }
            }
        }

        return [ $spines, $fragments ];
    }

    public function findISBN($author, $title) {
        $results = $this->dbhr->preQuery("SELECT * FROM booktastic_books WHERE author LIKE ? AND TITLE LIKE ?;", [
            $author,
            $title
        ], FALSE);

        foreach ($results as $result) {
            $this->dbhm->background("UPDATE booktastic_books SET popularity = popularity + 1 WHERE id = {$result['id']};");
            return $result['book'] ? json_decode($result['book'], TRUE) : NULL;
        }


        $res = $this->ISBNDB($author);
        $book = $this->ISBNFindBook($res, $title);

        $this->dbhm->preExec("INSERT INTO booktastic_books (author, title, added, book) VALUES (?, ?, NOW(), ?);", [
            $author,
            $title,
            json_encode($book)
        ]);

        return $book;
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
            # The guts of this are contracted out to some Go code, which is faster.
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

    public function ISBNDB($author) {
        # Look for existing queries.
        $existing = $this->dbhr->preQuery("SELECT id, results FROM booktastic_isbndb WHERE author LIKE ?;", [
            $author
        ]);

        if (count($existing)) {
            # We have a cached copy.
            $data = $existing[0]['results'];
        } else {
            # We need to query.
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "https://api2.isbndb.com/author/" . urlencode($author) . "?page=1&pageSize=1000");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: " . ISBNDB_KEY));

            $data = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($status == 200 && $data) {
                $this->dbhm->preExec("INSERT INTO booktastic_isbndb (author, results) VALUES (?, ?);", [
                    $author,
                    $data
                ]);
            }
        }

        // There are invalid escape sequences in publishers.
        $data = preg_replace("/\"publisher\".*,/", "", $data);
        $json = json_decode($data, TRUE);

        if (!$json) {
            error_log("JSON decode for $data failed " . json_last_error_msg());
        }

        return $json;
    }

    public function ISBNFindBook($res, $title) {
        $normtitle = $this->normaliseTitle($title);

        foreach ($res['books'] as $book) {
            $hittitle = $this->normaliseTitle($book['title']);

            if (levenshtein($normtitle, $hittitle) < 2) {
                return $book;
            }
        }

        return NULL;
    }
}

// @codeCoverageIgnoreEnd
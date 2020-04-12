<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/booktastic/Catalogue.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class CatalogueTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    /**
     * @dataProvider libraryData
     */
    public function testLibrary($filename) {
        $data = base64_encode(file_get_contents(IZNIK_BASE . $filename . ".jpg"));
        $textfile = @file_get_contents(IZNIK_BASE . $filename . "_text.txt");
        $text = $textfile ? json_decode($textfile, TRUE) : [];

        $booksfile = @file_get_contents(IZNIK_BASE . $filename . "_books.txt");
        $books = $booksfile ? json_decode($booksfile, TRUE) : [];
        
        $c = new Catalogue($this->dbhr, $this->dbhm);

        # First get the positions of books in the image.
//        list ($id, $purportedbooks) = $c->extractPossibleBooks($data, $filename);
//        assertNotNull($id);

        # Now get the text within each book.
        list ($id, $json2) = $c->ocr($data, $filename);
        assertNotNull($id);

        # Now identify spines.
        $c->identifySpinesFromOCR($id);

        $authors = $c->extractPossibleAuthors($id);
        $wip = $c->extricateAuthors($id, $authors);
        if ($text != $wip) {
            error_log("Mismatch " . json_encode($wip));
        }
        assertEquals($text, $wip);
    }

    public function libraryData() {
        return [
            [
                '/test/ut/php/booktastic/20200409_141013'
            ],
//            [
//                '/test/ut/php/booktastic/20200409_141743'
//            ]
        ];
    }
}


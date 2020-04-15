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
class CatalogueTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    /**
     * @dataProvider libraryData
     */
    public function testLibrary($filename)
    {
        if (!getenv('STANDALONE')) {
            $data = base64_encode(file_get_contents(IZNIK_BASE . $filename . ".jpg"));
            $textfile = @file_get_contents(IZNIK_BASE . $filename . "_text.txt");
            $text = $textfile ? json_decode($textfile, TRUE) : [];

            $c = new Catalogue($this->dbhr, $this->dbhm);

            # First get the positions of books in the image.
            //        list ($id, $purportedbooks) = $c->extractPossibleBooks($data, $filename);
            //        assertNotNull($id);

            # Now get the text within each book.
            list ($id, $json2) = $c->ocr($data, $filename);
            assertNotNull($id);

            # Now identify spines.
            $spines = $c->identifySpinesFromOCR($id);
            if ($spines != $text) {
                error_log("Mismatch " . json_encode($spines));
            }
            $books = $c->searchForSpines($id, $spines);

            error_log("\n\n");
            foreach ($books as $book) {
                if ($book['author'] && $book['title']) {
                    error_log("{$book['author']} - {$book['title']}");
                }
            }

            assertEquals($text, $spines);
            //
            //        $booksfile = @file_get_contents(IZNIK_BASE . $filename . "_authors.txt");
            //        $text = $booksfile ? json_decode($booksfile, TRUE) : [];
            //
            //        $spines = $c->extractKnownAuthors($id, $spines);
            //        $spines = $c->extractPossibleAuthors($id, $spines);
            //
            //        if ($spines != $text) {
            //            error_log("Mismatch " . json_encode($spines));
            //        }

            assertEquals($text, $spines);
        }

        assertTrue(TRUE);
    }

    public function libraryData()
    {
        return [
            [
                '/test/ut/php/booktastic/crime2',
            ],
//            [
//                '/test/ut/php/booktastic/vertical_easy',
//            ],
//            [
//                '/test/ut/php/booktastic/basic_horizontal'
//            ],
//            [
//                '/test/ut/php/booktastic/basic_vertical'
//            ],
//            [
//                '/test/ut/php/booktastic/gardening'
//            ],
//            [
//                '/test/ut/php/booktastic/horizontal_overlap'
//            ],
//            [
//                '/test/ut/php/booktastic/horizontal_overlap2'
//            ],
        ];
    }

//    public function testVideo()
//    {
//        $data = base64_encode(file_get_contents(IZNIK_BASE . '/test/ut/php/booktastic/video.mp4'));
//
//        # Now get the text within each book.
//        $c = new Catalogue($this->dbhr, $this->dbhm);
//        list ($id, $json2) = $c->ocr($data, 'video', TRUE);
//    }
}


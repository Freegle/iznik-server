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

            $c = new Catalogue($this->dbhr, $this->dbhm);

            # First get the positions of books in the image.
            //        list ($id, $purportedbooks) = $c->extractPossibleBooks($data, $filename);
            //        assertNotNull($id);

            # Now get the text within each book.
            list ($id, $json2) = $c->ocr($data, $filename);
            assertNotNull($id);

            # Now identify spines.
            $spines = $c->identifySpinesFromOCR2($id);
            $books = $c->searchForSpines($id, $spines);
            $books2 = $c->searchForBrokenSpines($id, $books);

            error_log("\n\n");
            foreach ($books2 as $book) {
                error_log("Book ". json_encode($book));
                if ($book['author'] && $book['title']) {
                    error_log("{$book['author']} - {$book['title']}");
                }
            }

            $booksfile = @file_get_contents(IZNIK_BASE . $filename . "_books.txt");
            $text = $booksfile ? json_decode($booksfile, TRUE) : [];

            $foundnow = 0;

            foreach ($books2 as $book) {
                if ($book['author'] && $book['title']) {
                    $foundnow++;
                }
            }

            $foundthen = 0;

            foreach ($text as $book) {
                if ($book['author'] && $book['title']) {
                    $foundthen++;
                }
            }

            if ($foundthen > $foundnow) {
                error_log("$filename worse");
                error_log(json_encode($books2));
            } else if ($foundthen < $foundnow) {
                error_log("$filename better");
                error_log(json_encode($books2));
            }

            assertEquals($foundthen, $foundnow);
        }

        assertTrue(TRUE);
    }

    public function libraryData()
    {
        return [
            [
                '/test/ut/php/booktastic/bryson',
            ],
//            [
//                '/test/ut/php/booktastic/crime1',
//            ],
//            [
//                '/test/ut/php/booktastic/crime2',
//            ],
//            [
//                '/test/ut/php/booktastic/crime3',
//            ],
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


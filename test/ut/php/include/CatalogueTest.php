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
            list ($spines, $fragments) = $c->identifySpinesFromOCR($id);
            $c->searchForSpines($id, $spines, $fragments);
            $c->searchForBrokenSpines($id, $spines, $fragments);
            $c->recordResults($id, $spines, $fragments);

            error_log("\n\n");
            foreach ($spines as $book) {
                #error_log("Book ". json_encode($book));
                if ($book['author'] && $book['title']) {
                    error_log("{$book['author']} - {$book['title']}");
                }
            }

            $booksfile = @file_get_contents(IZNIK_BASE . $filename . "_books.txt");
            $text = $booksfile ? json_decode($booksfile, TRUE) : [];

            $foundnow = 0;

            foreach ($spines as $book) {
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
                foreach ($text as $then) {
                    if ($then['author']) {
                        $found = FALSE;

                        foreach ($spines as $now) {
                            if ($now['author'] == $then['author'] && $now['title'] == $then['title']) {
                                $found = TRUE;
                            }
                        }

                        if (!$found) {
                            error_log("No longer finding {$then['author']} - {$then['title']}");
                        }
                    }
                }

                error_log(json_encode($spines));

            } else if ($foundthen < $foundnow) {
                error_log("$filename better");

                foreach ($spines as $now) {
                    if ($now['author']) {
                        $found = FALSE;

                        foreach ($text as $then) {
                            if ($now['author'] == $then['author'] && $now['title'] == $then['title']) {
                                $found = TRUE;
                            }
                        }

                        if (!$found) {
                            error_log("Now also finding {$now['author']} - {$now['title']}");
                        }
                    }
                }
                error_log(json_encode($spines));
            }

            assertEquals($foundthen, $foundnow);
        }

        assertTrue(TRUE);
    }

    public function libraryData()
    {
        $ret = [];
        
        foreach ( [ 
            'bryson3',
            'bryson2',
            'bryson',
            'chris1',
            'crime1',
            'crime2',
            'crime3',
            'vertical_easy',
            'basic_horizontal',
            'basic_vertical',
            'gardening',
            'horizontal_overlap',
            'horizontal_overlap2',
        ] as $test) {
            $ret[$test] = [ '/test/ut/php/booktastic/'. $test ];
        }

        return $ret;
    }

//    public function testVideo()
//    {
//        $data = base64_encode(file_get_contents(IZNIK_BASE . '/test/ut/php/booktastic/video.mp4'));
//
//        # Now get the text within each book.
//        $c = new Catalogue($this->dbhr, $this->dbhm);
//        list ($id, $json2) = $c->ocr($data, 'video', TRUE);
//        assertTrue(TRUE);
//    }
}


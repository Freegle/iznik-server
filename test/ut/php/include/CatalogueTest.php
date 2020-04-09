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
        $json = json_decode(file_get_contents(IZNIK_BASE . $filename . ".txt"), TRUE);

        $c = new Catalogue($this->dbhr, $this->dbhm);
        list ($id, $json2) = $c->ocr($data, $filename);
        assertNotNull($id);

        $authors = $c->extractPossibleAuthors($id);
        $wip = $c->extricateAuthors($id, $authors);
        if (!strcmp($json, $wip)) {
            error_log("Mismatch " . json_encode($wip));
        }
        assertEquals($json, $wip);
    }

    public function libraryData() {
        return [
            [
                '/test/ut/php/booktastic/20200409_141013',
            ]
        ];
    }
}


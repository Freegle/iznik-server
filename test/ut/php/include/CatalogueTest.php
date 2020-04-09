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

    public function testOCR() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/booktastic/basic_image');
        $text = file_get_contents(IZNIK_BASE . '/test/ut/php/booktastic/basic_text');
        $json = json_decode($text);

        $mock = $this->getMockBuilder('Catalogue')
            ->setConstructorArgs([
                $this->dbhr,
                $this->dbhm
            ])
            ->setMethods(array('doOCR'))
            ->getMock();
        $mock->method('doOCR')->willReturn($json);

        list ($id, $json2) = $mock->ocr($data);

        assertNotNull($id);
        assertEquals($json, $json2);
    }
}


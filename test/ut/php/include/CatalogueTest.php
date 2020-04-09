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

        $authors = $mock->extractPossibleAuthors($id);
        assertEquals( array (
            0 => 'c. s. lewis',
            1 => 'd. h. lawrence',
            2 => 'john lanchester',
        ), $authors);

        $wip = $mock->extricateAuthors($id, $authors);
        assertEquals(array (
                0 =>
                    array (
                        'type' => 'author',
                        'value' => 'c. s. lewis',
                    ),
                1 =>
                    array (
                        'type' => 'title',
                        'value' => 'the problem of pain',
                    ),
                2 =>
                    array (
                        'type' => 'author',
                        'value' => 'd. h. lawrence',
                    ),
                3 =>
                    array (
                        'type' => 'title',
                        'value' => 'women in love',
                    ),
                4 =>
                    array (
                        'type' => 'author',
                        'value' => 'john lanchester',
                    ),
                5 =>
                    array (
                        'type' => 'title',
                        'value' => 'fragrant harbour interpreter of maladies jhumpa lahiri',
                    ),
            ), $wip);
    }
}


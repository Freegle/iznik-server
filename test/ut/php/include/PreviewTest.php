<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class PreviewTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->dbhm->preExec("DELETE FROM link_previews WHERE url = 'https://google.co.uk';");
        $this->dbhm->preExec("DELETE FROM link_previews WHERE url = 'https://google.ca';");
    }

    public function testBasic() {
        $l = new Preview($this->dbhr, $this->dbhm);
        $id = $l->create('https://google.co.uk');
        assertNotNull($id);
        $atts = $l->getPublic();
        $this->log("Atts " . var_export($atts, TRUE));
        self::assertEquals(0, $atts['invalid']);
        self::assertGreaterThan(0, strlen($atts['title']));

        assertNotFalse(strpos($atts['image'], 'http'));

        $id2 = $l->get('https://google.co.uk');
        self::assertEquals($id, $id2);

        $id3 = $l->get('https://google.ca');
        assertNotNull($id3);

        }

    public function testInvalid() {
        $l = new Preview($this->dbhr, $this->dbhm);
        $id = $l->create('https://googfsdfasdfdsafsdafsdafsdafsd.com');
        assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['invalid']);

        $id = $l->create('https://googfsdfasdfdsafsdafsdafsdafsd');
        assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['invalid']);

        $id = $l->create('https://dbltest.com', TRUE);
        assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['spam']);

        $id = $l->create('https://goo.gl/AqZsSV', TRUE);
        assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['spam']);

        }
}


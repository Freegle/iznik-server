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
        $this->assertNotNull($id);
        $atts = $l->getPublic();
        $this->log("Atts " . var_export($atts, TRUE));
        self::assertEquals(0, $atts['invalid']);
        self::assertGreaterThan(0, strlen($atts['title']));

        // Could get full link or relative path.
        $this->assertTrue(strpos($atts['image'], 'http') !== FALSE || strpos($atts['image'], '/') === 0);

        $id2 = $l->get('https://google.co.uk');
        self::assertEquals($id, $id2);

        $id3 = $l->get('https://google.ca');
        $this->assertNotNull($id3);

        }

    public function testInvalid() {
        $l = new Preview($this->dbhr, $this->dbhm);
        $id = $l->create('https://googfsdfasdfdsafsdafsdafsdafsd.com');
        $this->assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['invalid']);

        $id = $l->create('https://googfsdfasdfdsafsdafsdafsdafsd');
        $this->assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['invalid']);

        $id = $l->create('https://dbltest.com', TRUE);
        $this->assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['spam']);

        $id = $l->create('https://goo.gl/AqZsSV', TRUE);
        $this->assertNotNull($id);
        $atts = $l->getPublic();
        self::assertEquals(1, $atts['spam']);

        }
}


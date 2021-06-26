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
class shortlinkTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM shortlinks WHERE name LIKE 'test%';");
        $this->dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
    }

    public function testGroup() {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        $s = new Shortlink($this->dbhr, $this->dbhm);
        list($id, $url) = $s->resolve('testgroup');
        self::assertEquals('https://' . USER_SITE . '/explore/testgroup', $url);
        $g->setPrivate('onhere', 0);
        list($id, $url) = $s->resolve('testgroup');
        self::assertEquals('https://groups.yahoo.com/testgroup', $url);
        $this->waitBackground();
        sleep(1);

        $s = new Shortlink($this->dbhr, $this->dbhm, $id);
        $atts = $s->getPublic();
        self::assertEquals('testgroup', $atts['name']);
        assertEquals(2, $atts['clicks']);
        assertEquals(2, $atts['clickhistory'][0]['count']);
        assertEquals(substr(date('c'), 0, 10), substr($atts['clickhistory'][0]['date'], 0, 10));

        $list = $s->listAll();
        $found = FALSE;
        foreach ($list as $l) {
            if ($id === $l['id']) {
                $found = TRUE;
            }
        }

        assertTrue($found);

        $s->delete();

        }

    public function testOther() {
        $s = new Shortlink($this->dbhr, $this->dbhm);
        $sid = $s->create('testurl', Shortlink::TYPE_OTHER, NULL, 'https://test.com');
        $atts = $s->getPublic();
        self::assertEquals('testurl', $atts['name']);
        self::assertEquals('https://test.com', $atts['url']);
        self::assertEquals('https://test.com', $s->resolve('testurl')[1]);
        $s->delete();

        }
}



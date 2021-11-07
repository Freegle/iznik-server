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
class IsochroneTest extends IznikTestCase {
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        $i = new Isochrone($this->dbhr, $this->dbhm);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $id = $i->create($uid, Isochrone::WALK, Isochrone::DEFAULT_TIME);
        assertEquals($id, $i->getPublic()['id']);
        $i = new Isochrone($this->dbhr, $this->dbhm, $id);
        assertEquals($id, $i->getPublic()['id']);
        assertNotNull($i->getPublic()['polygon']);

        $isochrones = $i->list($uid);
        assertEquals(1, count($isochrones));
        assertEquals($id, $isochrones[0]['id']);
    }
}


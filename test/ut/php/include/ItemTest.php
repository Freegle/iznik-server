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
class ItemTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM items WHERE name LIKE 'UTTest%';");
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM items WHERE name LIKE 'UTTest%';");
        parent::tearDown ();
    }

    public function testErrors() {
        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec', 'preQuery'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());

        $i = new Item($this->dbhr, $this->dbhm);
        $i->setDbhm($mock);
        $id = $i->create('UTTest');
        $this->assertNull($id);

        }

    public function testWeights() {
        $i = new Item($this->dbhr, $this->dbhm);
        $iid = $i->findByName('sofa');

        if (!$iid) {
            $this->log("Create sofa");
            $i->create('sofa');
            $i->setWeight(37);
        }

        $id = $i->create('UTTest sofa');
        $i = new Item($this->dbhr, $this->dbhm, $id);
        self::assertEquals(37, $i->estimateWeight());

        }
}


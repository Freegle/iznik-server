<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/message/Item.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class itemTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM items WHERE name LIKE 'UTTest%';");
    }

    protected function tearDown() {
        $this->dbhm->preExec("DELETE FROM items WHERE name LIKE 'UTTest%';");
        parent::tearDown ();
    }

    public function testErrors() {
        $mock = $this->getMockBuilder('LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec', 'preQuery'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new Exception());

        $i = new Item($this->dbhr, $this->dbhm);
        $i->setDbhm($mock);
        $id = $i->create('UTTest');
        assertNull($id);

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


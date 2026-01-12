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

    public function testItemNameTruncation() {
        $i = new Item($this->dbhr, $this->dbhm);

        // Create an item with a name exactly at the limit (60 chars).
        $name60 = 'UTTest' . str_repeat('x', 54);  // 6 + 54 = 60 chars
        self::assertEquals(60, strlen($name60));
        $id60 = $i->create($name60);
        $this->assertNotNull($id60);
        $i60 = new Item($this->dbhr, $this->dbhm, $id60);
        self::assertEquals($name60, $i60->getPrivate('name'));
        $i60->delete();

        // Create an item with a name longer than the limit (70 chars).
        $name70 = 'UTTest' . str_repeat('y', 64);  // 6 + 64 = 70 chars
        self::assertEquals(70, strlen($name70));
        $id70 = $i->create($name70);
        $this->assertNotNull($id70);
        $i70 = new Item($this->dbhr, $this->dbhm, $id70);
        // Should be truncated to 60 characters.
        self::assertEquals(60, strlen($i70->getPrivate('name')));
        self::assertEquals(substr($name70, 0, 60), $i70->getPrivate('name'));
        $i70->delete();

        // Create an item with a very long name (200 chars).
        $name200 = 'UTTest' . str_repeat('z', 194);  // 6 + 194 = 200 chars
        self::assertEquals(200, strlen($name200));
        $id200 = $i->create($name200);
        $this->assertNotNull($id200);
        $i200 = new Item($this->dbhr, $this->dbhm, $id200);
        // Should be truncated to 60 characters.
        self::assertEquals(60, strlen($i200->getPrivate('name')));
        $i200->delete();
    }

    public function testItemNameEmpty() {
        $i = new Item($this->dbhr, $this->dbhm);

        // Empty name should return null.
        $idEmpty = $i->create('');
        $this->assertNull($idEmpty);

        // Whitespace-only name should return null.
        $idWhitespace = $i->create('   ');
        $this->assertNull($idWhitespace);
    }
}


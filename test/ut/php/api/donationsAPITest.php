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
 * Testing final automation
 */
class donationsAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic()
    {
        $ret = $this->call('donations', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('donations', $ret));
    }


    public function testExternal() {
        $ret = $this->call('donations', 'PUT', [
            'userid' => 1,
            'amount' => 1,
            'date' => '2022-01-01'
        ]);

        $this->assertEquals(1, $ret['ret']);

        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('permissions', User::PERM_GIFTAID);
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('donations', 'PUT', [
            'userid' => $u->getId(),
            'amount' => 25,
            'date' => '2022-01-01'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('id', $ret));

    }
}

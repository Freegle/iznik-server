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
        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists('donations', $ret));
    }


    public function testExternal() {
        $ret = $this->call('donations', 'PUT', [
            'userid' => 1,
            'amount' => 1,
            'date' => '2022-01-01'
        ]);

        assertEquals(1, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->setPrivate('permissions', User::PERM_GIFTAID);
        $u->addEmail('test@test.com');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('donations', 'PUT', [
            'userid' => $u->getId(),
            'amount' => 25,
            'date' => '2022-01-01'
        ]);
        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists('id', $ret));

    }
}

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
class trystTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u1 = User::get($this->dbhr, $this->dbhm, $u1id);
        $u2id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm, $u2id);
        $u3id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u3 = User::get($this->dbhr, $this->dbhm, $u3id);

        # Create logged out - fail.
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06') // Just in time.
        ]);

        assertEquals(1, $ret['ret']);

        # Create logged in - should work.
        assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        # Read it back.
        $ret = $this->call('tryst', 'GET', [
          'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['tryst']['id']);
        assertEquals($u1id, $ret['tryst']['user1']);
        assertEquals($u2id, $ret['tryst']['user2']);
        assertEquals(Utils::ISODate('2038-01-19 03:14:06'), $ret['tryst']['arrangedfor']);
        $arrangedat = $ret['tryst']['arrangedat'];
        assertNotNull($arrangedat);

        # List
        $ret = $this->call('tryst', 'GET', []);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['trysts']));
        assertEquals($id, $ret['trysts'][0]['id']);

        # As the other user
        assertTrue($u2->login('testpw'));
        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['tryst']['id']);

        # Either user can edit.
        $ret = $this->call('tryst', 'PATCH', [
          'id' => $id,
          'arrangedfor' => Utils::ISODate('2038-01-19 03:14:07'),
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(Utils::ISODate('2038-01-19 03:14:07'), $ret['tryst']['arrangedfor']);

        # Another user shouldn't be able to see it.
        assertTrue($u3->login('testpw'));
        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        assertEquals(2, $ret['ret']);

        # Delete it.
        assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'DELETE', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
    }
}


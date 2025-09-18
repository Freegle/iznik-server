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
class itemAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM items WHERE name LIKE 'UTTest%';");

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->user->addMembership($this->groupid);
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('item', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('item', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create without name
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('item', 'POST', [
        ]);
        $this->assertEquals(3, $ret['ret']);

        # Create as member
        $ret = $this->call('item', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Create as moderator
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('item', 'POST', [
            'name' => 'UTTest',
            'dup' => 2
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        $ret = $this->call('item', 'GET', [
            'id' => $id
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['item']['id']);

        # Typeahead = won't find due to minpop.
        $ret = $this->call('item', 'GET', [
            'typeahead' => 'UTTest'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['items']));

        $ret = $this->call('item', 'GET', [
            'typeahead' => 'UTTest',
            'minpop' => 0
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['items'][0]['id']);
    }

    public function testPatch() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('item', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created $id");

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('item', 'PATCH', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Log back in
        $this->assertTrue($this->user->login('testpw'));

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('item', 'PATCH', [
            'id' => $id,
            'name' => 'UTTest2'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $ret = $this->call('item', 'PATCH', [
            'id' => $id,
            'name' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('item', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('UTTest2', $ret['item']['name']);

        }

    public function testDelete() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('item', 'POST', [
            'name' => 'UTTest',
            'dup' => time() . $this->count++
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('item', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Log back in
        $this->assertTrue($this->user->login('testpw'));

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('item', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('item', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('item', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(2, $ret['ret']);

        }

    public function testTypeahead() {
        # Get invalid id
        $ret = $this->call('item', 'GET', [
            'typeahead' => 'sof'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->log(var_export($ret, TRUE));
    }
}


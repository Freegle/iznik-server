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
class ModConfigAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM mod_configs WHERE name LIKE 'UTTest%';");

        # Create a moderator and log in as them
        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->user->addMembership($this->groupid);
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('modconfig', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create without name
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('modconfig', 'POST', [
        ]);
        $this->assertEquals(3, $ret['ret']);

        # Create as member
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Create as moderator
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        $ret = $this->call('modconfig', 'GET', [
            'id' => $id
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['config']['id']);
        $this->assertEquals($this->uid, $ret['config']['createdby']);

        }

    public function testPatch() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('modconfig', 'PATCH', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Log back in
        $this->assertTrue($this->user->login('testpw'));

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('modconfig', 'PATCH', [
            'id' => $id,
            'name' => 'UTTest2'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $ret = $this->call('modconfig', 'PATCH', [
            'id' => $id,
            'name' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('modconfig', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('UTTest2', $ret['config']['name']);

        # Try as a mod, but the wrong one.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup2', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $user = User::get($this->dbhr, $this->dbhm, $uid);
        $user->addEmail('test2@test.com');
        $user->addMembership($gid, User::ROLE_OWNER);
        $this->addLoginAndLogin($user, 'testpw');

        $ret = $this->call('modconfig', 'PATCH', [
            'id' => $id,
            'name' => 'UTTest3'
        ]);
        $this->assertEquals(4, $ret['ret']);

        }

    public function testDelete() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('modconfig', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Log back in
        $this->assertTrue($this->user->login('testpw'));

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('modconfig', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Try as a mod, but the wrong one.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup2', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $user = User::get($this->dbhr, $this->dbhm, $uid);
        $user->addEmail('test2@test.com');
        $user->addMembership($gid, User::ROLE_OWNER);
        $this->addLoginAndLogin($user, 'testpw');

        $ret = $this->call('modconfig', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('modconfig', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('modconfig', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(2, $ret['ret']);

        }
}


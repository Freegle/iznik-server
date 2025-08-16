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
class bulkOpAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

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
        list($this->user, $this->uid, $emailid) = $this->createTestUserWithMembershipAndLogin($this->groupid, User::ROLE_MEMBER, NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        # Create an empty config
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $this->addLoginAndLogin($this->user, 'testpw');
        @session_start();
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest',
            'dup' => time() . rand()
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        unset($_SESSION['id']);
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('bulkop', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('bulkop', 'POST', [
            'title' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create without title
        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('bulkop', 'POST', [
        ]);
        $this->assertEquals(3, $ret['ret']);

        # Create without configid
        $ret = $this->call('bulkop', 'POST', [
            'title' => "UTTest2"
        ]);
        $this->assertEquals(3, $ret['ret']);

        # Create as member
        $ret = $this->call('bulkop', 'POST', [
            'title' => 'UTTest',
            'configid' => $this->cid
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Create as moderator
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('bulkop', 'POST', [
            'title' => 'UTTest2',
            'configid' => $this->cid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        $ret = $this->call('bulkop', 'GET', [
            'id' => $id
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['bulkop']['id']);

        # Use the config on the group.
        $c = new ModConfig($this->dbhr, $this->dbhm, $this->cid);
        $c->useOnGroup($this->uid, $this->groupid);

        # Make the bulkop a bouncing member one.
        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id,
            'runevery' => 1,
            'action' => 'Unbounce',
            'set' => 'Members',
            'criterion' => 'Bouncing'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Start it
        $date = Utils::ISODate("@" . time());

        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'runstarted' => $date
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Finish it
        $date = Utils::ISODate("@" . time());

        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'runfinished' => $date
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testDue() {
        # Create as moderator
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $this->addLoginAndLogin($this->user, 'testpw');

        # Use the config on the group.
        $c = new ModConfig($this->dbhr, $this->dbhm, $this->cid);
        $c->useOnGroup($this->uid, $this->groupid);

        $ret = $this->call('bulkop', 'POST', [
            'title' => 'UTTest2',
            'configid' => $this->cid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        $b = new BulkOp($this->dbhr, $this->dbhm);
        $due = $b->checkDue($id);
        $this->assertEquals(1, count($due));
        $this->assertEquals($id, $due[0]['id']);
    }

    public function testPatch() {
        $this->addLoginAndLogin($this->user, 'testpw');
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $this->log("Create stdmsg for {$this->cid}");
        $ret = $this->call('bulkop', 'POST', [
            'configid' => $this->cid,
            'title' => 'UTTest'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created $id");

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Log back in
        $this->addLoginAndLogin($this->user, 'testpw');

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('bulkop', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('UTTest2', $ret['bulkop']['title']);

        # Try as a mod, but the wrong one.
        list($g2, $gid) = $this->createTestGroup('testgroup2', Group::GROUP_REUSE);
        list($user, $uid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_OWNER, NULL, NULL, 'Test User', 'test2@test.com', 'testpw');

        $ret = $this->call('bulkop', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest3'
        ]);
        $this->assertEquals(4, $ret['ret']);

        }

    public function testDelete() {
        $this->addLoginAndLogin($this->user, 'testpw');
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('bulkop', 'POST', [
            'configid' => $this->cid,
            'title' => 'UTTest',
            'dup' => time() . $this->count++
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('bulkop', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Log back in
        $this->addLoginAndLogin($this->user, 'testpw');

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('bulkop', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Try as a mod, but the wrong one.
        list($g3, $gid) = $this->createTestGroup('testgroup2', Group::GROUP_REUSE);
        list($user, $uid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_OWNER, NULL, NULL, 'Test User', 'test2@test.com', 'testpw');

        $ret = $this->call('bulkop', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('bulkop', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('bulkop', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(2, $ret['ret']);
    }
}


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
class mergeAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");

        # Create a moderator and log in as them
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->assertEquals(1, $this->user->addMembership($this->groupid));
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user2->addEmail('test2@test.com');
        $this->assertEquals(1, $this->user2->addMembership($this->groupid));
        $this->assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $this->assertTrue($this->user->login('testpw'));
    }

    public function testMerge() {
        $u1 = User::get($this->dbhm, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $u1->addMembership($this->groupid);
        $u1->addEmail('test11@test.com', 0);
        $u2 = User::get($this->dbhm, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);
        $u2->addMembership($this->groupid);
        $u2->addEmail('test12@test.com', 0);
        $u3 = User::get($this->dbhm, $this->dbhm);
        $id3 = $u3->create('Test', 'User', NULL);
        $u3->addEmail('test13@test.com', 0);
        $u3->addMembership($this->groupid);
        $u4 = User::get($this->dbhm, $this->dbhm);

        $id4 = $u4->create('Test', 'User', NULL);
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
        $u4->addEmail('test14@test.com', 0);
        $this->assertGreaterThan(0, $u4->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u4->login('testpw'));

        # Request a merge as a mod.
        $ret = $this->call('merge', 'PUT', [
            'user1' => $id1,
            'user2' => $id2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];
        $uid = $ret['uid'];

        $this->assertNotNull($mid);
        $this->assertNotNull($uid);

        # Log out.
        $_SESSION['id'] = NULL;
        $_SESSION['logged_in'] = FALSE;

        # Shouldn't be able to get without key.
        $ret = $this->call('merge', 'GET', [
            'id' => $mid,
            'uid' => 'zzz'
        ]);

        $this->assertEquals(2, $ret['ret']);

        # Should be able to get with key.
        $ret = $this->call('merge', 'GET', [
            'id' => $mid,
            'uid' => $uid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id1, $ret['merge']['user1']['id']);
        $this->assertEquals($id2, $ret['merge']['user2']['id']);

        # Accept with invalid info
        $ret = $this->call('merge', 'POST', [
            'user1' => $id1,
            'user2' => $id2,
            'id' => $mid,
            'uid' => 'zzz',
            'action' => 'Accept'
        ]);

        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('merge', 'POST', [
            'user1' => $id1,
            'user2' => $id2,
            'id' => -1,
            'uid' => $uid,
            'action' => 'Accept'
        ]);

        $this->assertEquals(2, $ret['ret']);

        # Reject
        $ret = $this->call('merge', 'POST', [
            'user1' => $id1,
            'user2' => $id2,
            'id' => $mid,
            'uid' => $uid,
            'action' => 'Reject'
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Shouldn't be merged.
        $u1 = new User($this->dbhr, $this->dbhm, $id1);
        $this->assertEquals($id1, $u1->getId());
        $u2 = new User($this->dbhr, $this->dbhm, $id2);
        $this->assertEquals($id2, $u2->getId());

        # Accept
        $ret = $this->call('merge', 'POST', [
            'user1' => $id2,
            'user2' => $id1,
            'id' => $mid,
            'uid' => $uid,
            'action' => 'Accept'
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Should be merged.
        $u1 = new User($this->dbhr, $this->dbhm, $id1);
        $this->assertNull($u1->getId());
        $u2 = new User($this->dbhr, $this->dbhm, $id2);
        $this->assertEquals($id2, $u2->getId());
    }

    public function testDelete() {
        $u1 = User::get($this->dbhm, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $u1->addMembership($this->groupid);
        $u2 = User::get($this->dbhm, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);
        $u2->addMembership($this->groupid);
        $u2->addEmail('test2@test.com', 0);
        $u3 = User::get($this->dbhm, $this->dbhm);
        $id3 = $u3->create('Test', 'User', NULL);
        $u3->addEmail('test3@test.com', 0);
        $u3->addMembership($this->groupid);
        $u4 = User::get($this->dbhm, $this->dbhm);

        $id4 = $u4->create('Test', 'User', NULL);
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
        $u4->addEmail('test4@test.com', 0);
        $this->assertGreaterThan(0, $u4->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u4->login('testpw'));

        # Request a merge as a mod.
        $ret = $this->call('merge', 'DELETE', [
            'user1' => $id1,
            'user2' => $id2
        ]);
        $this->assertEquals(0, $ret['ret']);
    }
}


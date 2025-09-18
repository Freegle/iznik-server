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
        list($this->group, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->assertEquals(1, $this->user->addMembership($this->groupid));

        list($this->user2, $this->uid2, $emailid2) = $this->createTestUser(NULL, NULL, 'Test User', 'test2@test.com', 'testpw');
        $this->assertEquals(1, $this->user2->addMembership($this->groupid));

        $this->assertTrue($this->user->login('testpw'));
    }

    public function testMerge() {
        list($u1, $id1, $emailid1) = $this->createTestUser('Test', 'User', NULL, 'test11@test.com', 'testpw');
        $u1->addMembership($this->groupid);
        
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', NULL, 'test12@test.com', 'testpw');
        $u2->addMembership($this->groupid);
        
        list($u3, $id3, $emailid3) = $this->createTestUser('Test', 'User', NULL, 'test13@test.com', 'testpw');
        $u3->addMembership($this->groupid);
        
        list($u4, $id4, $emailid4) = $this->createTestUser('Test', 'User', NULL, 'test14@test.com', 'testpw');
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
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
        
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', NULL, 'test2@test.com', 'testpw');
        $u2->addMembership($this->groupid);
        
        list($u3, $id3, $emailid3) = $this->createTestUser('Test', 'User', NULL, 'test3@test.com', 'testpw');
        $u3->addMembership($this->groupid);
        
        list($u4, $id4, $emailid4) = $this->createTestUser('Test', 'User', NULL, 'test4@test.com', 'testpw');
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($u4->login('testpw'));

        # Request a merge as a mod.
        $ret = $this->call('merge', 'DELETE', [
            'user1' => $id1,
            'user2' => $id2
        ]);
        $this->assertEquals(0, $ret['ret']);
    }
}


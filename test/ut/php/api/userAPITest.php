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
class userAPITest extends IznikAPITestCase {
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

        list($this->group, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        list($this->user, $this->uid, $emailid) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User', 'test@test.com', 'testpw');

        list($this->user2, $this->uid2, $emailid2) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User', 'test2@test.com', 'testpw');

        $this->assertTrue($this->user->login('testpw'));
    }

    public function testRegister() {
        $email = 'test3@test.com';

        # Invalid
        $ret = $this->call('user', 'PUT', [
            'password' => 'wibble'
        ]);
        $this->assertEquals(1, $ret['ret']);
        
        # Register successfully
        $this->log("Register expect success");
        $ret = $this->call('user', 'PUT', [
            'email' => $email,
            'password' => 'wibble'
        ]);
        $this->log("Expect success returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        $ret = $this->call('user', 'GET', [
            'id' => $id,
            'info' => TRUE
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals($email, $ret['user']['emails'][0]['email']);
        $this->assertTrue(array_key_exists('replies', $ret['user']['info']));

        # Register with email already taken and wrong password.  Should return OK
        $ret = $this->call('user', 'PUT', [
            'email' => $email,
            'password' => 'wibble2'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Register with same email and pass
        $ret = $this->call('user', 'PUT', [
            'email' => $email,
            'password' => 'wibble'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['id']);

        # Register with no password
        $ret = $this->call('user', 'PUT', [
            'email' => 'test4@test.com'
        ]);

        $this->assertEquals(0, $ret['ret']);
    }
    
    public function testDeliveryType() {
        # Shouldn't be able to do this as a member
        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooDeliveryType' => 'DIGEST',
            'email' => 'test@test.com'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'suspectcount' => 0,
            'yahooDeliveryType' => 'DIGEST',
            'email' => 'test@test.com',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testTrust() {
        # Shouldn't only be able to turn on/off as a member.
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => NULL
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(array_key_exists('trustlevel', $ret['me']));

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => User::TRUST_BASIC
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(User::TRUST_BASIC, $ret['me']['trustlevel']);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => User::TRUST_MODERATE
        ]);

        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(User::TRUST_BASIC, $ret['me']['trustlevel']);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => User::TRUST_ADVANCED
        ]);

        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(User::TRUST_BASIC, $ret['me']['trustlevel']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'trustlevel' => User::TRUST_ADVANCED
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(User::TRUST_ADVANCED, $ret['user']['trustlevel']);
    }

    public function testPostingStatus() {
        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooPostingStatus' => 'PROHIBITED'
        ]);
        $this->assertEquals(2, $ret['ret']);

        User::clearCache($this->uid);

        # Shouldn't be able to do this as a member
        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooPostingStatus' => 'PROHIBITED',
            'duplicate' => 0
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooPostingStatus' => 'PROHIBITED',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testNewsletter() {
        # Shouldn't be able to do this as a member
        list($u, $uid, $emailid) = $this->createTestUser("Test", "User", "Test User", 'test3@test.com', 'testpw');

        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'newslettersallowed' => 'FALSE'
        ]);

        $this->assertEquals(2, $ret['ret']);

        # As a non-freegle mod, shouldn't work.
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'GET', [
            'id' => $uid
        ]);

        # Should still be turned on.
        $this->assertEquals(1, $ret['user']['newslettersallowed']);

        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'newslettersallowed' => 'FALSE'
        ]);

        $this->assertEquals(2, $ret['ret']);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $this->group->create('testgroup2', Group::GROUP_FREEGLE);
        $this->user->addMembership($gid, User::ROLE_MODERATOR);

        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'newslettersallowed' => 'FALSE',
            'groupid' => $this->groupid,
            'password' => 'testpw2'
        ]);

        # Should be allowed.
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid
        ]);

        # Should have changed.
        $this->assertEquals($uid, $ret['user']['id']);
        $this->assertFalse(array_key_exists('newslettersallowed', $ret['user']));

        # As the mod themselves for coverage,
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid,
            'newslettersallowed' => 'FALSE',
            'groupid' => $this->groupid,
            'ourPostingStatus' => Group::POSTING_PROHIBITED,
            'emailfrequency' => 1
        ]);

        # Should be allowed.
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid
        ]);

        # Should have changed.
        $this->assertEquals($this->uid, $ret['user']['id']);
        $this->assertFalse(array_key_exists('newslettersallowed', $ret['user']));
        $this->assertEquals(Group::POSTING_PROHIBITED, $ret['user']['memberof'][0]['ourpostingstatus']);
        $this->assertEquals(1, $ret['user']['memberof'][0]['emailfrequency']);

        # Login with old password should fail as we changed it above.
        $_SESSION['id'] = NULL;
        $this->assertFalse($u->login('testpw'));
        $this->assertTrue($u->login('testpw2'));
    }

    public function testHoliday() {
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => '2017-12-25'
        ]);
        $this->assertEquals(2, $ret['ret']);

        User::clearCache($this->uid);

        # Shouldn't be able to do this as a member
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => '2017-12-25'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('onholidaytill', $ret['user']));

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => '2017-12-25'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2,
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Dates in the past are not returned.
        $this->assertFalse(array_key_exists('onholidaytill', $ret['user']));

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => NULL
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('onholidaytill', $ret['user']));

        }

    public function testPassword() {
        list($u, $uid, $emailid) = $this->createTestUser("Test", "User", "Test User", 'test4@test.com', 'testpw');

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid,
            'password' => 'testtest'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $_SESSION['supportAllowed'] = TRUE;

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid,
            'password' => 'testtest'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->assertFalse($u->login('testbad'));
        $this->assertFalse($u->login('testtest'));

        }

    public function testMail() {
        # Create a user for testing the API.
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test5@test.com', 'testpw');

        # Shouldn't be able to do this as a non-member.
        $ret = $this->call('user', 'POST', [
            'action' => 'Reply',
            'subject' => "Test",
            'body' => "Test",
            'id' => $uid
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Shouldn't be able to do this as a member
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('user', 'POST', [
            'subject' => "Test",
            'body' => "Test",
            'dup' => 1,
            'id' => $uid
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'POST', [
            'action' => 'Mail',
            'subject' => "Test",
            'body' => "Test",
            'groupid' => $this->groupid,
            'dup' => 2,
            'id' => $uid
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testLog() {
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Sleep for background logging
        $this->waitBackground();

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid,
            'logs' => TRUE
        ]);

        # Can't see logs when another user who is not not a mod on the group
        $this->log("Check can't see {$this->uid} as other member {$this->uid2}");
        $ret = $this->call('session', 'POST', [
            'email' => 'test2@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);

        $this->assertNull($log);

        # Promote.
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Sleep for background logging
        $this->waitBackground();

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);
        $this->assertEquals($this->groupid, $log['group']['id']);

        # Can also see as ourselves.
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);
        $this->assertEquals($this->groupid, $log['group']['id']);

        }

    public function testDelete() {
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'testdelete@test.com', 'testpw');

        $ret = $this->call('user', 'DELETE', [
            'id' => $uid
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $_SESSION['supportAllowed'] = TRUE;

        $ret = $this->call('user', 'DELETE', [
            'id' => $uid
        ]);
        $this->assertEquals(0, $ret['ret']);

        }

    public function testSupportSearch() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $_SESSION['supportAllowed'] = TRUE;
        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));

        # Search across all groups.
        $ret = $this->call('user', 'GET', [
            'search' => 'test@test'
        ]);
        $this->log("Search returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['users']));
        $this->assertEquals($this->uid, $ret['users'][0]['id']);

        # Test a phone number.
        $this->user->addEmail('+44794000000@mediamessaging.o2.co.uk', 0);
        $ret = $this->call('user', 'GET', [
            'search' => '+44794000000@mediamessaging.o2.co.uk'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['users']));
        $this->assertEquals($this->uid, $ret['users'][0]['id']);

        # Test that a mod can't see stuff
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $this->assertEquals(1, $this->user->removeMembership($this->groupid));

        # Search across all groups.
        $ret = $this->call('user', 'GET', [
            'search' => 'tes2t@test.com'
        ]);
        $this->log("Should fail " . var_export($ret, TRUE));
        $this->assertEquals(2, $ret['ret']);
    }

    public function testMerge() {
        list($u1, $id1, $emailid1) = $this->createTestUser('Test', 'User', 'Test User', 'testmerge1@test.com', 'testpw');
        $u1->addMembership($this->groupid);
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', 'Test User', 'test2@test.com', 'testpw');
        $u2->addMembership($this->groupid);
        list($u3, $id3, $emailid3) = $this->createTestUser('Test', 'User', 'Test User', 'test3@test.com', 'testpw');
        $u3->addMembership($this->groupid);
        list($u4, $id4, $emailid4) = $this->createTestUser('Test', 'User', 'Test User', 'test4@test.com', 'testpw');
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
        list($u5, $id5, $emailid5) = $this->createTestUser('Test', 'User', 'Test User', 'test5@test.com', 'testpw');
        $u5->addMembership($this->groupid);

        # Add giftaids to both.
        $d = new Donations($this->dbhr, $this->dbhm);
        $d->setGiftAid($id3, Donations::PERIOD_SINCE, "Test User", "Test Address");
        $d->setGiftAid($this->uid2, Donations::PERIOD_PAST_4_YEARS_AND_FUTURE, "Test User", "Test Address");

        # Can't merge not a mod
        $this->addLoginAndLogin($u1, 'testpw');

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        $this->assertEquals(4, $ret['ret']);

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test22@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        $this->assertEquals(3, $ret['ret']);

        # As mod should work
        $this->addLoginAndLogin($u4, 'testpw');

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Check best gift aid chosen.
        $giftaid = $d->getGiftAid($u3->getId());
        $this->assertEquals(Donations::PERIOD_PAST_4_YEARS_AND_FUTURE, $giftaid['period']);

        # Merge again should work.
        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT',
            'dup' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Already the same user', $ret['status']);

        # This merge should end up with test3 as primary.
        $id = $u1->findByEmail('test3@test.com');
        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertEquals('test3@test.com', $u->getEmailPreferred());

        # Merge self and check still mod.
        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test5@test.com',
            'email2' => 'test4@test.com',
            'reason' => 'UT'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $u1->findByEmail('test4@test.com');
        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertTrue($u->isModOrOwner($this->groupid));

        # Again for coverage of cache case.
        self::assertTrue($u->isModOrOwner($this->groupid));
    }

    public function testMergeById() {
        list($u1, $id1, $emailid1) = $this->createTestUser('Test', 'User', 'Test User', 'testmerge6@test.com', 'testpw');
        $u1->addMembership($this->groupid);
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', 'Test User', 'testmerge7@test.com', 'testpw');
        $u2->addMembership($this->groupid);
        list($u3, $id3, $emailid3) = $this->createTestUser('Test', 'User', 'Test User', 'testmerge8@test.com', 'testpw');
        $u3->addMembership($this->groupid);
        list($u4, $id4, $emailid4) = $this->createTestUser('Test', 'User', 'Test User', 'testmerge9@test.com', 'testpw');
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
        list($u5, $id5, $emailid5) = $this->createTestUser('Test', 'User', 'Test User', 'testmerge10@test.com', 'testpw');
        $u5->addMembership($this->groupid);

        # Can't merge not a mod
        $this->addLoginAndLogin($u1, 'testpw');

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'id1' => $id2,
            'id2' => $id3,
            'reason' => 'UT'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # Invalid id.

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'id1' => -$id2,
            'id2' => $id3,
            'reason' => 'UT'
        ]);
        $this->assertEquals(4, $ret['ret']);

        # As mod should work
        $this->addLoginAndLogin($u4, 'testpw');

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'id1' => $id2,
            'id2' => $id3,
            'reason' => 'UT'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # id2 now gone.
        User::clearCache();
        $u = new User($this->dbhr, $this->dbhm, $id2);
        $this->assertNull($u->getId());
        $u = new User($this->dbhr, $this->dbhm, $id1);
        $this->assertEquals($id1, $u->getId());
    }

    public function testCantMerge() {
        $u1 = User::get($this->dbhm, $this->dbhm);
        list($u1, $id1) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test', 'User', NULL, 'test1@test.com', 'testpw');
        list($u2, $id2) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test', 'User', NULL, 'test2@test.com', 'testpw');
        $u2->addEmail('test2@test.com', 0);
        list($u3, $id3) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test', 'User', NULL, 'test3@test.com', 'testpw');
        $u3->addEmail('test3@test.com', 0);
        $u3->setSetting('canmerge', FALSE);
        list($u4, $id4) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MODERATOR, 'Test', 'User', NULL, 'test4@test.com', 'testpw');
        $u4->addEmail('test4@test.com', 0);

        $this->addLoginAndLogin($u4, 'testpw');

        User::clearCache();

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        $this->assertNotEquals(0, $ret['ret']);
    }

    public function testUnbounce() {
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test3@test.com', 'testpw');
        $u->addMembership($this->groupid);
        $u->setPrivate('bouncing', 1);

        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'filter' => Group::FILTER_BOUNCING
        ]);

        self::assertEquals(1, $ret['members'][0]['bouncing']);

        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'groupid' => $this->groupid,
            'action' => 'Unbounce'
        ]);

        $ret = $this->call('memberships', 'GET', [
            'groupid' => $this->groupid,
            'filter' => Group::FILTER_BOUNCING
        ]);

        self::assertEquals(0, count($ret['members']));

        $this->waitBackground();

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_USER, Log::SUBTYPE_UNBOUNCE, $logs[$this->uid]['logs']);
        $this->assertNotNull($log);
    }

    public function testUnbounceAsMember()
    {
        list($u, $uid, $emailid) = $this->createTestUser(null, null, 'Test User', 'testunbounce2@test.com', 'testpw');
        $u->addMembership($this->groupid);
        $u->setPrivate('bouncing', 1);

        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'Unbounce'
        ]);

        $this->assertEquals(0, $ret['ret']);
    }

    public function testAddEmail() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_USER);
        $this->assertTrue($this->user->login('testpw'));

        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'testaddemail@test.com', 'testpw');

        # Add for another user - should fail.
        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'AddEmail',
            'email' => 'test4@test.com'
        ]);

        $this->assertEquals(4, $ret['ret']);

        # Add for ourselves, should fail - members add email via session call, not user call..
        $ret = $this->call('user', 'POST', [
            'id' => $this->user->getId(),
            'action' => 'AddEmail',
            'email' => 'test4@test.com'
        ]);

        $this->assertEquals(3, $ret['ret']);
        $this->assertEquals('test@test.com', $this->user->getEmailPreferred());

        # Add as an admin - should work.
        list($au, $auid, $emailidau) = $this->createTestUser("Test", "User", "Test User", 'testadmin@test.com', 'testpw');
        $au->setPrivate("systemrole", User::SYSTEMROLE_ADMIN);
        $this->assertTrue($au->login('testpw'));
        $_SESSION['supportAllowed'] = TRUE;

        $ret = $this->call('user', 'POST', [
            'id' => $this->user->getId(),
            'action' => 'AddEmail',
            'email' => 'test4@test.com',
            'dup' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('test4@test.com', $this->user->getEmailPreferred());

        # Remove for another user - should fail.
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'RemoveEmail',
            'email' => 'test2@test.com'
        ]);

        $this->assertEquals(2, $ret['ret']);

        # Remove for ourselves, should work.
        $ret = $this->call('user', 'POST', [
            'id' => $this->user->getId(),
            'action' => 'RemoveEmail',
            'email' => 'test4@test.com'
        ]);

        $this->assertEquals(0, $ret['ret']);
        User::clearCache($this->user->getId());
        $u = User::get($this->dbhr, $this->dbhm, $this->user->getId());
        $this->assertEquals('test@test.com', $u->getEmailPreferred());
    }

    public function testRating() {
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $u->addMembership($this->groupid);

        list($u2, $uid2, $emailid2) = $this->createTestUser(NULL, NULL, 'Test User', 'testrating@test.com', 'testpw');

        $ret = $this->call('user', 'GET', [
            'info' => TRUE,
            'id' => $uid
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('user', 'POST', [
            'action' => 'Rate',
            'ratee' => $uid,
            'rating' => User::RATING_UP
        ]);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(1, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # No API call for showing rated except export which is a bit of a faff.
        $rated = $this->user->getRated();
        self::assertEquals($uid, $rated[0]['ratee']);

        # Test remove rating.
        $ret = $this->call('user', 'POST', [
            'action' => 'Rate',
            'ratee' => $uid,
            'rating' => NULL
        ]);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        $ret = $this->call('user', 'POST', [
            'action' => 'Rate',
            'ratee' => $uid,
            'rating' => User::RATING_DOWN,
            'reason' => User::RATINGS_REASON_NOSHOW,
            'text' => "Didn't turn up"
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(1, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Rating should not be visible to someone else  there has been no interaction.
        $this->assertTrue($u2->login('testpw'));
        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Should not flag as visible yet.
        $u->ratingVisibility();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Create some interaction.
        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cid, $blocked) = $cr->createConversation($this->user->getId(), $uid);
        list ($mid1, $j) = $cm->create($cid, $this->user->getId(), "test");
        list ($mid2, $j) = $cm->create($cid, $uid, "test");

        $this->log("Created conversation between {$this->user->getId()} and $uid");
        $u->ratingVisibility();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(1, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Delete that interaction and re-rate - should get set to not visible.
        $this->dbhm->preExec("DELETE FROM chat_messages WHERE id IN (?,?)", [
            $mid1,
            $mid2
        ]);

        $u->ratingVisibility();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Add the other kind of interaction.  Fake a reply from $uid to a message ostensibly posted by $this->user.
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');
        list($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup', 'from@test.com', 'to@test.com', null, null, []);
        $this->assertEquals(MailRouter::PENDING, $rc);

        $cm->create($cid, $uid, "test", ChatMessage::TYPE_DEFAULT, $id);

        $u->ratingVisibility();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(1, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # The rating should be visible to a mod on the rater and ratee's group.
        list($u, $modid) = $this->createTestUserWithMembershipAndLogin($this->groupid, User::ROLE_MODERATOR, 'Test', 'User', 'Test User', 'test@test.com', 'testpw');
        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['ratings']));
        $this->assertEquals(User::RATINGS_REASON_NOSHOW, $ret['ratings'][0]['reason']);
        $this->assertEquals($this->user->getId(), $ret['ratings'][0]['rater']);

        # Mark the rating as reviewed.  Should still show.
        $ret = $this->call('user', 'POST', [
            'action' => 'RatingReviewed',
            'ratingid' => $ret['ratings'][0]['id']
        ]);
        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::HAPPINESS,
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['ratings']));

        # Unrate
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('user', 'POST', [
            'action' => 'Rate',
            'ratee' => $uid,
            'rating' => NULL
        ]);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);
    }

    public function testActive() {
        $this->assertEquals(1, $this->user->addMembership($this->groupid));
        $this->addLoginAndLogin($this->user, 'testpw');

        # Trigger a notification check - should mark this as active.
        $ret = $this->call('notification', 'GET', [
            'count' => TRUE,
            'modtools' => FALSE
        ]);
        $this->waitBackground();

        self::assertEquals(1, count($this->user->getActive()));

        $active = $this->user->mostActive($this->groupid);
        self::assertEquals($this->user->getId(), $active[0]['id']);

        # Retrieve that info as a mod.
        list($u, $mod, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'testactive@test.com', 'testpw');
        $this->assertEquals(1, $u->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::APPROVED,
            'filter' => Group::FILTER_MOSTACTIVE,
            'groupid' => $this->groupid
        ]);

        $this->log("Get most active " . var_export($ret, TRUE));
        self::assertEquals($this->user->getId(), $ret['members'][0]['id']);

        # Ban them
        $this->user->removeMembership($this->groupid, TRUE);

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::APPROVED,
            'filter' => Group::FILTER_BANNED,
            'groupid' => $this->groupid
        ]);

        $this->log("Get banned " . var_export($ret, TRUE));
        self::assertEquals($this->user->getId(), $ret['members'][0]['id']);

        # Test again with context for coverage.
        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::APPROVED,
            'filter' => Group::FILTER_BANNED,
            'groupid' => $this->groupid,
            'context' => [
                'date' => Utils::ISODate(date("Y-m-d H:i:s", strtotime('tomorrow')))
            ]
        ]);

        $this->log("Get banned " . var_export($ret, TRUE));
        self::assertEquals($this->user->getId(), $ret['members'][0]['id']);

    }

    public function  testGravatar() {
        $u = new User($this->dbhr, $this->dbhm);
        self::assertNotNull($u->gravatar('edward@ehibbert.org.uk'));
    }

    public function testEngage() {
        $ret = $this->call('user', 'POST', [
            'id' => $this->user->getId(),
            'engageid' => -1
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testSupportUnsubscribe() {
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'testunsub1@test.com', 'testpw');
        list($u2, $uid2, $emailid2) = $this->createTestUser(NULL, NULL, 'Test User', 'testunsub2@test.com', 'testpw');
        list($umod, $mod, $emailidmod) = $this->createTestUser(NULL, NULL, 'Test User', 'testunsub3@test.com', 'testpw');
        $umod->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

        # User shouldn't be able to unsub another.
        $this->assertTrue($u2->login('testpw'));
        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'Unsubscribe'
        ]);
        $this->assertEquals(4, $ret['ret']);
        $this->assertNull($u->getPrivate('deleted'));

        # Support should be able to.
        $this->assertTrue($umod->login('testpw'));
        $_SESSION['supportAllowed'] = TRUE;
        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'Unsubscribe',
            'bump' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        User::clearCache($uid);
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertNotNull($u->getPrivate('deleted'));
    }

    public function testCreateFullName() {
        $ret = $this->call('user', 'PUT', [
            'email' => 'test4@test.com',
            'password' => 'wibble',
            'fullname' => 'Test User'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        $ret = $this->call('user', 'GET', [
            'id' => $id,
            'info' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test User', $ret['user']['fullname']);
        $this->assertEquals('Test User', $ret['user']['displayname']);
    }
}


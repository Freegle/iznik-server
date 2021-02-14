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

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");

        # Create a moderator and log in as them
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        assertEquals(1, $this->user->addMembership($this->groupid));
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user2->addEmail('test2@test.com');
        assertEquals(1, $this->user2->addMembership($this->groupid));
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        assertTrue($this->user->login('testpw'));
    }

    public function testRegister() {
        $email = 'test3@test.com';

        # Invalid
        $ret = $this->call('user', 'PUT', [
            'password' => 'wibble'
        ]);
        assertEquals(1, $ret['ret']);
        
        # Register successfully
        $this->log("Register expect success");
        $ret = $this->call('user', 'PUT', [
            'email' => $email,
            'password' => 'wibble'
        ]);
        $this->log("Expect success returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        $ret = $this->call('user', 'GET', [
            'id' => $id,
            'info' => TRUE
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals($email, $ret['user']['emails'][0]['email']);
        assertTrue(array_key_exists('replies', $ret['user']['info']));

        # Register with email already taken and wrong password.  Should return OK
        $ret = $this->call('user', 'PUT', [
            'email' => $email,
            'password' => 'wibble2'
        ]);
        assertEquals(2, $ret['ret']);

        # Register with same email and pass
        $ret = $this->call('user', 'PUT', [
            'email' => $email,
            'password' => 'wibble'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['id']);

        # Register with no password
        $ret = $this->call('user', 'PUT', [
            'email' => 'test4@test.com'
        ]);

        assertEquals(0, $ret['ret']);
    }
    
    public function testDeliveryType() {
        # Shouldn't be able to do this as a member
        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooDeliveryType' => 'DIGEST',
            'email' => 'test@test.com'
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'suspectcount' => 0,
            'yahooDeliveryType' => 'DIGEST',
            'email' => 'test@test.com',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);
    }

    public function testTrust() {
        # Shouldn't only be able to turn on/off as a member.
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => NULL
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('trustlevel', $ret['me']));

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => User::TRUST_BASIC
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(User::TRUST_BASIC, $ret['me']['trustlevel']);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => User::TRUST_MODERATE
        ]);

        assertEquals(2, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(User::TRUST_BASIC, $ret['me']['trustlevel']);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->user->getId(),
            'trustlevel' => User::TRUST_ADVANCED
        ]);

        assertEquals(2, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'components' => [ 'me' ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(User::TRUST_BASIC, $ret['me']['trustlevel']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'trustlevel' => User::TRUST_ADVANCED
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(User::TRUST_ADVANCED, $ret['user']['trustlevel']);
    }

    public function testPostingStatus() {
        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooPostingStatus' => 'PROHIBITED'
        ]);
        assertEquals(2, $ret['ret']);

        User::clearCache($this->uid);

        # Shouldn't be able to do this as a member
        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooPostingStatus' => 'PROHIBITED',
            'duplicate' => 0
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'PATCH', [
            'groupid' => $this->groupid,
            'yahooPostingStatus' => 'PROHIBITED',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);
    }

    public function testNewsletter() {
        # Shouldn't be able to do this as a member
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->addEmail('test2@test.com');

        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'newslettersallowed' => 'FALSE'
        ]);

        assertEquals(2, $ret['ret']);

        # As a non-freegle mod, shouldn't work.
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'GET', [
            'id' => $uid
        ]);

        # Should still be turned on.
        assertEquals(1, $ret['user']['newslettersallowed']);

        $ret = $this->call('user', 'PATCH', [
            'id' => $uid,
            'newslettersallowed' => 'FALSE'
        ]);

        assertEquals(2, $ret['ret']);

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
        assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid
        ]);

        # Should have changed.
        assertEquals($uid, $ret['user']['id']);
        assertFalse(array_key_exists('newslettersallowed', $ret['user']));

        # As the mod themselves for coverage,
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid,
            'newslettersallowed' => 'FALSE',
            'groupid' => $this->groupid,
            'ourPostingStatus' => Group::POSTING_PROHIBITED,
            'emailfrequency' => 1
        ]);

        # Should be allowed.
        assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid
        ]);

        # Should have changed.
        assertEquals($this->uid, $ret['user']['id']);
        assertFalse(array_key_exists('newslettersallowed', $ret['user']));
        assertEquals(Group::POSTING_PROHIBITED, $ret['user']['memberof'][0]['ourpostingstatus']);
        assertEquals(1, $ret['user']['memberof'][0]['emailfrequency']);

        # Login with old password should fail as we changed it above.
        $_SESSION['id'] = NULL;
        assertFalse($u->login('testpw'));
        assertTrue($u->login('testpw2'));
    }

    public function testHoliday() {
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => '2017-12-25'
        ]);
        assertEquals(2, $ret['ret']);

        User::clearCache($this->uid);

        # Shouldn't be able to do this as a member
        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => '2017-12-25'
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2
        ]);
        assertEquals(0, $ret['ret']);
        assertFalse(Utils::pres('onholidaytill', $ret['user']));

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => '2017-12-25'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2,
        ]);
        assertEquals(0, $ret['ret']);

        # Dates in the past are not returned.
        assertFalse(array_key_exists('onholidaytill', $ret['user']));

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid2,
            'groupid' => $this->groupid,
            'onholidaytill' => NULL
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $this->uid2
        ]);
        assertEquals(0, $ret['ret']);
        assertFalse(Utils::pres('onholidaytill', $ret['user']));

        }

    public function testPassword() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->addEmail('test2@test.com');

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid,
            'password' => 'testtest'
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

        $ret = $this->call('user', 'PATCH', [
            'id' => $this->uid,
            'password' => 'testtest'
        ]);
        assertEquals(0, $ret['ret']);

        assertFalse($u->login('testbad'));
        assertFalse($u->login('testtest'));

        }

    public function testMail() {
        # Create a user without an email - we're just testing the API.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        # Shouldn't be able to do this as a non-member.
        $ret = $this->call('user', 'POST', [
            'action' => 'Reply',
            'subject' => "Test",
            'body' => "Test",
            'id' => $uid
        ]);
        assertEquals(2, $ret['ret']);

        # Shouldn't be able to do this as a member
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('user', 'POST', [
            'subject' => "Test",
            'body' => "Test",
            'dup' => 1,
            'id' => $uid
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('user', 'POST', [
            'action' => 'Mail',
            'subject' => "Test",
            'body' => "Test",
            'groupid' => $this->groupid,
            'dup' => 2,
            'id' => $uid
        ]);
        assertEquals(0, $ret['ret']);
    }

    public function testLog() {
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

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
        assertEquals(0, $ret['ret']);

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, FALSE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);

        assertNull($log);

        # Promote.
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

        # Sleep for background logging
        $this->waitBackground();

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);
        assertEquals($this->groupid, $log['group']['id']);

        # Can also see as ourselves.
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

        $ctx = NULL;
        $logs = [ $this->uid => [ 'id' => $this->uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$this->uid]['logs']);
        assertEquals($this->groupid, $log['group']['id']);

        }

    public function testDelete() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $ret = $this->call('user', 'DELETE', [
            'id' => $uid
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);

        $ret = $this->call('user', 'DELETE', [
            'id' => $uid
        ]);
        assertEquals(0, $ret['ret']);

        }

    public function testSupportSearch() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MEMBER));

        # Search across all groups.
        $ret = $this->call('user', 'GET', [
            'search' => 'test@test'
        ]);
        $this->log("Search returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['users']));
        assertEquals($this->uid, $ret['users'][0]['id']);

        # Test that a mod can't see stuff
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        assertEquals(1, $this->user->removeMembership($this->groupid));

        # Search across all groups.
        $ret = $this->call('user', 'GET', [
            'search' => 'tes2t@test.com'
        ]);
        $this->log("Should fail " . var_export($ret, TRUE));
        assertEquals(2, $ret['ret']);

        }

    public function testMerge() {
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
        $u5 = User::get($this->dbhm, $this->dbhm);
        $id5 = $u5->create('Test', 'User', NULL);
        $u5->addEmail('test5@test.com', 0);
        $u5->addMembership($this->groupid);

        # Can't merge not a mod
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u1->login('testpw'));

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        assertEquals(4, $ret['ret']);

        # Invalid email.

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test22@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        assertEquals(3, $ret['ret']);

        # As mod should work
        assertGreaterThan(0, $u4->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u4->login('testpw'));

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        assertEquals(0, $ret['ret']);

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
        assertEquals(0, $ret['ret']);
        $id = $u1->findByEmail('test4@test.com');
        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertTrue($u->isModOrOwner($this->groupid));

    }

    public function testCantMerge() {
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
        $u3->setSetting('canmerge', FALSE);
        $u4 = User::get($this->dbhm, $this->dbhm);
        $id4 = $u4->create('Test', 'User', NULL);
        $u4->addMembership($this->groupid, User::ROLE_MODERATOR);
        $u4->addEmail('test4@test.com', 0);

        assertGreaterThan(0, $u4->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u4->login('testpw'));

        User::clearCache();

        $ret = $this->call('user', 'POST', [
            'action' => 'Merge',
            'email1' => 'test2@test.com',
            'email2' => 'test3@test.com',
            'reason' => 'UT'
        ]);
        assertNotEquals(0, $ret['ret']);
    }

    public function testUnbounce() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test3@test.com');
        $u->addMembership($this->groupid);
        $u->setPrivate('bouncing', 1);

        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        assertTrue($this->user->login('testpw'));

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
        assertNotNull($log);
    }

    public function testAddEmail() {
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        assertTrue($this->user->login('testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'AddEmail',
            'email' => 'test4@test.com'
        ]);

        assertEquals(0, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertEquals('test4@test.com', $u->getEmailPreferred());

        # Remove for another user - should fail.
        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'RemoveEmail',
            'email' => 'test2@test.com'
        ]);

        assertNotEquals(0, $ret['ret']);

        # Remove for ourselves, should work.
        $ret = $this->call('user', 'POST', [
            'id' => $uid,
            'action' => 'RemoveEmail',
            'email' => 'test4@test.com'
        ]);

        assertEquals(0, $ret['ret']);
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertNull($u->getEmailPreferred());
    }

    public function testRating() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $ret = $this->call('user', 'GET', [
            'info' => TRUE,
            'id' => $uid
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        assertTrue($this->user->login('testpw'));

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

        $ret = $this->call('user', 'POST', [
            'action' => 'Rate',
            'ratee' => $uid,
            'rating' => User::RATING_DOWN
        ]);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(1, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Rating should not be visible to someone else  there has been no interaction.
        assertTrue($u2->login('testpw'));
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
        $cid = $cr->createConversation($this->user->getId(), $uid);
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
        $ids = $this->dbhr->preQuery("SELECT id FROM messages LIMIT 1");
        assertEquals(1, count($ids));
        $cm->create($cid, $uid, "test", ChatMessage::TYPE_DEFAULT, $ids[0]['id']);

        $u->ratingVisibility();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        self::assertEquals(0, $ret['user']['info']['ratings'][User::RATING_UP]);
        self::assertEquals(1, $ret['user']['info']['ratings'][User::RATING_DOWN]);

        # Unrate
        assertTrue($this->user->login('testpw'));
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
        assertEquals(1, $this->user->addMembership($this->groupid));
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($this->user->login('testpw'));

        # Trigger a notification check - should mark this as active.
        $ret = $this->call('notification', 'GET', [
            'count' => TRUE
        ]);
        $this->waitBackground();

        self::assertEquals(1, count($this->user->getActive()));

        $active = $this->user->mostActive($this->groupid);
        self::assertEquals($this->user->getId(), $active[0]['id']);

        # Retrieve that info as a mod.
        $u = User::get($this->dbhr, $this->dbhm);
        $mod = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test2@test.com');
        assertEquals(1, $u->addMembership($this->groupid, User::ROLE_MODERATOR));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

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
        assertEquals(0, $ret['ret']);
    }
}


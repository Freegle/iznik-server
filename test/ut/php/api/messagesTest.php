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
class messagesTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->user = $u;
    }

    public function tearDown()
    {
    }

    public function testApproved() {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: OFFER: sofa (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Index
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::APPROVED);
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('source', Message::PLATFORM);

        # Should be able to see this message even logged out, as this is a Freegle group.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid
        ]);
        $this->log("Get when logged out with permission" . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # And using the multiple groupid option
        $ret = $this->call('messages', 'GET', [
            'groupids' => [ $this->gid ]
        ]);
        $this->log("Get when logged out using groupids " . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Now join and check we can see see it.
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        $rc = $u->addMembership($this->gid, User::ROLE_MEMBER);
        assertEquals(1, $rc);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Omit groupid - should use groups for currently logged in user.
        $ret = $this->call('messages', 'GET', [
            'grouptype' => Group::GROUP_FREEGLE,
            'modtools' => FALSE
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        #$this->log(var_export($msgs, TRUE));
        assertEquals(1, count($msgs));

        # Test search by word
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmess',
            'groupid' => $this->gid,
            'search' => 'sofa'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Test search by id
        $this->log("Test by id");
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmess',
            'groupid' => $this->gid,
            'search' => $a->getID()
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);

        # Test search by member
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmemb',
            'groupid' => $this->gid,
            'search' => 'test@test.com'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Search by member on current groups
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'searchmemb',
            'search' => 'test@test.com'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertFalse(array_key_exists('source', $msgs[0])); # Only a member, shouldn't see mod att

        # Check the log.
        $u->setRole(User::ROLE_MODERATOR, $this->gid);

        # Get messages for our logged in groups.
        $ret = $this->call('messages', 'GET', [
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertTrue(array_key_exists('source', $msgs[0]));

        # Get messages for this specific user
        $ret = $this->call('messages', 'GET', [
            'fromuser' => $a->getFromuser()
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);

        # Get messages for another user
        $ret = $this->call('messages', 'GET', [
            'fromuser' => $id
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Filter by type
        $ret = $this->call('messages', 'GET', [
            'types' => [ Message::TYPE_OFFER ]
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));

        $ret = $this->call('messages', 'GET', [
            'types' => [ Message::TYPE_OTHER ]
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # A bad type.  This will be the same as if we didn't supply any; the key thing is the SQL injection defence.
        $ret = $this->call('messages', 'GET', [
            'types' => [ 'wibble' ]
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));

        # Sleep for background logging
        $this->waitBackground();

        $this->log("Fromuser is " . $a->getFromuser());

        $ctx = NULL;
        $logs = [ $a->getFromuser() => [ 'id' => $a->getFromuser() ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_RECEIVED, $logs[$a->getFromuser()]['logs']);
        $this->log("Got log " . var_export($log, TRUE));
        assertEquals($this->gid, $log['group']['id']);
        assertEquals($a->getFromuser(), $log['user']['id']);
        assertEquals($a->getID(), $log['message']['id']);

        $id = $a->getID();
        $this->log("Delete it");
        $a->delete();

        # Actually delete the message to force a codepath.
        $this->log("Delete msg $id");
        $rc = $this->dbhm->preExec("DELETE FROM messages WHERE id = ?;", [ $id ]);
        assertEquals(1, $rc);
        $this->waitBackground();

        # The delete should show in the log.
        $ctx = NULL;
        $logs = [ $a->getFromuser() => [ 'id' => $a->getFromuser() ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_RECEIVED, $logs[$a->getFromuser()]['logs']);
        assertEquals($this->gid, $log['group']['id']);
        assertEquals($a->getFromuser(), $log['user']['id']);
        assertEquals(1, $log['message']['deleted']);

        }

    public function testSpam() {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Spam msgid $id");
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::SPAM);
        $a = new Message($this->dbhr, $this->dbhm, $id);

        # Shouldn't be able to see spam
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Now join - shouldn't be able to see a spam message
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        $u->addMembership($this->gid);

        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Promote to owner - should be able to see it.
        $u->setRole(User::ROLE_OWNER, $this->gid);
        assertEquals(User::ROLE_OWNER, $u->getRoleForGroup($this->gid));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Spam',
            'start' => '2100-01-01T06:00:00Z'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        $this->log(var_export($msgs, true));
        assertTrue(array_key_exists('source', $msgs[0])); # An owner, should see mod att

        $a->delete();

        }

    public function testError() {
        $ret = $this->call('messages', 'GET', [
            'groupid' => 0,
            'collection' => 'wibble'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['messages']));

        }

    public function testPending() {
        # Create a group with a message on it
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $a = new Message($this->dbhr, $this->dbhm, $id);

        # Shouldn't be able to see pending logged out.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        self::assertEquals(1, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Shouldn't be able to see pending logged in but not a member.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);

        $this->log("Shouldn't see pending " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Now join - shouldn't be able to see a pending message
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $id);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Promote to mod - should be able to see it.
        $this->log("Check as mod for " . $a->getID());
        $u->setRole(User::ROLE_MODERATOR, $this->gid);
        assertEquals(User::ROLE_MODERATOR, $u->getRoleForGroup($this->gid));
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($a->getID(), $msgs[0]['id']);
        assertEquals($this->gid, $msgs[0]['groups'][0]['groupid']);
        assertTrue(array_key_exists('source', $msgs[0])); # A mod, should see mod att

        $a->delete();

    }

    public function testNear() {
        # Need a location and polygon for near testing.
        $this->group->setPrivate('lng', 179.15);
        $this->group->setPrivate('lat', 8.4);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('onhere', 1);

        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: basic test (Place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Index
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.4);
        $m->setPrivate('lng', 179.15);
        $m->addToSpatialIndex();

        # Ensure we have consent to see this message
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());
        $sender->setPrivate('publishconsent', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'nearlocation' => $lid
        ]);
        $this->log("Get near " . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);
    }

    public function testSearchInBounds() {
        # Need a location and polygon for near testing.
        $this->group->setPrivate('lng', 179.15);
        $this->group->setPrivate('lat', 8.4);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('onhere', 1);
        $this->group->setPrivate('onmap', 1);
        $this->group->setPrivate('publish', 1);

        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: basic test (Place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Index
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        # Put the message in the bounding box.
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('lng', 179.15);
        $a->setPrivate('lat', 8.35);
        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());

        # Ensure we have consent to see the message.
        $sender->setPrivate('publishconsent', 1);

        # Look for it - should find it.
        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'swlng' => 179.11,
            'swlat' => 8.31,
            'nelng' => 179.3,
            'nelat' => 8.4
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'swlng' => 179.11,
            'swlat' => 8.31,
            'nelng' => 179.3,
            'nelat' => 8.4,
            'messagetype' => Message::TYPE_OFFER
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'swlng' => 179.11,
            'swlat' => 8.31,
            'nelng' => 179.3,
            'nelat' => 8.4,
            'groupid' => $this->group->getId()
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'groupids' => [ $this->group->getId() ]
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'swlng' => 179.11,
            'swlat' => 8.31,
            'nelng' => 179.3,
            'nelat' => 8.4,
            'groupid' => $this->group->getId() + 1
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'swlng' => 179.11,
            'swlat' => 8.31,
            'nelng' => 179.3,
            'nelat' => 8.4,
            'messagetype' => Message::TYPE_WANTED
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Close but not cigar.
        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'swlng' => 179.16,
            'swlat' => 8.31,
            'nelng' => 179.3,
            'nelat' => 8.4
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));
    }

    public function testSearchActiveInGroups() {
        # Need a location and polygon for near testing.
        $this->group->setPrivate('lng', 179.15);
        $this->group->setPrivate('lat', 8.4);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('onhere', 1);
        $this->group->setPrivate('onmap', 1);
        $this->group->setPrivate('publish', 1);

        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: basic test (Place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Index
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        # Put the message in the bounding box.
        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('lng', 179.15);
        $a->setPrivate('lat', 8.35);
        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());

        # Ensure we have consent to see the message.
        $sender->setPrivate('publishconsent', 1);

        # Log in as a user on the group.
        assertTrue($this->user->login('testpw'));

        # Look for it - should find it.
        $ret = $this->call('messages', 'GET', [
            'search' => 'basic t',
            'subaction' => 'searchmess',
            'searchmygroups' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);
    }

    public function testPendingWithdraw() {
        # Set up a pending message on a native group.
        assertTrue($this->user->login('testpw'));

        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'from@test.com', 'testgroup@groups.ilovefreegle.org', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->log("From " . $m->getFromuser() . "," . $m->getFromaddr());
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_WITHDRAWN
        ]);

        assertEquals(0, $ret['ret']);
        self::assertEquals(TRUE, $ret['deleted']);

        }

    public function testAttachment() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $email = 'ut-' . rand() . '@test.com';
        $u->addEmail($email, 0, FALSE);

        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_UNMODERATED);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace("test@test.com", $email, $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $id = $r->received(Message::EMAIL, $email, 'testgroup@' . GROUP_DOMAIN, $msg, $this->gid);

        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::APPROVED,
            'summary' => TRUE,
            'groupid' => $this->gid
        ]);

        assertEquals(1, count($ret['messages'][0]['attachments']));
    }

    public function testInBounds() {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Ensure we have consent to see this message
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 179.15);
        $m->setPrivate('lng', 8.4);

        $ret = $this->call('messages', 'GET', [
            'subaction' => 'inbounds',
            'swlat' => 179,
            'nelat' => 180,
            'swlng' => 8,
            'nelng' => 9
        ]);

        # Nothing indexed yet.
        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Index
        $m = new Message($this->dbhr, $this->dbhm);
        $m->updateSpatialIndex();

        $ret = $this->call('messages', 'GET', [
            'subaction' => 'inbounds',
            'swlat' => 179,
            'nelat' => 180,
            'swlng' => 8,
            'nelng' => 9
        ]);
        $msgs = $ret['messages'];

        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

    }

    public function testMyGroups() {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $this->log("Approved id $id");

        # Ensure we have consent to see this message
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 179.15);
        $m->setPrivate('lng', 8.4);

        # Nothing indexed yet.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'mygroups'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Index
        $m = new Message($this->dbhr, $this->dbhm);
        $m->updateSpatialIndex();

        # No groups
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'mygroups'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Logged in but no groups
        assertTrue($this->user->login('testpw'));
        $this->user->removeMembership($this->gid);

        $ret = $this->call('messages', 'GET', [
            'subaction' => 'mygroups'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Add membership
        $this->user->addMembership($this->gid);

        $ret = $this->call('messages', 'GET', [
            'subaction' => 'mygroups'
        ]);
        $msgs = $ret['messages'];

        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);
    }

    public function testUnprettyPoly() {
        $ret = $this->call('messages', 'GET', [
            'swlat' => 51.331168891035944,
            'swlng' =>-0.2466740747070162,
            'nelat' => 51.331168891035944,
            'nelng' =>-0.11617000000001099,
            'moodtools' => FALSE,
            'subaction' => 'inbounds'
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('messages', 'GET', [
            'swlat' => 51.331168891035944,
            'swlng' =>-0.2466740747070162,
            'nelat' => 51.341168891035944,
            'nelng' =>-0.2466740747070162,
            'moodtools' => FALSE,
            'subaction' => 'inbounds'
        ]);

        assertEquals(0, $ret['ret']);
    }

//    public function testEH()
//        $u = new User($this->dbhr, $this->dbhm);
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//
//        $u = new User($this->dbhr, $this->dbhm);
//
//        $uid = $u->findByEmail('edward@ehibbert.org.uk');
//        $u = new User($this->dbhr, $this->dbhm, $uid);
//        $_SESSION['id'] = $uid;
//        $ret = $this->call('messages', 'GET', [
//            'collection' => MessageCollection::ALLUSER,
//            'summary' => TRUE,
//            'types' => [
//                Message::TYPE_OFFER,
//                Message::TYPE_WANTED
//            ],
//            'fromuser' => $uid,
//        ]);
//
//        assertEquals(0, $ret['ret']);
//        error_log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//        error_log(var_export($ret['context'], TRUE));
//        error_log("Got " . count($ret['messages']) . " messages");
//    }
}


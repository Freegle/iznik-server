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
class messageAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $dbhm->preExec("DELETE FROM messages_drafts WHERE (SELECT fromuser FROM messages WHERE id = msgid) IS NULL;");
        $dbhm->preExec("DELETE FROM worrywords WHERE keyword LIKE 'UTtest%';");
        $dbhm->preExec("DELETE FROM messages WHERE messageid LIKE 'GTUBE1.1010101@example.net';");

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->gid);
        $this->user = $u;
    }

    protected function tearDown() : void
    {
        $this->dbhm->preExec("DELETE FROM partners_keys WHERE partner = 'UT';");
        parent::tearDown();
    }

    public function testLoggedOut() {
        # Create a group with a message on it
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('sourceheader', Message::PLATFORM);

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        $this->log("Message returned when logged out " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);
        $this->assertFalse(array_key_exists('fromuser', $ret['message']));

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved',
            'summary' => TRUE
        ]);
        $this->log("Summary message returned when logged out " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);
        $this->assertFalse(array_key_exists('fromuser', $ret['message']));
    }

    public function testApproved()
    {
        # Create a group with a message on it
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('sourceheader', Message::PLATFORM);

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);
        $this->assertFalse(array_key_exists('fromuser', $ret['message']));

        # When logged in should be able to see message history.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertTrue($u->addMembership($this->gid));
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);
        $this->assertFalse(array_key_exists('emails', $ret['message']['fromuser']));

        # Test we can get the message history.
        $ret = $this->call('messages', 'GET', [
            'id' => $id,
            'collection' => 'Approved',
            'summary' => FALSE,
            'grouptype' => Group::GROUP_FREEGLE,
            'modtools' => TRUE,
            'groupid' => $this->gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['messages'][0]['id']);
        $this->assertEquals(1, count($ret['messages'][0]['fromuser']['messagehistory']));

        $atts = $a->getPublic();
        $this->assertEquals(1, count($atts['fromuser']['messagehistory']));
        $this->assertEquals($id, $atts['fromuser']['messagehistory'][0]['id']);
        $this->assertEquals('Other', $atts['fromuser']['messagehistory'][0]['type']);
        $this->assertEquals('Basic test', $atts['fromuser']['messagehistory'][0]['subject']);

        $a->delete();
        $this->group->delete();

        }

    public function testBadColl()
    {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Shouldn't be able to see a bad collection
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'BadColl'
        ]);
        $this->assertEquals(101, $ret['ret']);

        }

    public function testPending()
    {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);

        # Shouldn't be able to see pending logged out
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Pending'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Now join - shouldn't be able to see a pending message as user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to mod - should be able to see it.
        $u->setRole(User::ROLE_MODERATOR, $this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);

        # Pending message should show in notification.
        list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route) = $u->getNotificationPayload(TRUE);
        $this->assertEquals("1 pending message\n", $title);

        $a->delete();
    }

    public function testSpam()
    {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Created spam message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals($id, $a->getID());
        $this->assertTrue(array_key_exists('subject', $a->getPublic()));

        # Shouldn't be able to see spam logged out
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Now join - shouldn't be able to see a spam message as user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to see it.
        $u->setRole(User::ROLE_OWNER, $this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);

        # Delete it - as a user should fail
        $u->setRole(User::ROLE_MEMBER, $this->gid);
        $ret = $this->call('message', 'DELETE', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Approved'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $u->setRole(User::ROLE_OWNER, $this->gid);
        $ret = $this->call('message', 'DELETE', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Try again to see it - should be gone
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(3, $ret['ret']);

        }

    public function testSpamToApproved()
    {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->assertFalse($failok);
        $this->assertNotNull($id);
        $this->log("Created spam message $id");
        $rc = $r->route();
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals($id, $a->getID());
        $this->assertTrue(array_key_exists('subject', $a->getPublic()));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid, User::ROLE_OWNER);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);

        # Approve the message.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Try again to see it - should be gone from spam into approved
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->log("Should be in approved " . var_export($ret['message']['groups'], TRUE));
        $this->assertEquals('Approved', $ret['message']['groups'][0]['collection']);

        # Now send it again - should fail as duplicate message id.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id2, $failok) = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->assertNull($id2);
        $this->assertTrue($failok);
    }

    public function testSpamNoLongerMember()
    {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Created spam message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals($id, $a->getID());
        $this->assertTrue(array_key_exists('subject', $a->getPublic()));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid, User::ROLE_OWNER);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);

        # Remove member from group.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $fromuid = $m->getFromuser();
        $fromu = new User($this->dbhr, $this->dbhm, $fromuid);
        $fromu->removeMembership($this->gid);

        # Mark as not spam.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Try again to see it - should be gone from pending into approved
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MessageCollection::APPROVED, $ret['message']['groups'][0]['collection']);
    }

    public function testApprove()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        # Send from a user at our domain, so that we can cover the reply going back to them
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $this->user->addEmail($email);

       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Suppress mails.
        $m = $this->getMockBuilder('Freegle\Iznik\Message')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('sendOne'))
            ->getMock();
        $m->method('sendOne')->willReturn(false);

        # Shouldn't be able to approve logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to approve as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve',
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to approve it.  Suppress the mail.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Specific');
        $c->setPrivate('ccrejectaddr', 'test@test.com');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve',
            'duplicate' => 1,
            'subject' => 'Test',
            'body' => 'Test',
            'stdmsgid' => $sid
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Sleep for background logging
        $this->waitBackground();

        # Get the logs - should reference the stdmsg.
        $ctx = NULL;
        $logs = [ $uid => [ 'id' => $uid ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_APPROVED, $logs[$uid]['logs']);
        $this->assertEquals($sid, $log['stdmsgid']);

        $groups = $u->getModGroupsByActivity();
        $this->assertEquals('testgroup', $groups[0]['namedisplay']);

        $s->delete();
        $c->delete();

        # Message should now exist but approved.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $groups = $m->getGroups();
        $this->assertEquals($this->gid, $groups[0]);
        $p = $m->getPublic();
        $this->log("After approval " . var_export($p, TRUE));
        $this->assertEquals('Approved', $p['groups'][0]['collection']);
        $this->assertEquals($uid, $p['groups'][0]['approvedby']['id']);

        # Should be gone, but will return success.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve',
            'duplicate' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testReject()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Subject: Basic test', 'Subject: OFFER: thing (place)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Suppress mails.
        $m = $this->getMockBuilder('Freegle\Iznik\Message')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('sendOne'))
            ->getMock();
        $m->method('sendOne')->willReturn(false);

        $this->assertEquals(Message::TYPE_OFFER, $m->getType());
        $senduser = $m->getFromUser();

        # Set to platform for testing message visibility.
        $m->setPrivate('sourceheader', Message::PLATFORM);

        # Shouldn't be able to reject logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to reject as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject',
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create another mod.
        $othermod = User::get($this->dbhr, $this->dbhm);
        $othermoduid = $othermod->create(NULL, NULL, 'Test User');
        $othermod->addMembership($this->gid, User::ROLE_MODERATOR);

        # Promote to owner - should be able to reject it.  Suppress the mail.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Me');
        $c->setPrivate('fromname', 'Groupname Moderator');
        $c->setPrivate('chatread', 1);

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'stdmsgid' => $sid,
            'action' => 'Reject',
            'subject' => 'Test reject',
            'body' => 'Test body',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Other mod should not see this as unread, since we're set to mark as read.
        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $cr->createUser2Mod($senduser, $this->gid);
        $this->assertEquals(0, $cr->unseenCountForUser($othermoduid));

        $s->delete();
        $c->delete();

        # User should have modmails.
        $this->waitBackground();
        $u->updateModMails($senduser);
        $ctx = NULL;
        $logs = [ $senduser => [ 'id' => $senduser ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_REJECTED, $logs[$senduser]['logs']);
        $this->assertNotNull($log);

        $ret = $this->call('user', 'GET', [
            'id' => $senduser,
            'logs' => TRUE,
            'modmailsonly' => TRUE
        ]);
        $this->assertEquals(1, $ret['user']['modmails']);

        # The message should exist as rejected.  Should be able to see logged out
        $this->log("Can see logged out");
        $_SESSION['id'] = NULL;
        $ret = $this->call('message', 'GET', [
            'id' => $m->getId()
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now log in as the sender.
        $uid = $m->getFromuser();
        $this->log("Found sender as $uid");
        $u = User::get($this->dbhm, $this->dbhm, $uid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $this->log("Message $id should now be rejected");
        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::REJECTED,
            'groupid' => $this->gid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['messages']));
        $this->assertEquals($id, $ret['messages'][0]['id']);
        $this->log("Indeed it is");

        # We should have a chat message.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $chatid = $ret['chatrooms'][0]['id'];

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $chatid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatmessages']));
        $chatmsgid = $ret['chatmessages'][0]['id'];

        # Test the last seen
        $ret = $this->call('chatrooms', 'GET', [
            'id' => $chatid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($chatmsgid, $ret['chatroom']['lastmsgseen']);

        # And it should be flagged as mailed.
        $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
        $roster = $r->getRoster();
        $found = FALSE;

        foreach ($roster as $rost) {
            if ($rost['userid'] == $uid) {
                $found = TRUE;
                $this->assertEquals($chatmsgid, $rost['lastmsgemailed']);
            }
        }
        $this->assertEquals(TRUE, $found);

        # Try to convert it back to a draft.
        $this->log("Back to draft");
//        $this->dbhm->errorLog = TRUE;
//        $this->dbhr->errorLog = TRUE;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'RejectToDraft'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->waitBackground();
        $ctx = NULL;
        $logs = [ $senduser => [ 'id' => $senduser ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_REPOST, $logs[$uid]['logs']);
        $this->assertNotNull($log);

        # Check it's a draft.  Have to be logged in to see that.
        $this->log("Check draft");
        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::DRAFT
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['messages']));
        $this->assertEquals($id, $ret['messages'][0]['id']);

        # Coverage of rollback case.
        $this->log("Rollback");
        $m2 = new Message($this->dbhr, $this->dbhm);
        $this->assertFalse($m2->backToDraft());

        # Should be gone from the messages we can see as a mod, but will return success.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject',
            'duplicate' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testReply()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Suppress mails.
        $m = $this->getMockBuilder('Freegle\Iznik\Message')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('sendOne'))
            ->getMock();
        $m->method('sendOne')->willReturn(false);
        $senduser = $m->getFromUser();

        $this->assertEquals(Message::TYPE_OTHER, $m->getType());

        # Shouldn't be able to mail logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reply'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to mail as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reply',
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create another mod.
        $othermod = User::get($this->dbhr, $this->dbhm);
        $othermoduid = $othermod->create(NULL, NULL, 'Test User');
        $othermod->addMembership($this->gid, User::ROLE_MODERATOR);

        # Promote to owner - should be able to reply.  Suppress the mail.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Specifc');
        $c->setPrivate('ccrejectaddr', 'test@test.com');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s->setPrivate('action', 'Leave Approved Message');
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reply',
            'stdmsgid' => $sid,
            'subject' => 'Test reply',
            'body' => 'Test body',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Other mod should see this as unread, since we're not set to mark as read.
        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $cr->createUser2Mod($senduser, $this->gid);
        $this->assertEquals(1, $cr->unseenCountForUser($othermoduid));

        $s->delete();
        $c->delete();
    }

    public function testDelete()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Shouldn't be able to delete logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to delete as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete',
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to delete it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Should be gone but will return success.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject',
            'duplicate' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Route and delete approved.
        $this->log("Route and delete approved");
        $msg = $this->unique($msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete'
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testNotSpam()
    {
        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $origmsg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # Shouldn't be able to do this logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to do this as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam',
            'dup' => 2
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to do this it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Message should now be in pending.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        $msgs = $ret['messages'];
        $this->assertEquals(1, count($msgs));
        $this->assertEquals($id, $msgs[0]['id']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Spam'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Pending should be empty.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $msgs = $ret['messages'];
        $this->assertEquals(0, count($msgs));

        # Again as admin
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);

        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $origmsg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Spam'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Pending should be empty.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::PENDING
        ]);
        $msgs = $ret['messages'];
        $this->assertEquals(0, count($msgs));

        }

    public function testHold()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Shouldn't be able to hold logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Hold'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to hold as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Hold',
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to hold it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Hold',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid
        ]);
        $this->assertEquals($uid, $ret['message']['heldby']['id']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Release',
            'duplicate' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid
        ]);
        $this->assertFalse(Utils::pres('heldby', $ret['message']));

        }

    public function testEditAsMod()
    {
        # Create an attachment
        $cwd = getcwd();
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        file_put_contents("/tmp/chair.jpg", $data);
        chdir($cwd);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/chair.jpg'
            ],
            'identify' => FALSE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $attid = $ret['id'];

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertFalse($m->isEdited());
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        # Shouldn't be able to edit logged out
        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit',
            'attachments' => []
        ]);

        $this->log(var_export($ret, true));
        $this->assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to edit as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to edit it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit long',
            'FOP' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals('Test edit long', $ret['message']['subject']);
        $this->assertEquals('Test edit long', $ret['message']['suggestedsubject']);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertTrue($m->isEdited());

        # Now edit a platform subject.
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('TV1 1AA');

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'msgtype' => 'Offer',
            'item' => 'Test item',
            'locationid' => $lid
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('OFFER: Test item (TV1)', $ret['message']['subject']);
        $this->assertEquals($ret['message']['subject'], $ret['message']['suggestedsubject']);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'msgtype' => 'Offer',
            'item' => 'Test item',
            'locationid' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Attachments - twice for old atts code path.
        for ($i = 0; $i < 2; $i++) {
            $ret = $this->call('message', 'PATCH', [
                'id' => $id,
                'groupid' => $this->gid,
                'textbody' => 'Test edit',
                'attachments' => [ $attid ]
            ]);
            $this->assertEquals(0, $ret['ret']);

            $ret = $this->call('message', 'GET', [
                'id' => $id
            ]);
            $this->assertEquals('Test edit', $ret['message']['textbody']);
            $this->log("After text edit " . var_export($ret, TRUE));
        }

        # Check edit history
        $this->assertEquals('Test edit long', $ret['message']['edits'][1]['oldsubject']);
        $this->assertEquals('OFFER: Test item (TV1)', $ret['message']['edits'][1]['newsubject']);
        $this->assertEquals(Message::TYPE_OTHER, $ret['message']['edits'][1]['oldtype']);
        $this->assertEquals(Message::TYPE_OFFER, $ret['message']['edits'][1]['newtype']);
        $this->assertEquals('Hey.', $ret['message']['edits'][0]['oldtext']);
        $this->assertEquals('Test edit', $ret['message']['edits'][0]['newtext']);
    }

    /**
     * @dataProvider editProvider
     */
    public function testEditAsMember($anonymous, $revert)
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid2 = $l->create(NULL, 'TV1 1AB', 'Postcode', 'POINT(179.3 8.6)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        $u2id = $u->create('Test','User', 'Test User');

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $modid = $u->create('Test','User', 'Test User');
        $mod = User::get($this->dbhr, $this->dbhm, $modid);
        $this->assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $mod->addMembership($this->gid, User::ROLE_MODERATOR);

        $this->log("Created member $memberid and mod $modid");

        # Submit a message from the member, who will be moderated as new members are.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Test the canedit flag
        $this->assertTrue($member->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);
        $this->assertEquals(TRUE, $ret['message']['canedit']);
        $this->assertEquals(MessageCollection::PENDING, $ret['message']['groups'][0]['collection']);

        # Disable edits for moderated members.
        $settings = json_decode($this->group->getPrivate('settings'), TRUE);
        $settings['allowedits'] = [
            'moderated' => FALSE,
            'group' => TRUE
        ] ;
        $this->group->setPrivate('settings', json_encode($settings));

        # Shouldn't be allowed to edit now.
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);
        $this->assertEquals(FALSE, $ret['message']['canedit']);

        # Should be allowed to edit as mod
        $this->assertTrue($mod->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);
        $this->assertEquals(TRUE, $ret['message']['canedit']);

        # Now approve
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'groupid' => $this->gid,
            'action' => 'Approve'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now log in as the member.  Should show in our count.
        $m = new Message($this->dbhr, $this->dbhm);
        $m->updateSpatialIndex();
        $this->assertTrue($member->login('testpw'));
        $ret = $this->call('session', 'GET', [
            'components' => [ 'openposts' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['me']['openposts']);

        # Edit the message twice.
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'location' => 'TV1 1AB'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'textbody' => 'Another text body',
            'location' => 'TV1 1AB'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Under the covers the message lat/lng should have changed.
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->assertEquals(179.3, $m->getPrivate('lng'));
        $this->assertEquals(8.6, $m->getPrivate('lat'));

        # Again but with no actual change
//        $ret = $this->call('message', 'PATCH', [
//            'id' => $mid,
//            'groupid' => $this->gid,
//            'item' => 'Edited',
//            'textbody' => 'Another text body'
//        ]);
//        $this->assertEquals(0, $ret['ret']);

        # Test the available numbers.
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'availableinitially' => 10,
            'availablenow' => 9,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $this->assertEquals(10, $ret['message']['availableinitially']);
        $this->assertEquals(9, $ret['message']['availablenow']);

        # Now test the taken/received by function.  Restore the counts.
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'availableinitially' => 10,
            'availablenow' => 10,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'AddBy',
            'userid' => $anonymous ? NULL : $u1id,
            'count' => 4,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $this->assertEquals(10, $ret['message']['availableinitially']);
        $this->assertEquals(6, $ret['message']['availablenow']);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'AddBy',
            'userid' => $anonymous ? NULL : $u2id,
            'count' => 7,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $this->assertEquals(10, $ret['message']['availableinitially']);
        $this->assertEquals($anonymous ? 3 : 0, Utils::presdef('availablenow', $ret['message'], 0));

        # Now back as the mod and check the edit history.
        $this->assertTrue($mod->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'RemoveBy',
            'userid' => $anonymous ? NULL : $u2id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $this->assertEquals(10, $ret['message']['availableinitially']);
        $this->assertEquals($anonymous ? 10 : 6, $ret['message']['availablenow']);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'AddBy',
            'userid' => $anonymous ? NULL : $u1id,
            'count' => 7,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $this->assertEquals(10, $ret['message']['availableinitially']);
        $this->assertEquals(3, $ret['message']['availablenow']);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'RemoveBy',
            'userid' => $anonymous ? NULL : $u1id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        $this->assertEquals(10, $ret['message']['availableinitially']);
        $this->assertEquals(10, $ret['message']['availablenow']);

        # Check edit history.  Edit should show as needing approval.
        $this->assertEquals(2, count($ret['message']['edits']));
        $this->assertEquals('Text body', $ret['message']['edits'][0]['oldtext']);
        $this->assertEquals('Another text body', $ret['message']['edits'][0]['newtext']);
        $this->assertEquals(TRUE, $ret['message']['edits'][0]['reviewrequired']);
        $this->assertNull($ret['message']['edits'][0]['oldsubject']);
        $this->assertNull($ret['message']['edits'][0]['newsubject']);
        $this->assertNull($ret['message']['edits'][1]['oldtext']);
        $this->assertNull($ret['message']['edits'][1]['newtext']);
        $this->assertEquals(TRUE, $ret['message']['edits'][1]['reviewrequired']);
        $this->assertEquals('OFFER: a thing (TV1)', $ret['message']['edits'][1]['oldsubject']);
        $this->assertEquals('OFFER: Edited (TV1)', $ret['message']['edits'][1]['newsubject']);

        # This message should also show up in edits.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::EDITS
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['messages']));
        $this->assertEquals($mid, $ret['messages'][0]['id']);
        $this->assertEquals($this->gid, $ret['messages'][0]['groups'][0]['groupid']);

        # Will have a collection of approved, because it is.
        $this->assertEquals(MessageCollection::APPROVED, $ret['messages'][0]['groups'][0]['collection']);

        # And will show the edit.
        $this->assertEquals(2, count($ret['messages'][0]['edits']));
        $editid = $ret['messages'][0]['edits'][0]['id'];

        # And also in the counts.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->gid, $ret['groups'][0]['id']);
        $this->assertEquals(1, $ret['groups'][0]['work']['editreview']);
        $this->assertEquals(1, $ret['work']['editreview']);

        if ($revert) {
            # Revert all edits.
            $ret = $this->call('message', 'POST', [
                'id' => $mid,
                'action' => 'RevertEdits'
            ]);
            $this->assertEquals(0, $ret['ret']);

            # Should be back as it was.
            $ret = $this->call('message', 'GET', [
                'id' => $mid
            ]);

            $this->assertEquals('OFFER: a thing (TV1)', $ret['message']['subject']);
            $this->assertEquals('Text body', $ret['message']['textbody']);
        } else {
            # Approve the edits.
            $ret = $this->call('message', 'POST', [
                'id' => $mid,
                'action' => 'ApproveEdits'
            ]);
            $this->assertEquals(0, $ret['ret']);

            # Should have applied..
            $ret = $this->call('message', 'GET', [
                'id' => $mid
            ]);

            $this->assertEquals('OFFER: Edited (TV1)', $ret['message']['subject']);
            $this->assertEquals('Another text body', $ret['message']['textbody']);
        }

        # Not showing for review.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::EDITS
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['messages']));

    }

    public function editProvider() {
        return [
            [ TRUE, TRUE ],
            [ FALSE, TRUE ],
            [ TRUE, FALSE ],
            [ FALSE, FALSE ],
        ];
    }

    public function testEditAsMemberPending()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $this->log("Created member $memberid");

        # Submit a message from the member, who will be moderated as new members are.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Now log in as the member and edit the message in Pending.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'textbody' => 'Another text body'
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testEditAsMemberDeleted()
    {
        // This can happen with TrashNothing, when a post is rejected and then edited by the member.
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $this->log("Created member $memberid");

        # Submit a message from the member, who will be moderated as new members are.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Reject as mod.
        $othermod = User::get($this->dbhr, $this->dbhm);
        $othermoduid = $othermod->create(NULL, NULL, 'Test User');
        $othermod->addMembership($this->gid, User::ROLE_MODERATOR);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Me');
        $c->setPrivate('fromname', 'Groupname Moderator');
        $c->setPrivate('chatread', 1);

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'groupid' => $this->gid,
            'stdmsgid' => $sid,
            'action' => 'Reject',
            'subject' => 'Test reject',
            'body' => 'Test body',
            'duplicate' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->assertEquals(MessageCollection::REJECTED, $m->getGroups(FALSE, FALSE)[0]['collection']);

        # Now log in as the member and edit the message.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'textbody' => 'Another text body'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(MessageCollection::PENDING, $m->getGroups(FALSE, FALSE)[0]['collection']);
    }

    public function testEditGroupModerated() {
        // Set group moderated.
        $this->group->setSettings([ 'moderated' => TRUE ]);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $this->log("Created member $memberid");

        # Submit a message from the member, who will be moderated as new members are.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Approve the message.
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->approve($this->gid);

        # Now log in as the member and edit the message.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'textbody' => 'Another text body'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Message should still be in approved.
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->assertTrue($m->isApproved($this->gid));
    }

    public function testDraft()
    {
        # Can create drafts when not logged in.
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        file_put_contents("/tmp/chair.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/chair.jpg'
            ],
            'identify' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $attid = $ret['id'];

        $locid = $this->dbhr->preQuery("SELECT id FROM locations ORDER BY id LIMIT 1;")[0]['id'];

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'attachments' => [ $attid ]
        ]);
        $this->log("Draft PUT " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # And again to exercise codepath
        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'groupid' => $this->gid,
            'attachments' => [ $attid ]
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['id']);

        # Delete the draft to test the case where the draft is completed from another session.
        $this->dbhm->preExec("DELETE FROM messages_drafts WHERE msgid = ?;", [ $id ]);

        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'groupid' => $this->gid,
            'attachments' => [ $attid ],
            'dup' => 1
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotEquals($id, $ret['id']);
        $id = $ret['id'];

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $msg = $ret['message'];
        $this->assertEquals('Offer', $msg['type']);
        $this->assertEquals('a thing', $msg['subject']);
        $this->assertEquals('Text body', $msg['textbody']);
        $this->assertEquals($attid, $msg['attachments'][0]['id']);

        # Tick off a coverage case.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $userlist = [];
        $locationlist = [];
        $this->assertEquals($attid, $m->getPublic(TRUE, TRUE, FALSE, $userlist, $locationlist, TRUE)['attachments'][0]['id']);

        # Now create a new attachment and update the draft.
        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/chair.jpg',
                'dedup' => 1
            ],
            'identify' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $attid2 = $ret['id'];

        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Wanted',
            'item' => 'a thing2',
            'locationid' => $locid,
            'textbody' => 'Text body2',
            'attachments' => [ $attid2 ],
            'dup' => 2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['id']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $msg = $ret['message'];
        $this->assertEquals('Wanted', $msg['type']);
        $this->assertEquals('a thing2', $msg['subject']);
        $this->assertEquals('Text body2', $msg['textbody']);
        $this->assertEquals($attid2, $msg['attachments'][0]['id']);

        $this->log("Get back in draft");
        $ret = $this->call('messages', 'GET', [
            'collection' => 'Draft'
        ]);
        $this->log("Messages " . var_export($ret, TRUE));
        $found = FALSE;
        foreach ($ret['messages'] as $message) {
            $this->log("Compare {$message['id']} to $id");
            if ($message['id'] == $id) {
                $found = TRUE;
            }
        }
        $this->assertTrue($found);

        # Now remove the attachment
        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Wanted',
            'locationid' => $locid,
            'item' => 'a thing2',
            'textbody' => 'Text body2',
            'dup' => 3
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['id']);

        $ret = $this->call('messages', 'GET', [
            'collection' => 'Draft'
        ]);
        $this->log("Messages " . var_export($ret, TRUE));
        $this->assertEquals($id, $ret['messages'][0]['id']);
        $this->assertEquals(0, count($ret['messages'][0]['attachments']));

        }

    public function testSubmitNative() {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be pending " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

        # Now approve the message and wait for it to reach the group.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($this->gid, NULL, NULL, NULL);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->assertEquals(0, count($msgs));

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::APPROVED);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

    }

    public function testSubmitBanned()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        # Find a location
        # ...but ban them first.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail($email);

        if (!$uid) {
            $uid = $u->create("Test", "User", "Test User");
            $u->addEmail($email);
        }

        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->log("Ban $uid from {$this->gid}");
        $u->removeMembership($this->gid, TRUE);
        $this->assertFalse($u->addMembership($this->gid));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should not be pending " . var_export($ret, TRUE));
        $this->assertEquals(9, $ret['ret']);
        $this->assertEquals('Banned from this group', $ret['status']);
        $this->assertNull($u->isApprovedMember($this->gid));

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        $this->assertEquals(0, count($msgs));
    }

    public function testCrosspost() {
        # At the moment a crosspost results in two separate messages - see comment in Message::save().
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $group1= $this->group->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $this->group->create('testgroup2', Group::GROUP_REUSE);

        $this->user->addMembership($group1);
        $this->user->addMembership($group2);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id1, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $msg = str_ireplace('testgroup1', 'testgroup2', $msg);
       list ($id2, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $this->assertNotEquals($m1->getMessageID(), $m2->getMessageID());
        $m1->delete("UT delete");
        $m2->delete("UT delete");
    }
    
    public function testPromise() {
        $u = $this->user;
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $uid2 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $uid3 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: A thing (A place)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Not yet promised in spatial index.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->updateSpatialIndex();

        $spatials = $this->dbhr->preQuery("SELECT * FROM messages_spatial WHERE msgid = ?", [
            $id
        ]);

        $this->assertEquals(1, count($spatials));
        $this->assertEquals(0, $spatials[0]['successful']);
        $this->assertEquals(0, $spatials[0]['promised']);

        # Shouldn't be able to promise logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $this->uid,
            'action' => 'Promise'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Promise it to the other user.
        $u = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Promise'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Should show in spatial index.
        $m->updateSpatialIndex();
        $spatials = $this->dbhr->preQuery("SELECT * FROM messages_spatial WHERE msgid = ?", [
            $id
        ]);

        $this->assertEquals(1, count($spatials));
        $this->assertEquals(0, $spatials[0]['successful']);
        $this->assertEquals(1, $spatials[0]['promised']);

        # Promise should show
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->log("Got message " . var_export($ret, TRUE));
        $this->assertEquals(1, count($ret['message']['promises']));
        $this->assertEquals($uid2, $ret['message']['promises'][0]['userid']);

        # Promised to me flag shouldn't show, because it isn't.
        $this->assertFalse(array_key_exists('promisedtome', $ret['message']));

        # But should to that user.
        $u2 = User::get($this->dbhr, $this->dbhm, $uid2);
        $this->assertTrue($u2->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue($ret['message']['promisedtome']);

        $this->assertTrue($u->login('testpw'));

        # Can promise to multiple users
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Promise'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['message']['promises']));
        $this->assertEquals($uid3, $ret['message']['promises'][0]['userid']);
        $this->assertEquals($uid2, $ret['message']['promises'][1]['userid']);
        
        # Renege on one of them.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Renege'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['message']['promises']));
        $this->assertEquals($uid3, $ret['message']['promises'][0]['userid']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid2,
            'info' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['user']['info']['reneged']);

        # Still promised.
        $m->updateSpatialIndex();
        $spatials = $this->dbhr->preQuery("SELECT * FROM messages_spatial WHERE msgid = ?", [
            $id
        ]);

        $this->assertEquals(1, count($spatials));
        $this->assertEquals(0, $spatials[0]['successful']);
        $this->assertEquals(1, $spatials[0]['promised']);

        # Check we can't promise on someone else's message.
        $u = User::get($this->dbhr, $this->dbhm, $uid3);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Promise'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Renege'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Renege on the other.
        $u = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Renege',
            'dup' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # No longer promised.
        $m->updateSpatialIndex();
        $spatials = $this->dbhr->preQuery("SELECT * FROM messages_spatial WHERE msgid = ?", [
            $id
        ]);

        $this->assertEquals(1, count($spatials));
        $this->assertEquals(0, $spatials[0]['successful']);
        $this->assertEquals(0, $spatials[0]['promised']);
    }

    public function testPromisePartner() {
        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'test.com']);
        $this->assertNotNull($id);

        $u = $this->user;
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $uid2 = $u->create(NULL, NULL, 'Test User');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: A thing (A place)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Promise it to the other user.
        global $sessionPrepared;
        $sessionPrepared = FALSE;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Promise',
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Renege
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Renege',
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Promise it without a user id.
        global $sessionPrepared;
        $sessionPrepared = FALSE;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Promise',
            'partner' => $key
        ]);

        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Renege',
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testMark()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Message::TYPE_OFFER, $m->getType());

        # Get it into the spatial index.
        $m->setPrivate('lat', 50.0657);
        $m->setPrivate('lng', -5.7132);
        $m->addToSpatialIndex();
        $m->deleteFromSpatialIndex();
        $m->addToSpatialIndex();

        # Should show in our open post count.
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('session', 'GET', [
            'components' => [ 'openposts' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['me']['openposts']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_TAKEN,
            'message' => 'Message for others'
        ]);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'happiness' => User::FINE,
            'comment' => "I'm happy",
            'userid' => $uid
        ]);
        $this->assertEquals(0, $ret['ret']);

        # For coverage.
        $m->addToSpatialIndex();

        # Should no longer show in our open post count.
        $ret = $this->call('session', 'GET', [
            'components' => [ 'openposts' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['me']['openposts']);

        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Message::TYPE_WANTED, $m->getType());

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_RECEIVED,
            'happiness' => User::FINE,
            'userid' => $uid
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now withdraw it.  Will be ignored as we have an outcome already.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_WITHDRAWN,
            'happiness' => User::FINE,
            'comment' => "It was fine",
            'userid' => $uid
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now get the happiness back.  Should be 1 because we have one comment.
        $u->setRole(User::ROLE_MODERATOR, $this->gid);
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('memberships', 'GET', [
            'collection' => 'Happiness',
            'groupid' => $this->gid
        ]);
        $this->log("Happiness " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(1, count($ret['members']));

        $m->delete("UT delete");

    }

    public function testMarkAsMod()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Create a member on the group and check we can can't mark.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid, User::ROLE_MEMBER);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN
        ]);
        $this->assertEquals(2, $ret['ret']);

        $u->addMembership($this->gid, User::ROLE_MODERATOR);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'dup' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);
        $this->assertEquals(1, count($ret['message']['outcomes']));
    }

    public function testExpired()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Force it to expire.  First ensure that it's not expired just because it's old.
        $expired = date("Y-m-d H:i:s", strtotime("midnight 91 days ago"));
        $m->setPrivate('arrival', $expired);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);

        $this->assertEquals(0, count($ret['message']['outcomes']));

        # Now expire it on the group.
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = '$expired' WHERE msgid = $id;");

        # ...but add a recent chat referencing it.
        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        list ($cid, $banned) = $cr->createConversation($uid, $uid2);
        list ($cmid, $banned) = $cm->create($cid, $uid2, 'Please', ChatMessage::TYPE_INTERESTED, $id);

        # Shouldn't have expired yet.
        self::assertEquals(0, count($ret['message']['outcomes']));

        # Should now have expired.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);

        # Delete the chat message - should now look like it's expired.
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $cmid);
        $cm->delete();

        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);

        self::assertEquals(Message::OUTCOME_EXPIRED, $ret['message']['outcomes'][0]['outcome']);
    }

    public function testIntendedTaken()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_TAKEN
        ]);

        # Too soon.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, $m->processIntendedOutcomes($id));

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        $this->assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        $this->assertEquals(Message::OUTCOME_TAKEN, $atts['outcomes'][0]['outcome']);

        $m->delete("UT delete");

        }

    public function testIntendedPromised()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_TAKEN
        ]);

        # Make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);

        # Promise it.
        $m->promise($uid);
        $this->assertEquals(0, $m->processIntendedOutcomes($id));
    }

    public function testIntendedReceived()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_RECEIVED
        ]);

        # Too soon.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, $m->processIntendedOutcomes($id));

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        $this->assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        $this->assertEquals(Message::OUTCOME_RECEIVED, $atts['outcomes'][0]['outcome']);

        $m->delete("UT delete");

        }

    public function testIntendedWithdrawn()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_WITHDRAWN
        ]);

        # Too soon.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, $m->processIntendedOutcomes($id));

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        $this->assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        $this->assertEquals(Message::OUTCOME_WITHDRAWN, $atts['outcomes'][0]['outcome']);

        $m->delete("UT delete");

        }

    public function testIntendedRepost()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_REPOST
        ]);

        $groups = $m->getGroups(FALSE, FALSE);
        $arrival = strtotime($groups[0]['arrival']);

        # Too soon.
        $m = new Message($this->dbhm, $this->dbhm, $id);
        $this->assertEquals(0, $m->processIntendedOutcomes($id));
        sleep(5);

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = DATE_SUB(arrival, INTERVAL 20 DAY) WHERE msgid = ?;", [ $id ]);
        $this->assertEquals(1, $this->dbhm->rowsAffected());
        $this->assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        $this->assertEquals(0, count($atts['outcomes']));

        # The arrival time should have been bumped.
        $groups = $m->getGroups(FALSE, FALSE);
        $arrival2 = strtotime($groups[0]['arrival']);
        $this->assertGreaterThan($arrival, $arrival2);

        # Now try again; shouldn't repost as recently done.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_REPOST,
            'dup' => 2
        ]);

        self::assertEquals(0, $ret['ret']);

        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        $this->assertEquals(1, $this->dbhm->rowsAffected());
        $this->assertEquals(0, $m->processIntendedOutcomes($id));

        $this->waitBackground();
        $ctx = NULL;
        $logs = [ $uid => [ 'id' => $uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, TRUE, TRUE);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_REPOST, $logs[$uid]['logs']);
        $this->assertNotNull($log);

        $m->delete("UT delete");
    }

    public function testChatSource()
    {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);

        # Put a message on the group.
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Create a chat reply by email.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: a test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($replyid, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Try logged out
        $this->log("Logged out");
        $ret = $this->call('message', 'GET', [
            'id' => $replyid,
            'collection' => 'Chat'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Try to get not as a mod.
        $this->log("Logged in");
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $replyid,
            'collection' => 'Chat'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Try as mod
        $this->log("As mod");
        $u->addMembership($this->gid, User::ROLE_MODERATOR);
        $ret = $this->call('message', 'GET', [
            'id' => $replyid,
            'collection' => 'Chat'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($replyid, $ret['message']['id']);
    }

    public function testNativeSpam() {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body with viagra'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be spam " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);
    }

    public function testNativeWorry() {
        $email = 'test-' . rand() . '@blackhole.io';

        $this->dbhm->preExec("INSERT INTO worrywords (keyword, type) VALUES (?, ?);", [
            'UTtest1',
            WorryWords::TYPE_REPORTABLE
        ]);

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body with uttest2'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id is worrying " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);
        self::assertTrue(array_key_exists('worry', $msgs[0]));
    }

    public function testLikes() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Love'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        $this->assertEquals(1, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Unlove'
        ]);

        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Laugh'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(1, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Unlaugh'
        ]);

        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(1, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View',
            'dup' => 1
        ]);

        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(1, $m->getLikes(Message::LIKE_VIEW));

        # Can pass View logged out and get back success.
        $_SESSION['id'] = NULL;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View',
            'dup' => 3
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();
        $this->assertTrue($u->login('testpw'));

        # Check this shows up in our list of viewed messages.
        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::VIEWED,
            'fromuser' => $u->getId()
        ]);

        $this->assertEquals(1, count($ret['messages']));
        $this->assertEquals($id, $ret['messages'][0]['id']);

        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View',
            'dup' => 2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        $this->assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        $this->assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        $this->assertEquals(2, $m->getLikes(Message::LIKE_VIEW));
    }

    public function testBigSwitch()
    {
        $this->assertTrue(TRUE);

        $this->group->setPrivate('overridemoderation', Group::OVERRIDE_MODERATION_ALL);

        $email = 'test-' . rand() . '@blackhole.io';
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail($email);
        $u->addMembership($this->gid);

        # Take off moderation.
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent; will get queued, as we don't have a membership for the group
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Success', $ret['status']);

        # Should be pending because of the big switch.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertTrue($m->isPending($this->gid));
    }

    public function testCantPost()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        # Forbid us from posting.
        $member->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_PROHIBITED);

        # Submit a message from the member - should fail
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertNotEquals(0, $ret['ret']);
    }

    public function testMergeDuringSubmit() {
        $this->group = Group::get($this->dbhm, $this->dbhm);
        $this->gid = $this->group->create('testgroup1', Group::GROUP_REUSE);

        $u = User::get($this->dbhm, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhm, $this->dbhm, $id1);
        $this->assertGreaterThan(0, $u1->addEmail('test1@test.com'));

        $l = new Location($this->dbhm, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => 'test2@test.com'
        ]);

        $msgs = $this->dbhr->preQuery("SELECT * FROM messages WHERE id = $mid;");

        $m = new Message($this->dbhm, $this->dbhm, $mid);
        $id2 = $m->getFromuser();
        $this->assertNotNull($id2);
        $this->assertNotEquals($id1, $id2);

        $this->assertEquals(0, $ret['ret']);

        $u->merge($id1, $id2, "UT Test");

        $m = new Message($this->dbhm, $this->dbhm, $mid);
        $id3 = $m->getFromuser();
        $this->assertNotNull($id3);
    }

    public function testPartner() {
        global $sessionPrepared;
        $sessionPrepared = FALSE;

        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'test.com']);
        $this->assertNotNull($id);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Thing (Place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'subject' => 'OFFER: Thing (Another place)',
            'textbody' => 'Test edit',
            'lat' => 56.1,
            'lng' => 1.23,
            'partner' => $key
        ]);

        $this->assertEquals(0, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'partner' => $key
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test edit', $ret['message']['textbody']);
        $this->assertEquals('OFFER: Thing (Another place)', $ret['message']['subject']);
        $this->assertEquals(56.1, $ret['message']['lat']);
        $this->assertEquals(1.2365, $ret['message']['lng']);
        $this->assertEquals('Hey.', $ret['message']['edits'][0]['oldtext']);
        $this->assertEquals('OFFER: Thing (Place)', $ret['message']['edits'][0]['oldsubject']);
        $this->assertEquals('OFFER: Thing (Another place)', $ret['message']['edits'][0]['newsubject']);
    }

    public function testPartnerConsent() {
        global $sessionPrepared;
        $sessionPrepared = FALSE;

        $key = Utils::randstr(64);
        $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'test2.com']);
        $partnerid = $this->dbhm->lastInsertId();
        $this->assertNotNull($partnerid);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        $u2id = $u->create('Test','User', 'Test User');

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);
        $member->addEmail('test@test.com');

        $modid = $u->create('Test','User', 'Test User');
        $mod = User::get($this->dbhr, $this->dbhm, $modid);
        $this->assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $mod->addMembership($this->gid, User::ROLE_MODERATOR);

        $this->log("Created member $memberid and mod $modid");

        # Submit a message from the member, who will be moderated as new members are.
        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Not given consent.
        $_SESSION['id'] = NULL;
        $_SESSION['partner'] = NULL;
        $GLOBALS['sessionPrepared'] = FALSE;
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'subject' => 'Test edit',
            'partner' => $key
        ]);
        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid,
            'partner' => $key
        ]);

        # Lat/lng  blurred.
        $this->assertEquals(8.5298, $ret['message']['lat']);
        $this->assertEquals(179.2161, $ret['message']['lng']);
        $this->assertFalse(array_key_exists('location', $ret['message']));
        $this->assertEquals(1, count($ret['message']['fromuser']['emails']));
        $this->assertEquals($email, $ret['message']['fromuser']['emails'][0]['email']);

        # Give consent
        $this->assertTrue($member->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'PartnerConsent',
            'partner' => 'UT'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Still shouldn't have write access.
        $_SESSION['id'] = NULL;
        $_SESSION['partner'] = NULL;
        $GLOBALS['sessionPrepared'] = FALSE;
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'subject' => 'Test edit',
            'partner' => $key
        ]);
        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $mid,
            'partner' => $key
        ]);

        # Lat/lng not blurred.
        $this->assertEquals(8.53333, $ret['message']['lat']);
        $this->assertEquals(179.2167, $ret['message']['lng']);
        $this->assertEquals('TV1 1AA', $ret['message']['location']['name']);
    }

    public function testMove() {
        $group1 = $this->group->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $this->group->create('testgroup2', Group::GROUP_REUSE);

        $this->assertTrue($this->user->addMembership($group1));
        $this->assertTrue($this->user->addMembership($group2));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $this->assertTrue($this->user->login('testpw'));

        # Move as member - fail.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Move',
            'groupid' => $group2
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->addMembership($group1, User::ROLE_MODERATOR);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Move',
            'groupid' => $group2,
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Move as mod.
        $this->user->addMembership($group2, User::ROLE_MODERATOR);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Move',
            'groupid' => $group2,
            'dup' => 3
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals($group2, $ret['message']['groups'][0]['groupid']);
    }

    public function testTidyOutcomes() {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'comment' => 'Thanks, this has now been taken.'
        ]);

        // Should have been stripped on input.
        $atts = $m->getPublic();
        $this->assertNull($atts['outcomes'][0]['comments']);

        // Fudge it back in.
        $this->dbhm->preExec("INSERT INTO messages_outcomes (msgid, outcome, comments) VALUES (?, ?, ?);", [
            $id,
            Message::OUTCOME_TAKEN,
            'Thanks, this has now been taken.'
        ]);

        $atts = $m->getPublic();
        $this->assertEquals('Thanks, this has now been taken.', $atts['outcomes'][0]['comments']);

        // Now tidy the outcomes, which should zap that text.
        $m->tidyOutcomes(date("Y-m-d", strtotime("24 hours ago")));
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $atts = $m->getPublic();
        $this->assertNull($atts['outcomes'][0]['comments']);
    }

    public function testPromiseError() {
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => 0,
            'userid' => $this->uid,
            'action' => 'Promise'
        ]);
        $this->assertEquals(10, $ret['ret']);
    }

    public function testJoinPostTwoEmails() {
        $email1 = 'test-' . rand() . '@blackhole.io';
        $email2 = 'test-' . rand() . '@blackhole.io';

        # Create a user with email1
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addEmail($email1);

        # Create a user who is already using email2.
        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create('Test', 'User', 'Test User');
        $u->addEmail($email2);

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # Submit the post but with an email of another user.  This will work - deliberately.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email2,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be pending " . var_export($ret, TRUE));
        $this->assertEquals(6, $ret['ret']);
    }

    public function testJoinPostLoggedInNoEmail() {
        $email1 = 'test-' . rand() . '@blackhole.io';

        # Create a user with email1
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addEmail($email1);

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # Submit the post.  Should work as we are logged in.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be pending " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
    }

    public function testRepost() {
        # Create a group with a message on it
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(MessageCollection::APPROVED, $m->repost());

        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        $this->assertEquals(MessageCollection::PENDING, $m->repost());
    }


    public function testTnPostId() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: OFFER: sofa (Place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('22 Aug 2015', '22 Aug 2035', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('tnpostid', -1);

        $ret = $this->call('message', 'GET', [
            'tnpostid' => -1
        ]);

        $this->assertEquals($id, $ret['message']['id']);
    }

    public function testWorryWords() {
        $email1 = 'test-' . rand() . '@blackhole.io';

        # Create a user with email1
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->addEmail($email1);

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        # Set up worry words.
        $settings = json_decode($this->group->getPrivate('settings'), TRUE);
        $settings['spammers'] = [ 'worrywords' => 'wibble,wobble,£' ];
        $this->group->setSettings($settings);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'wibble'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # Submit the post - should go to spam.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email1,
            'ignoregroupoverride' => true
        ]);

        $this->assertEquals(0, $ret['ret']);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(MessageCollection::PENDING, $m->getPublic()['groups'][0]['collection']);
        $this->assertEquals(Spam::REASON_WORRY_WORD, $m->getPrivate('spamtype'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'I want £3 for the pair'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # Submit the post - should go to spam.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email1,
            'ignoregroupoverride' => true
        ]);

        $this->assertEquals(0, $ret['ret']);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(MessageCollection::PENDING, $m->getPublic()['groups'][0]['collection']);
        $this->assertEquals(Spam::REASON_WORRY_WORD, $m->getPrivate('spamtype'));
    }

    /**
     * @dataProvider markProvider
     */
    public function testMarkPartner($type, $outcome)
    {
        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'blackhole.io']);
        $this->assertNotNull($id);

        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $email = 'test-' . rand() . '@blackhole.io';
        $u->addEmail($email);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', "$type: A thing (A place)", $msg);
        $msg = str_ireplace('test@test.com', $email, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Mark as taken.
        global $sessionPrepared;
        $sessionPrepared = FALSE;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => $outcome,
            'partner' => $key
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['message']['outcomes']));
        $this->assertEquals($outcome, $ret['message']['outcomes'][0]['outcome']);
    }

    public function markProvider() {
        return [
            [
                Message::TYPE_OFFER, Message::OUTCOME_TAKEN
            ],
            [
                Message::TYPE_OFFER, Message::OUTCOME_WITHDRAWN
            ],
            [
                Message::TYPE_WANTED, Message::OUTCOME_RECEIVED
            ],
            [
                Message::TYPE_WANTED, Message::OUTCOME_WITHDRAWN
            ],
        ];
    }

    public function testPromiseMultipleTimes() {
        $u = $this->user;
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $uid2 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $uid3 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body',
            'availablenow' => 2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => 'test@test.com'
        ]);

        # Correct to 1 available.
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'availablenow' => 1,
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Promise it to the other user, twice
        $u = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'userid' => $uid2,
            'action' => 'Promise'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'userid' => $uid2,
            'action' => 'Promise',
            'dup' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now say the other person has taken 1, twice.
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'AddBy',
            'userid' => $uid2,
            'count' => 1,
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now none available.
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->assertEquals(0, $m->getPrivate('availablenow'));

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'AddBy',
            'userid' => $uid2,
            'count' => 1,
            'dup' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Should still be none available.
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->assertEquals(0, $m->getPrivate('availablenow'));
    }

    public function testSubmitUnvalidatedEmail()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create a user with a validated and an unvalidated email
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->addEmail($email);
        $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw');
        $this->assertTrue($u->login('testpw'));

        $email2 = 'test-' . rand() . '@blackhole.io';
        $ret = $this->call('session', 'PATCH', [
            'email' => $email2,
        ]);
        $this->assertEquals(10, $ret['ret']);

        // Now submit using the unvalidated email.
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email2,
            'ignoregroupoverride' => true
        ]);

        $this->assertEquals(11, $ret['ret']);
    }

    public function testMessageToOthersOnTaken() {
        # Create a message.
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid2 = $l->create(NULL, 'TV1 1AB', 'Postcode', 'POINT(179.3 8.6)');

        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $modid = $u->create('Test','User', 'Test User');
        $mod = User::get($this->dbhr, $this->dbhm, $modid);
        $this->assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $mod->addMembership($this->gid, User::ROLE_MODERATOR);

        $this->log("Created member $memberid and mod $modid");

        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Reply to it from two other users.
        $u1id = $u->create('Test','User', 'Test User');
        $u2id = $u->create('Test','User', 'Test User');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid1, $blocked) = $r->createConversation($u1id, $memberid);
        list ($rid2, $blocked) = $r->createConversation($u2id, $memberid);

        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $mid1 = $cm->create($rid1, $u1id, 'Test', ChatMessage::TYPE_INTERESTED, $mid);
        $mid2 = $cm->create($rid2, $u2id, 'Test', ChatMessage::TYPE_INTERESTED, $mid);

        # Now mark as TAKEN.
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'happiness' => User::FINE,
            'comment' => "I'm happy",
            'userid' => $u1id,
            'message' => "Message for others"
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        # We should now have a completed message for u2.
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid2);
        list ($msgs, $users) = $r->getMessages();
        $this->assertEquals(2, count($msgs));
        $this->assertEquals(ChatMessage::TYPE_INTERESTED, $msgs[0]['type']);
        $this->assertEquals(ChatMessage::TYPE_COMPLETED, $msgs[1]['type']);
        $this->assertEquals("Message for others", $msgs[1]['message']);
    }

    public function testSubmitLoggedInDifferentEmail() {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)');

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);
        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        $this->assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $modid = $u->create('Test','User', 'Test User');
        $mod = User::get($this->dbhr, $this->dbhm, $modid);
        $this->assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $mod->addMembership($this->gid, User::ROLE_MODERATOR);

        $this->log("Created member $memberid and mod $modid");

        $this->assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        // Submit from a different email than the one we're logged in as.
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email . "2"
        ]);

        $this->assertEquals(12, $ret['ret']);
    }

    public function testAttachmentOrder()
    {
        # Can create drafts when not logged in.
        $attids = [];

        for ($i = 0; $i < 3; $i++) {
            $attname = ['chair', 'pan', 'Tile'];
            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/' . $attname[$i] . '.jpg');
            file_put_contents("/tmp/chair.jpg", $data);

            $ret = $this->call('image', 'POST', [
                'photo' => [
                    'tmp_name' => '/tmp/chair.jpg'
                ],
                'identify' => FALSE
            ]);

            $this->assertEquals(0, $ret['ret']);
            $this->assertNotNull($ret['id']);
            $attids[] = $ret['id'];
        }

        $locid = $this->dbhr->preQuery("SELECT id FROM locations ORDER BY id LIMIT 1;")[0]['id'];

        # Reorder the attachments so that the first one created is not the first one passed.  This should test
        # the primary attachment ordering.
        $attid = array_shift($attids);
        $attids[] = $attid;

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'attachments' => $attids
        ]);
        $this->log("Draft PUT " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Check attachment order.
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $msg = $ret['message'];
        $this->assertEquals('Offer', $msg['type']);
        $this->assertEquals('a thing', $msg['subject']);
        $this->assertEquals('Text body', $msg['textbody']);
        $this->assertEquals(3, count($msg['attachments']));
        $this->assertEquals($attids[0], $msg['attachments'][0]['id']);

        # Now reorder again.
        $attid = array_shift($attids);
        $attids[] = $attid;

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit',
            'attachments' => $attids
        ]);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $msg = $ret['message'];
        $this->assertEquals('Offer', $msg['type']);
        $this->assertEquals('a thing', $msg['subject']);
        $this->assertEquals('Text body', $msg['textbody']);
        $this->assertEquals($attids[0], $msg['attachments'][0]['id']);
    }

    public function testBackToPending()
    {
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $u = new User($this->dbhr, $this->dbhm);
        $u->create('Test', 'User', 'Test User');
        $this->assertNotNull($u->addEmail($email));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Create a message at this location on this group.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid, User::ROLE_MODERATOR);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'BackToPending',
        ]);

        $this->assertEquals(0, $ret['ret']);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(MessageCollection::PENDING, $m->getGroups(FALSE, FALSE)[0]['collection']);
    }

    public function testMarkUnpromised() {
        $u = $this->user;
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $uid2 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $uid3 = $u->create(NULL, NULL, 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: A thing (A place)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $this->assertGreaterThan(0, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Set up some interest from two users.  No need to use the API as we're not testing chat function here.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid2, $banned) = $r->createConversation($uid2, $this->uid);
        $this->assertGreaterThan(0, $rid2);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $mid2 = $cm->create($rid2, $uid2, 'Test', ChatMessage::TYPE_INTERESTED, $id);
        list ($rid3, $banned) = $r->createConversation($uid3, $this->uid);
        $this->assertGreaterThan(0, $rid3);
        $mid3 = $cm->create($rid3, $uid3, 'Test', ChatMessage::TYPE_INTERESTED, $id);

        # Promise it and renege.
        $u = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Promise'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Renege'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Now mark as taken.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'message' => 'Message for others'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->waitBackground();

        # Should be interested, promised, reneged, taken.
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid2);
        list ($msgs, $users) = $r->getMessages();
        $this->assertEquals(4, count($msgs));
        $this->assertEquals(ChatMessage::TYPE_INTERESTED, $msgs[0]['type']);
        $this->assertEquals(ChatMessage::TYPE_PROMISED, $msgs[1]['type']);
        $this->assertEquals(ChatMessage::TYPE_RENEGED, $msgs[2]['type']);
        $this->assertEquals(ChatMessage::TYPE_COMPLETED, $msgs[3]['type']);
        $this->assertEquals(NULL, $msgs[3]['message']);

        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid3);
        list ($msgs, $users) = $r->getMessages();

        # Should be interested, message to others, taken.
        $this->assertEquals(2, count($msgs));
        $this->assertEquals(ChatMessage::TYPE_INTERESTED, $msgs[0]['type']);
        $this->assertEquals(ChatMessage::TYPE_COMPLETED, $msgs[1]['type']);
        $this->assertEquals('Message for others', $msgs[1]['message']);
    }
}
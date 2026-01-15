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
class chatMessagesAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");

        list($this->user, $this->uid) = $this->createTestUserWithLogin('Test User', 'testpw');
        list($this->user2, $this->uid2) = $this->createTestUserWithLogin('Test User', 'testpw');
        list($this->user3, $this->uid3) = $this->createTestUserWithLogin('Test User', 'testpw');

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);

        # Recipient must be a member of at least one group
        $this->user2->addMembership($this->groupid);

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $this->cid = $c->createGroupChat('test', $this->groupid);

        $this->dbhm->preExec("DELETE FROM spam_whitelist_links WHERE domain LIKE '%google.co';");
        $this->dbhm->preExec("DELETE FROM spam_whitelist_links WHERE domain LIKE '%microsoft.co%';");
    }

    protected function tearDown(): void
    {
    }

    public function testGroupGet()
    {
        # Logged out - no rooms
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatmessages', $ret));

        $m = new ChatMessage($this->dbhr, $this->dbhm);;
        list ($mid, $banned) = $m->create($this->cid, $this->uid, 'Test');
        $this->log("Created chat message $mid");

        # Just because it exists, doesn't mean we should be able to see it.
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatmessages', $ret));

        $this->addLoginAndLogin($this->user, 'testpw');

        # Still not, even logged in.
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        $this->assertEquals(2, $ret['ret']);
        $this->assertFalse(Utils::pres('chatmessages', $ret));

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        # Now we're talking.
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        $this->log("Now we're talking " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatmessages']));
        $this->assertEquals($mid, $ret['chatmessages'][0]['id']);
        $this->assertEquals($this->cid, $ret['chatmessages'][0]['chatid']);
        $this->assertEquals('Test', $ret['chatmessages'][0]['message']);

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($mid, $ret['chatmessage']['id']);

        }

    public function testGroupPut()
    {
        # Logged out - no rooms
        $this->log("Logged out");
        $ret = $this->call('chatmessages', 'POST', [ 'roomid' => $this->cid, 'message' => 'Test' ]);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('chatmessages', $ret));

        $this->assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        $this->addLoginAndLogin($this->user, 'testpw');

        # Now we're talking.  Make sure we're on the roster.
        $this->log("Logged in");
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $this->cid,
            'lastmsgseen' => 1
        ]);

        $this->log("Post test");
        $ret = $this->call('chatmessages', 'POST', [ 'roomid' => $this->cid, 'message' => 'Test2' ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid = $ret['id'];

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($mid, $ret['chatmessage']['id']);

        # Test search
        $ret = $this->call('chatrooms', 'GET', [
            'search' => 'zzzz',
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatrooms']));

        $ret = $this->call('chatrooms', 'GET', [
            'search' => 'ES',
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ]
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Two rooms - one we've created, and the automatic mod chat.
        $this->assertEquals(2, count($ret['chatrooms']));
        $this->assertTrue($this->cid == $ret['chatrooms'][0]['id'] || $this->cid == $ret['chatrooms'][1]['id']);

        }

    public function testConversation() {
        $this->addLoginAndLogin($this->user, 'testpw');

        # We want to use a referenced message which is promised, to test suppressing of email notifications.
        list($testUser, $uid2) = $this->createTestUserWithLogin('Test User', 'testpw');

        $this->user2->addEmail('test@test.com');
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $this->user2->addMembership($this->groupid);
        $this->user2->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Subject: Basic test', 'Subject: OFFER: Test item (Edinburgh EH3)', $msg);
        list($r, $refmsgid, $failok, $rc) = $this->createTestMessage($msg, 'testgroup', 'from@test.com', 'to@test.com', $this->groupid, $this->user2->getId(), []);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # The message should not yet show that we have interacted.
        $ret = $this->call('message', 'GET', [
            'id' => $refmsgid
        ]);
        $this->assertNull($ret['message']['interacted']);

        # Promise to someone else.
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $m->promise($uid2);

        # Create a chat to the second user with a referenced message from the second user.
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        $ret = $this->call('chatrooms', 'GET', []);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($this->cid, $ret['chatrooms'][0]['id']);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test',
            'refmsgid' => $refmsgid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        # The message should now yet show that we have interacted.
        $ret = $this->call('message', 'GET', [
            'id' => $refmsgid
        ]);
        $this->assertEquals($this->cid, $ret['message']['interacted']);

        # Should be able to set the replyexpected flag
        $ret = $this->call('chatmessages', 'PATCH', [
            'roomid' => $this->cid,
            'id' => $mid1,
            'replyexpected' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Second user should now show that they are expected to reply.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $r->updateExpected();
        $info = $this->user->getInfo(0);
        $this->assertEquals(0, $info['expectedreply']);
        $info = $this->user2->getInfo(0);
        $this->assertEquals(1, $info['expectedreply']);

        # Duplicate
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test',
            'refmsgid' => $refmsgid,
            'dup' => TRUE
        ]);
        $this->log("Dup create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $this->assertEquals($mid1, $ret['id']);

        # Check that the email was not suppressed.
        $this->log("Check for suppress of $mid1 to {$this->uid2}");
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $this->cid
        ]);

        foreach ($ret['roster'] as $rost) {
            if ($rost['user']['id'] == $this->uid2) {
                self::assertNull($rost['lastmsgemailed']);
            }
        }

        # Now log in as the other user.
        $this->addLoginAndLogin($this->user2, 'testpw');

        # Should be able to see the room
        $ret = $this->call('chatrooms', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatrooms']));
        $this->assertEquals($this->cid, $ret['chatrooms'][0]['id']);

        # If we create a chat to the first user, should get the same chat
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->cid, $ret['id']);

        # Check no reply expected shows for sender.
        $this->waitBackground();
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $r->updateExpected();
        $replies = $this->user->getExpectedReplies([ $this->user->getId() ], ChatRoom::ACTIVELIM, -1);
        $this->assertEquals(0, $replies[0]['count']);

        # Reply expected should show for recipient.
        $replies = $this->user->getExpectedReplies([ $this->uid2 ], ChatRoom::ACTIVELIM, -1);
        $this->assertEquals(1, $replies[0]['count']);
        $replies = $this->user->listExpectedReplies($this->uid2, ChatRoom::ACTIVELIM, -1);
        $this->assertEquals(1, count($replies));
        $this->assertEquals($this->cid, $replies[0]['id']);

        # Should see the messages
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        $this->log("Get message" . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($mid1, $ret['chatmessage']['id']);
        $this->assertEquals(1, $ret['chatmessage']['replyexpected']);

        # Should be able to post
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test',
            'dup' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);

        # We have now replied.
        $r->updateExpected();
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($mid1, $ret['chatmessage']['id']);
        $this->assertEquals(1, $ret['chatmessage']['replyreceived']);

        # Now log in as a third user
        $this->addLoginAndLogin($this->user3, 'testpw');

        # Shouldn't see the chat
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        $this->assertEquals(2, $ret['ret']);
        $this->assertFalse(Utils::pres('chatmessages', $ret));

        # Shouldn't see the messages
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        $this->assertEquals(2, $ret['ret']);
    }

    public function testImage() {
        $this->addLoginAndLogin($this->user, 'testpw');

        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        # Create a chat to the second user with a referenced image
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        file_put_contents("/tmp/pan.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/pan.jpg'
            ],
            'chatmessage' => 1,
            'imgtype' => Attachment::TYPE_CHAT_MESSAGE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $iid = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'imageid' => $iid
        ]);
        $this->log("Create image " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        # Now log in as the other user.
        $this->addLoginAndLogin($this->user2, 'testpw');

        # Should see the messages
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        $this->log("Get message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($mid1, $ret['chatmessage']['id']);
        $this->assertEquals($iid, $ret['chatmessage']['image']['id']);
        $this->assertEquals($mid1, $ret['chatmessage']['image']['chatmsgid']);
    }

    public function testLink() {
        $m = new ChatMessage($this->dbhr, $this->dbhm);;

        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("Hi ↵↵repetitionbowie.com/sportscapping.php?meant=mus2x216xkrn0mpb↵↵↵↵↵Thank you!", FALSE, NULL));
    }

    public function testReview() {
        $this->addLoginAndLogin($this->user, 'testpw');

        # Make the originating user be on the group so we can test groupfrom.
        $this->user->addMembership($this->groupid);

        # Add some mods on the recipient's group, so they can be notified.
        list($mod, $modid) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MODERATOR, 'Test User', 'test@test.com', 'testpw');

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://microsoft.com and email test@test.com',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://google.com'
        ]);
        $this->log("Create message with link" . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test without link'
        ]);
        $this->log("Create message with no link " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid3 = $ret['id'];

        # Process the messages to trigger review logic
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        $cm->process();

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        $cm->process();

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid3);
        $cm->process();

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_SPAM, $cm->getPrivate('reportreason'));

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_SPAM, $cm->getPrivate('reportreason'));

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid3);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_LAST, $cm->getPrivate('reportreason'));

        # Now log in as the other user.
        $this->addLoginAndLogin($this->user2, 'testpw');

        # Shouldn't see chat as no messages not held for review.
        $ret = $this->call('chatrooms', 'GET', []);
        $this->log("Shouldn't see rooms " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals(0, count($ret['chatrooms']));

        # Shouldn't see messages as held for review.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid
        ]);
        $this->log("Get message" . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatmessages']));

        # Now log in as a third user.
        $this->addLoginAndLogin($this->user3, 'testpw');
        $this->user3->addMembership($this->groupid, User::ROLE_MODERATOR);

        $this->user2->removeMembership($this->groupid);

        # user2 is not on any groups, so the messages should show up for review as we are a mod on the sender's group.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(3, $ret['work']['chatreview']);

        $this->user3->removeMembership($this->groupid);

        # Shouldn't see this as the sender is not on a group we mod.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['chatreview']);

        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->user3->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Should see this now - normal review case of being a mod on the recipient's group.
        $this->log("Check can see for mod on {$this->groupid}");
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(3, $ret['work']['chatreview']);

        # Test the 'other' variant.
        $this->user2->setGroupSettings($this->groupid, [ 'active' => 0 ]);
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['chatreviewother']);

        # Get the messages for review.
        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        error_log(var_export($ret['chatmessages'], TRUE));
        $this->log("Messages for review " . var_export($ret, TRUE));
        $this->assertEquals(3, count($ret['chatmessages']));
        $this->assertEquals($mid1, $ret['chatmessages'][0]['id']);
        $this->assertEquals(ChatMessage::TYPE_REPORTEDUSER, $ret['chatmessages'][0]['type']);
        $this->assertEquals(Spam::REASON_LINK, $ret['chatmessages'][0]['reviewreason']);
        $this->assertEquals($mid2, $ret['chatmessages'][1]['id']);
        $this->assertEquals(Spam::REASON_LINK, $ret['chatmessages'][1]['reviewreason']);
        $this->assertEquals($mid3, $ret['chatmessages'][2]['id']);
        $this->assertEquals(ChatMessage::REVIEW_LAST, $ret['chatmessages'][2]['reviewreason']);
        $this->assertEquals($this->groupid, $ret['chatmessages'][0]['group']['id']);
        $this->assertEquals($this->groupid, $ret['chatmessages'][0]['groupfrom']['id']);

        # Should be able to redact.
        $ret2 = $this->call('chatmessages', 'POST', [
            'id' => $mid1,
            'action' => 'Redact'
        ]);
        $this->assertEquals(0, $ret2['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        #$this->log("After hold " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertStringContainsString('https://www.microsoft', $ret['chatmessages'][0]['message']);
        $this->assertStringContainsString('(email removed)', $ret['chatmessages'][0]['message']);

        # Test hold/unhold.
        $this->log("Hold");
        $this->assertFalse(Utils::pres('held', $ret['chatmessages'][0]));
        $ret = $this->call('chatmessages', 'POST', [
            'id' => $mid1,
            'action' => 'Hold'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('chatmessages', 'GET', []);
        #$this->log("After hold " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals($this->user3->getId(), $ret['chatmessages'][0]['held']['id']);

        $ret = $this->call('chatmessages', 'POST', [
            'id' => $mid1,
            'action' => 'Release'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('held', $ret['chatmessages'][0]));

        # Approve the first
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Approve',
            'id' => $mid1
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['chatmessages']));
        $this->assertEquals($mid2, $ret['chatmessages'][0]['id']);

        # Reject the second
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Reject',
            'id' => $mid2
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatmessages']));

        # Approve the third
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Approve',
            'id' => $mid3
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatmessages'])*-9);

        # Now log in as the recipient.  Should see the approved ones.
        $this->addLoginAndLogin($this->user2, 'testpw');

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, count($ret['chatmessages']));
        $this->assertEquals($mid1, $ret['chatmessages'][0]['id']);
        $this->assertEquals($mid3, $ret['chatmessages'][1]['id']);
    }

    public function testReviewDup() {
        $this->addLoginAndLogin($this->user, 'testpw');

        # Make the originating user be on the group so we can test groupfrom.
        $this->user->addMembership($this->groupid);

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        # Create a chat to the third user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid3
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->cid2 = $ret['id'];
        $this->assertNotNull($this->cid2);

        # Create the same spam on each.
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://microsoft.co.uk ',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid2,
            'message' => 'Test with link http://microsoft.co.uk ',
            'refchatid' => $this->cid2
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        # Process the messages to trigger review logic
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        $cm->process();

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        $cm->process();

        # Now log in as a mod.
        $this->addLoginAndLogin($this->user3, 'testpw');
        $this->user3->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Should see this now.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(2, $ret['work']['chatreview']);

        # Reject the first
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Reject',
            'id' => $mid1
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Should have deleted the dup.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['chatreview']);
    }

    public function testReviewUnmod() {
        $this->user->setPrivate('chatmodstatus', User::CHAT_MODSTATUS_UNMODERATED);
        $this->addLoginAndLogin($this->user, 'testpw');

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://spam.wherever ',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://ham.wherever '
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        self::assertEquals(0, $cm->getPrivate('reviewrequired'));

        }

    public function testContext() {
        # Set up a conversation with lots of messages.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($this->uid, $this->uid2);

        for ($i = 0; $i < 10; $i++) {
            $cm = new ChatMessage($this->dbhr, $this->dbhm);
            list ($cid, $banned) = $cm->create($rid, $this->uid, "Test message $i");
            $this->log("Created chat message $cid in $rid");
        }

        $this->addLoginAndLogin($this->user, 'testpw');

        # Get all.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(10, count($ret['chatmessages']));

        # Get first lot.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid,
            'limit' => 5
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(5, count($ret['chatmessages']));

        for ($i = 5; $i < 10; $i++) {
            $this->assertEquals("Test message $i", $ret['chatmessages'][$i - 5]['message']);
        }

        $ctx = $ret['context'];

        # Get second lot.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid,
            'limit' => 5,
            'context' => $ctx
        ]);

        $this->log("Got second " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(5, count($ret['chatmessages']));

        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals("Test message $i", $ret['chatmessages'][$i]['message']);
        }
    }

    public function testTyping() {
        $this->addLoginAndLogin($this->user, 'testpw');

        $this->user->addEmail('test@test.com');
        $this->user2->addEmail('test2@test.com');

        # Create a chat to the second user.
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $rid = $ret['id'];
        $this->assertNotNull($rid);

        # Send a message.
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $rid,
            'message' => 'Test'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $cmid = $ret['id'];

        # Process the message so it can be sent via email.
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $cmid);
        $cm->process();

        # Age the message slightly (10 seconds old) so the typing action can bump it.
        # Messages need to be old enough to be in the "about to be mailed" window for typing to bump them.
        $tenSecondsAgo = date("Y-m-d H:i:s", strtotime("-10 seconds"));
        $this->dbhm->preExec("UPDATE chat_messages SET date = ? WHERE id = ?;", [$tenSecondsAgo, $cmid]);
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $cmid); # Reload

        # Say we're still typing. Should bump 1 chat message.
        $olddate = $cm->getPrivate('date');
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'action' => ChatRoom::ACTION_TYPING
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['count']);
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $cmid);
        $newdate = $cm->getPrivate('date');
        $this->assertNotEquals($olddate, $newdate);

        # Mark message as already mailed (simulates notification having been sent).
        $this->dbhm->preExec("UPDATE chat_messages SET mailedtoall = 1 WHERE id = ?;", [$cmid]);

        # Say we're still typing - nothing to bump (message already mailed).
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'action' => ChatRoom::ACTION_TYPING
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['count']);
    }

    public function testDelete() {
        $this->addLoginAndLogin($this->user, 'testpw');

        $this->user->addEmail('test@test.com');
        $this->user2->addEmail('test2@test.com');

        # Create a chat to the second user with a referenced message from the second user.
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $rid = $ret['id'];
        $this->assertNotNull($rid);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $rid))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->willReturn(TRUE);

        # Send a message.
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $rid,
            'message' => 'Test'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $cmid = $ret['id'];

        # Delete it
        $ret = $this->call("chatmessages", 'DELETE', [
            'id' => $cmid
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $rid ]);
        $this->assertEquals(1, count($ret['chatmessages']));
        $this->assertEquals(1, $ret['chatmessages'][0]['deleted']);
    }

    public function testReviewRecipientOnNoGroups() {
        # Normally mods on the recipient's groups see chat for review.  But if the recipient is not on any group
        # then the sender's mods see it.
        $this->addLoginAndLogin($this->user, 'testpw');

        # Set up:
        # - sender on group1
        # - recipient on group2
        list($g, $this->groupid) = $this->createTestGroup('testgroup2', Group::GROUP_FREEGLE);
        list($this->user, $this->uid) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User', 'test1@test.com', 'testpw');
        list($g2, $this->groupid2) = $this->createTestGroup('testgroup3', Group::GROUP_FREEGLE);
        list($this->user2, $this->uid2) = $this->createTestUserWithMembership($this->groupid2, User::ROLE_MEMBER, 'Test User', 'test2@test.com', 'testpw');

        # Create a chat from first user to second user.
        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://spam.wherever and email test@test.com',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        # Process the message to trigger review logic
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        $cm->process();

        # Set up:
        # - mod on sender's group
        # - mod on recipient's group
        list($mod1, $modid1) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MODERATOR, 'Test User', 'mod1@test.com', 'testpw');
        list($mod2, $modid2) = $this->createTestUserWithMembership($this->groupid2, User::ROLE_MODERATOR, 'Test User', 'mod2@test.com', 'testpw');

        # Mod on sender's group shouldn't see the message for review this recipient is on a group, but not theirs.
        $this->assertTrue($mod1->login('testpw'));
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['chatreview']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatmessages']));

        # Mod on recipient's group should see it.
        $this->assertTrue($mod2->login('testpw'));
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['chatreview']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatmessages']));

        # Make sure the recipient isn't on any groups.
        $this->user2->removeMembership($this->groupid2);

        # Mod on sender's group should now see the message for review.
        $this->assertTrue($mod1->login('testpw'));
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['chatreview']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatmessages']));

        # Mod on recipient's group should not see it.
        $this->assertTrue($mod2->login('testpw'));
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['chatreview']);

        $ret = $this->call('chatmessages', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatmessages']));
    }

    public function testQuickChatReview() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup2', Group::GROUP_FREEGLE);

        # Make uid a mod on the group.  Not signed up for quick review yet.
        $u = new User($this->dbhr, $this->dbhm, $this->uid);
        $u->addMembership($gid, User::ROLE_MODERATOR);
        $u->setGroupSettings($gid, [ 'active' => 1 ]);
        $this->assertTrue($u->login('testpw'));

        # Set up a chat message for review on another group which has widerchatreview on, has another mod, but
        # the earlier mod isn't on.
        $gid2 = $g->create('testgroup3', Group::GROUP_FREEGLE);
        $settings = json_decode($g->getPrivate('settings'), TRUE);
        $settings['widerchatreview'] = 1;
        $g->setSettings($settings);
        Group::clearCache();

        list($user1, $uid1) = $this->createTestUserWithMembership($gid2, User::ROLE_MEMBER, 'Test User', 'user1@test.com', 'testpw');
        list($user2, $uid2) = $this->createTestUserWithMembership($gid2, User::ROLE_MEMBER, 'Test User', 'user2@test.com', 'testpw');
        list($user3, $uid3) = $this->createTestUserWithMembership($gid2, User::ROLE_MODERATOR, 'Test User', 'user3@test.com', 'testpw');

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($uid1, $uid2);

        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cid, $banned) = $cm->create($rid, $uid1, "Test message");
        $cm->process(TRUE);

        # Should not see this for review
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['chatmessages']));

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['chatreview']);

        # Now enable quick chat review.
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $settings = json_decode($g->getPrivate('settings'), TRUE);
        $settings['widerchatreview'] = 1;
        $g->setSettings($settings);
        Group::clearCache();

        # Should now see it.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['chatmessages']));

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['chatreviewother']);
    }

    public function testReviewLastSpam() {
        $this->addLoginAndLogin($this->user, 'testpw');

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        $this->assertNotNull($this->cid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test £1',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test £1 again',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test innocent',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $mid3 = $ret['id'];

        # Process the messages to trigger review logic
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        $cm->process();

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        $cm->process();

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid3);
        $cm->process();

        # Messages should be held for:
        # - spam
        # - spam (not last)
        # - last
        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_SPAM, $cm->getPrivate('reportreason'));

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_SPAM, $cm->getPrivate('reportreason'));

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid3);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_LAST, $cm->getPrivate('reportreason'));
    }
//
//    public function testEH2()
//    {
//        $u = new User($this->dbhr, $this->dbhm);
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//
//        $uid = $u->findByEmail('sheilamentor@gmail.com');
//        $u = new User($this->dbhr, $this->dbhm, $uid);
//        $_SESSION['id'] = $uid;
//
//        $ret = $this->call('chatmessages', 'GET', [ 'limit' => 10, 'modtools' => TRUE ]);
//
//        $this->assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//    }
}


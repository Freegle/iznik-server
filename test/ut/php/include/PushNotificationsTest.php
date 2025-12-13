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
class PushNotificationsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
    }

    public function testBasic() {
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', NULL, 'test2@test.com', 'testpw2');
        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $this->log("Send app User.");
        $n->add($id, PushNotifications::PUSH_FCM_ANDROID, 'test', FALSE);
        $this->assertEquals(1, count($n->get($id)));

        # Nothing to notify on MT.
        $this->assertEquals(0, $mock->notify($id, TRUE));

        # Fake an FD notification for the user.
        $sql = "INSERT INTO users_notifications (`fromuser`, `touser`, `type`, `title`) VALUES (?, ?, ?, 'Test');";
        $this->dbhm->preExec($sql, [ $id, $id, Notifications::TYPE_EXHORT ]);

        # App notifications with a category send one notification with channel_id.
        $this->assertEquals(1, $mock->notify($id, FALSE));
        $this->assertEquals(1, $mock->notify($id, FALSE));

        $n->add($id, PushNotifications::PUSH_FIREFOX, 'test2', FALSE);
        $this->assertEquals(2, count($n->get($id)));
        # 1 for FCM_ANDROID + 1 for FIREFOX = 2 total
        $this->assertEquals(2, $n->notify($id, FALSE));

        # Test notifying mods.
        $this->log("Notify group mods");
        $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE subscription LIKE 'Test%';");
        $n->add($id, PushNotifications::PUSH_GOOGLE, 'test3', TRUE);

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Create a chat message from the user to the mods.
        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $r->createUser2Mod($id2, $this->groupid);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($rid, $id2, "Testing", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->assertNotNull($cm);

        $this->assertEquals(1, $mock->notifyGroupMods($this->groupid));

        $n->remove($id);
        $this->assertEquals([], $n->get($id));
    }

    public function testExecuteOld() {
        list($u, $id) = $this->createTestUser('Test', 'User', NULL, NULL, 'testpw');
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willReturn(TRUE);
        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception('UT'));

        $rc = $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);
        $this->assertNotNull($rc['exception']);

        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', [
            'count' => 1,
            'title' => 'UT'
        ]);
        $this->assertNotNull($rc['exception']);
    }

    public function testExecuteFCM() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willReturn(TRUE);
        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception('UT'));

        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_ANDROID, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'UT',
            'chatids' => [ 1 ]
        ]);
        $this->assertNotNull($rc['exception']);

        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_IOS, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'UT',
            'chatids' => [ 1 ]
        ]);
        $this->assertNotNull($rc['exception']);

        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_IOS, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => '',
            'chatids' => [ 1 ]
        ]);
        $this->assertNotNull($rc['exception']);
    }

    public function testPoke() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->enableProxyingToOriginalMethods()
            ->getMock();
        $this->assertEquals(TRUE, $mock->poke($id, [ 'ut' => 1 ], FALSE));

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->enableProxyingToOriginalMethods()
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());
        $this->assertEquals(FALSE, $mock->poke($id, [ 'ut' => 1 ], FALSE));

        }

    public function testErrors() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fsockopen'))
            ->getMock();
        $mock->method('fsockopen')->willThrowException(new \Exception());
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fputs'))
            ->getMock();
        $mock->method('fputs')->willThrowException(new \Exception());
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fsockopen'))
            ->getMock();
        $mock->method('fsockopen')->willReturn(NULL);
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('puts'))
            ->getMock();
        $mock->method('puts')->willReturn(NULL);
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $this->assertTrue(TRUE);
    }

    public function testCategoryConstants() {
        # Test that category constants are defined correctly
        $this->assertEquals('CHAT_MESSAGE', PushNotifications::CATEGORY_CHAT_MESSAGE);
        $this->assertEquals('CHITCHAT_COMMENT', PushNotifications::CATEGORY_CHITCHAT_COMMENT);
        $this->assertEquals('CHITCHAT_REPLY', PushNotifications::CATEGORY_CHITCHAT_REPLY);
        $this->assertEquals('CHITCHAT_LOVED', PushNotifications::CATEGORY_CHITCHAT_LOVED);
        $this->assertEquals('POST_REMINDER', PushNotifications::CATEGORY_POST_REMINDER);
        $this->assertEquals('NEW_POSTS', PushNotifications::CATEGORY_NEW_POSTS);
        $this->assertEquals('COLLECTION', PushNotifications::CATEGORY_COLLECTION);
        $this->assertEquals('EVENT_SUMMARY', PushNotifications::CATEGORY_EVENT_SUMMARY);
        $this->assertEquals('EXHORT', PushNotifications::CATEGORY_EXHORT);

        # Test that categories config is set up correctly
        $this->assertArrayHasKey(PushNotifications::CATEGORY_CHAT_MESSAGE, PushNotifications::CATEGORIES);
        $this->assertEquals('time-sensitive', PushNotifications::CATEGORIES[PushNotifications::CATEGORY_CHAT_MESSAGE]['ios_interruption']);
        $this->assertEquals('chat_messages', PushNotifications::CATEGORIES[PushNotifications::CATEGORY_CHAT_MESSAGE]['android_channel']);
        $this->assertEquals('high', PushNotifications::CATEGORIES[PushNotifications::CATEGORY_CHAT_MESSAGE]['android_priority']);

        $this->assertEquals('passive', PushNotifications::CATEGORIES[PushNotifications::CATEGORY_CHITCHAT_COMMENT]['ios_interruption']);
        $this->assertEquals('social', PushNotifications::CATEGORIES[PushNotifications::CATEGORY_CHITCHAT_COMMENT]['android_channel']);
    }

    public function testNotificationPayloadCategory() {
        # Test that getNotificationPayload returns a category for chat messages
        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User2', NULL, 'test2@test.com', 'testpw2');

        # Create a chat between users
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list($rid, $created) = $r->createConversation($id, $id2);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($rid, $id2, "Testing chat message", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);

        # Get notification payload - should return CHAT_MESSAGE category with threadId and image
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route, $category, $threadId, $image) = $u->getNotificationPayload(FALSE);

        $this->assertEquals(1, $chatcount);
        $this->assertEquals(PushNotifications::CATEGORY_CHAT_MESSAGE, $category);
        $this->assertStringContainsString('/chats/', $route);
        $this->assertEquals('chat_' . $rid, $threadId);
        $this->assertNotNull($image);
    }

    public function testNotificationPayloadChitChatCategory() {
        # Test that getNotificationPayload returns correct category for newsfeed notifications
        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User2', NULL, 'test2@test.com', 'testpw2');

        # Clean up any existing notifications for this user first
        $this->dbhm->preExec("DELETE FROM users_notifications WHERE touser = ?;", [$id]);

        # Create newsfeed entries first (FK constraint)
        $nf = new Newsfeed($this->dbhr, $this->dbhm);
        $nfid1 = $nf->create(Newsfeed::TYPE_MESSAGE, $id, "Test post 1");
        $nfid2 = $nf->create(Newsfeed::TYPE_MESSAGE, $id, "Test post 2");
        $nfid3 = $nf->create(Newsfeed::TYPE_MESSAGE, $id, "Test post 3");

        # Clean up any notifications that might have been created by newsfeed creation
        $this->dbhm->preExec("DELETE FROM users_notifications WHERE touser = ?;", [$id]);

        # Create a newsfeed notification for a comment on user's post
        $this->dbhm->preExec("INSERT INTO users_notifications (`fromuser`, `touser`, `type`, `newsfeedid`) VALUES (?, ?, ?, ?);", [
            $id2, $id, Notifications::TYPE_COMMENT_ON_YOUR_POST, $nfid1
        ]);

        # Get notification payload - should return CHITCHAT_COMMENT category with threadId
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route, $category, $threadId, $image) = $u->getNotificationPayload(FALSE);

        $this->assertEquals(1, $notifcount);
        $this->assertEquals(PushNotifications::CATEGORY_CHITCHAT_COMMENT, $category);
        $this->assertEquals('chitchat_' . $nfid1, $threadId);

        # Clean up and test reply category
        $this->dbhm->preExec("DELETE FROM users_notifications WHERE touser = ?;", [$id]);
        $this->dbhm->preExec("INSERT INTO users_notifications (`fromuser`, `touser`, `type`, `newsfeedid`) VALUES (?, ?, ?, ?);", [
            $id2, $id, Notifications::TYPE_COMMENT_ON_COMMENT, $nfid2
        ]);

        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route, $category, $threadId, $image) = $u->getNotificationPayload(FALSE);
        $this->assertEquals(PushNotifications::CATEGORY_CHITCHAT_REPLY, $category);
        $this->assertEquals('chitchat_' . $nfid2, $threadId);

        # Clean up and test loved category
        $this->dbhm->preExec("DELETE FROM users_notifications WHERE touser = ?;", [$id]);
        $this->dbhm->preExec("INSERT INTO users_notifications (`fromuser`, `touser`, `type`, `newsfeedid`) VALUES (?, ?, ?, ?);", [
            $id2, $id, Notifications::TYPE_LOVED_POST, $nfid3
        ]);

        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route, $category, $threadId, $image) = $u->getNotificationPayload(FALSE);
        $this->assertEquals(PushNotifications::CATEGORY_CHITCHAT_LOVED, $category);
        $this->assertEquals('chitchat_' . $nfid3, $threadId);
    }

    public function testExecuteSendWithCategory() {
        # Test that executeSend handles category correctly for Android
        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception('UT'));

        # Test Android with chat message category, threadId, and image
        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_ANDROID, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'Test message',
            'chatids' => [ 1 ],
            'category' => PushNotifications::CATEGORY_CHAT_MESSAGE,
            'threadId' => 'chat_123',
            'image' => 'https://images.ilovefreegle.org/tuimg_123.jpg'
        ]);
        $this->assertNotNull($rc['exception']);

        # Test iOS with chat message category, threadId, and image
        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_IOS, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'Test message',
            'chatids' => [ 1 ],
            'category' => PushNotifications::CATEGORY_CHAT_MESSAGE,
            'threadId' => 'chat_123',
            'image' => 'https://images.ilovefreegle.org/tuimg_123.jpg'
        ]);
        $this->assertNotNull($rc['exception']);

        # Test with unknown category (should still work, just no special config)
        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_ANDROID, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'Test message',
            'chatids' => [ 1 ],
            'category' => 'UNKNOWN_CATEGORY'
        ]);
        $this->assertNotNull($rc['exception']);

        # Test with null category (should still work)
        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_ANDROID, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'Test message',
            'chatids' => [ 1 ],
            'category' => NULL
        ]);
        $this->assertNotNull($rc['exception']);
    }

    public function testNoDuplicateNotificationsForUnviewedMessages() {
        # Test that calling notify() multiple times doesn't send duplicate notifications
        # for messages that were already notified but not yet viewed by the user.
        #
        # This reproduces a bug where the legacy notification path uses lastmsgseen
        # instead of lastmsgnotified, causing duplicates when:
        # 1. Message is sent and notified (lastmsgnotified updated)
        # 2. User doesn't view it (lastmsgseen stays at 0)
        # 3. Another trigger calls notify() again
        # 4. Legacy path finds "unseen" messages and re-notifies

        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User2', NULL, 'test2@test.com', 'testpw2');

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());

        # Add FCM Android subscription for recipient
        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($id, PushNotifications::PUSH_FCM_ANDROID, 'test-android', FALSE);

        # Create a chat and send a message from user2 to user1
        # Pass $process = FALSE to skip inline notification (14th param)
        # This lets us control when notifications are sent for testing
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list($rid, $created) = $r->createConversation($id, $id2);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($rid, $id2, "Test chat message", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL, NULL, FALSE, FALSE, FALSE);

        # Manually mark the message as processed/visible (without triggering notifications)
        $this->dbhm->preExec("UPDATE chat_messages SET processingrequired = 0, processingsuccessful = 1, reviewrequired = 0 WHERE id = ?", [$cm]);

        # Verify lastmsgnotified is NOT set yet
        $roster = $this->dbhr->preQuery("SELECT lastmsgnotified, lastmsgseen FROM chat_roster WHERE chatid = ? AND userid = ?", [$rid, $id]);
        $this->assertTrue(
            count($roster) == 0 || is_null($roster[0]['lastmsgnotified']) || $roster[0]['lastmsgnotified'] == 0,
            "lastmsgnotified should not be set before first notify"
        );

        # First notify() should send notifications
        $count1 = $mock->notify($id, FALSE, FALSE, $rid);
        $this->assertGreaterThan(0, $count1, "First notify should send notifications");

        # Verify lastmsgnotified was set
        $roster = $this->dbhr->preQuery("SELECT lastmsgnotified, lastmsgseen FROM chat_roster WHERE chatid = ? AND userid = ?", [$rid, $id]);
        $this->assertGreaterThan(0, count($roster), "Roster entry should exist after first notify");
        $this->assertNotNull($roster[0]['lastmsgnotified'], "lastmsgnotified should be set after first notify");

        # User has NOT viewed the message, so lastmsgseen should still be NULL or 0
        $this->assertTrue(
            is_null($roster[0]['lastmsgseen']) || $roster[0]['lastmsgseen'] == 0,
            "lastmsgseen should be NULL or 0 since user hasn't viewed message"
        );

        # Second notify() should NOT send any notifications - the message was already notified
        # BUG: Currently this sends duplicates because legacy path checks lastmsgseen not lastmsgnotified
        $count2 = $mock->notify($id, FALSE, FALSE, $rid);
        $this->assertEquals(0, $count2, "Second notify should NOT send duplicate notifications for already-notified messages");
    }

    public function testNotificationWithCategory() {
        # Test that app notifications with a category include channel_id
        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User2', NULL, 'test2@test.com', 'testpw2');

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());

        # Add FCM Android subscription
        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($id, PushNotifications::PUSH_FCM_ANDROID, 'test-android', FALSE);

        # Create a chat message to trigger CHAT_MESSAGE category
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list($rid, $created) = $r->createConversation($id, $id2);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($rid, $id2, "Test chat message", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);

        # Notify should return 1 for app notification with category
        $count = $mock->notify($id, FALSE);
        $this->assertEquals(1, $count);

        # Add browser push subscription
        $n->add($id, PushNotifications::PUSH_BROWSER_PUSH, 'test-browser', FALSE);

        # Notify should return 2 (1 for Android + 1 for browser)
        $count = $mock->notify($id, FALSE);
        $this->assertEquals(2, $count);
    }
}


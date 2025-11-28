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
class PostNotificationsTest extends IznikTestCase {
    private $dbhr, $dbhm;
    private $queuedNotifications = [];

    protected function setUp() : void {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->queuedNotifications = [];

        // Ensure tracking table exists (for CI environments where schema might be updated)
        $this->dbhm->preExec("CREATE TABLE IF NOT EXISTS `users_postnotifications_tracking` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `groupid` bigint unsigned NOT NULL,
            `frequency` int NOT NULL COMMENT 'Digest frequency constant (-1=immediate, 1=hourly, 24=daily, etc.)',
            `msgdate` timestamp NULL DEFAULT NULL COMMENT 'Arrival of message we have sent notifications up to',
            `lastsent` timestamp NULL DEFAULT NULL COMMENT 'When we last sent notifications for this group/frequency',
            PRIMARY KEY (`id`),
            UNIQUE KEY `groupid_frequency` (`groupid`,`frequency`),
            KEY `groupid` (`groupid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Clean up test data
        $this->dbhm->preExec("DELETE FROM users_postnotifications_tracking WHERE groupid IN (SELECT id FROM `groups` WHERE nameshort LIKE 'testgroup%');");
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM users_postnotifications_tracking WHERE groupid IN (SELECT id FROM `groups` WHERE nameshort LIKE 'testgroup%');");
        parent::tearDown();
    }

    /**
     * Test that no notifications are sent for a group with no posts.
     */
    public function testNoPostsNoNotifications() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->never())->method('queueSend');

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(0, $count);
    }

    /**
     * Test that no notifications are sent to users without app push subscriptions.
     */
    public function testNoAppSubscriptionNoNotification() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        // Create a user with immediate frequency but no push subscription
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        // Create a message on the group from another user
        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->never())->method('queueSend');

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(0, $count);
    }

    /**
     * Test that a single post generates a notification with details.
     */
    public function testSinglePostNotification() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        // Create a user with app push subscription
        // Must be Admin to receive notifications (temporary restriction)
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        // Add FCM Android subscription
        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        // Create a message from another user
        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();

        $self = $this;
        $mock->expects($this->once())
            ->method('queueSend')
            ->willReturnCallback(function($userid, $type, $params, $endpoint, $payload) use ($self, $uid, $id) {
                $self->assertEquals($uid, $userid);
                $self->assertEquals(PushNotifications::PUSH_FCM_ANDROID, $type);
                $self->assertStringContainsString('OFFER', $payload['title']);
                $self->assertEquals(PushNotifications::CATEGORY_NEW_POSTS, $payload['category']);
                $self->assertStringContainsString('/message/', $payload['route']);
                $self->assertNotNull($payload['threadId']);
            });

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(1, $count);
    }

    /**
     * Test that multiple posts generate a summary notification.
     */
    public function testMultiplePostsSummaryNotification() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        // Create a user with app push subscription (must be Admin)
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        // Create multiple messages from another user
        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        // Create 3 OFFER messages
        for ($i = 1; $i <= 3; $i++) {
            $substitutions = [
                'freegleplayground' => 'testgroup',
                'Basic test' => "OFFER: Item $i (Location)",
                'test@test.com' => 'sender@test.com'
            ];
            $msg = $this->createMessageContent('basic', $substitutions);
            $msg = $this->unique($msg);

            list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
            $this->assertNotNull($id);
            $this->assertEquals(MailRouter::APPROVED, $rc);
        }

        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();

        $self = $this;
        $mock->expects($this->once())
            ->method('queueSend')
            ->willReturnCallback(function($userid, $type, $params, $endpoint, $payload) use ($self) {
                // Title should contain count and type
                $self->assertStringContainsString('3 OFFERs', $payload['title']);
                // Route should be to browse page for multiple posts
                $self->assertEquals('/browse', $payload['route']);
                $self->assertEquals(PushNotifications::CATEGORY_NEW_POSTS, $payload['category']);
            });

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(1, $count);
    }

    /**
     * Test that taken/received posts are excluded from notifications.
     */
    public function testTakenPostsExcluded() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        // Create a message
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        // Mark the message as taken
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", NULL, NULL);

        // Should get no notifications since the post is taken
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->never())->method('queueSend');

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(0, $count);
    }

    /**
     * Test that users don't get notified about their own posts.
     */
    public function testNoNotificationForOwnPosts() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        // Create a user who will both post and receive (must be Admin)
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        // Create a message from this same user
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'test@test.com', 'to@test.com');
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        // Should get no notifications since it's their own post
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->never())->method('queueSend');

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(0, $count);
    }

    /**
     * Test frequency tracking - don't resend notifications for same posts.
     */
    public function testTrackingSamePostsNotResent() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);

        $callCount = 0;
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->method('queueSend')->willReturnCallback(function() use (&$callCount) {
            $callCount++;
        });

        // First send - should notify
        $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(1, $callCount);

        // Second send - should NOT notify (same posts)
        $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(1, $callCount); // Still 1, no new calls
    }

    /**
     * Test that closed groups don't get notifications.
     */
    public function testClosedGroupNoNotifications() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);
        $g->setSettings(['closed' => TRUE]);

        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->never())->method('queueSend');

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(0, $count);
    }

    /**
     * Test that iOS subscriptions also receive notifications.
     */
    public function testIOSNotification() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        // Add iOS subscription instead of Android
        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_IOS, 'test-ios-token', FALSE);

        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);

        $self = $this;
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->once())
            ->method('queueSend')
            ->willReturnCallback(function($userid, $type, $params, $endpoint, $payload) use ($self) {
                $self->assertEquals(PushNotifications::PUSH_FCM_IOS, $type);
            });

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(1, $count);
    }

    /**
     * Test mixed OFFERs and WANTEDs in summary.
     */
    public function testMixedOffersAndWanteds() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        // Create 2 OFFERs
        for ($i = 1; $i <= 2; $i++) {
            $substitutions = [
                'freegleplayground' => 'testgroup',
                'Basic test' => "OFFER: Item $i (Location)",
                'test@test.com' => 'sender@test.com'
            ];
            $msg = $this->createMessageContent('basic', $substitutions);
            $msg = $this->unique($msg);

            list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
            $this->assertNotNull($id);
        }

        // Create 1 WANTED
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => "WANTED: Something (Location)",
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);

        $self = $this;
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->once())
            ->method('queueSend')
            ->willReturnCallback(function($userid, $type, $params, $endpoint, $payload) use ($self) {
                // Title should contain both types
                $self->assertStringContainsString('2 OFFERs', $payload['title']);
                $self->assertStringContainsString('1 WANTED', $payload['title']);
            });

        $mock->send($gid, Digest::IMMEDIATE);
    }

    /**
     * Test daily frequency respects timing.
     */
    public function testDailyFrequencyTiming() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::DAILY);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);

        $callCount = 0;
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->method('queueSend')->willReturnCallback(function() use (&$callCount) {
            $callCount++;
        });

        // First send for daily - should send
        $mock->send($gid, Digest::DAILY);
        $this->assertEquals(1, $callCount);

        // Immediate second call - should not send (need to wait 24 hours for daily)
        $mock->send($gid, Digest::DAILY);
        $this->assertEquals(1, $callCount); // Still 1
    }

    /**
     * Test that non-Admin users don't receive notifications (temporary restriction).
     */
    public function testNonAdminUsersExcluded() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        // Create a regular user (NOT Admin) with app push subscription
        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        // Don't set systemrole - defaults to User
        $u->addMembership($gid, User::ROLE_MEMBER);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $n->add($uid, PushNotifications::PUSH_FCM_ANDROID, 'test-token', FALSE);

        // Create a message from another user
        list($sender, $senderid, $senderEmailid) = $this->createTestUser('Sender', 'User', NULL, 'sender@test.com', 'testpw');
        $sender->addMembership($gid, User::ROLE_MEMBER);
        $sender->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (Location)',
            'test@test.com' => 'sender@test.com'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteTestMessage($msg,'sender@test.com', 'to@test.com');
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        // Non-Admin should NOT receive notifications
        $mock = $this->getMockBuilder('Freegle\Iznik\PostNotifications')
            ->setConstructorArgs([$this->dbhr, $this->dbhm])
            ->setMethods(['queueSend'])
            ->getMock();
        $mock->expects($this->never())->method('queueSend');

        $count = $mock->send($gid, Digest::IMMEDIATE);
        $this->assertEquals(0, $count);
    }

    /**
     * Helper to create message content from template.
     */
    protected function createMessageContent($template, $substitutions = []) {
        $msgPath = IZNIK_BASE . '/test/ut/php/msgs/' . $template;
        $content = file_get_contents($msgPath);

        foreach ($substitutions as $find => $replace) {
            $content = str_ireplace($find, $replace, $content);
        }

        return $content;
    }

    /**
     * Helper to create and route a message with custom parameters.
     */
    private function createAndRouteTestMessage($msg, $from, $to) {
        global $dbhr, $dbhm;

        $r = new MailRouter($dbhr, $dbhm);
        list ($id, $failok) = $r->received(\Swift_Message::newInstance()->setTo([$to])->setFrom([$from])->setBody($msg), NULL, NULL, $from);

        $rc = NULL;
        if ($id) {
            $rc = $r->route();
        }

        return [$r, $id, $failok, $rc];
    }
}

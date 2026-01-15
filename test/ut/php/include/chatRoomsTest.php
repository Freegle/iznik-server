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
class chatRoomsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");
        $dbhm->preExec("DELETE FROM users_emails WHERE email LIKE 'test2@user.trashnothing.com';");
        $dbhm->preExec("DELETE FROM users_replytime;");

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
    }

    protected function tearDown(): void
    {
        # Resume background processing
        @unlink('/tmp/iznik.background.abort');
    }

    public function testPromoteRead() {
        # Create an unread chat message between a user and a mod on a group.
        list($u1, $mod) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MODERATOR, 'Test User 1', 'test1@test.com', 'testpw');
        list($u2, $member) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User 2', 'test2@test.com', 'testpw');

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $id = $r->createUser2Mod($member, $this->groupid);

        $this->assertEquals('testgroup Volunteers', $r->getName($id, $member));
        $this->assertEquals('Test User 2 on testgroup', $r->getName($id, $member + 1));

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $member, "Testing", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->assertNotNull($cm);
        $this->assertFalse($banned);

        $this->assertEquals(1, $r->countAllUnseenForUser($mod, [
            ChatRoom::TYPE_USER2MOD
        ]));

        # Create a new user and promote to mod.
        list($u3, $newmod) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User 3', 'test3@test.com', 'testpw');
        $u3->setRole(User::ROLE_MODERATOR, $this->groupid);

        # The chat message should have been marked as read for this user to avoid flooding them with unread old chat
        # messages.
        $this->assertEquals(0, $r->countAllUnseenForUser($newmod, [
            ChatRoom::TYPE_USER2MOD
        ]));
    }

    public function testGroup() {
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createGroupChat('test', $this->groupid);
        $this->assertNotNull($id);

        $r->setAttributes(['name' => 'test']);
        $this->assertEquals('testgroup Mods', $r->getPublic()['name']);
        $this->assertEquals('testgroup Mods', $r->getName($id, NULL));
        
        $this->assertEquals(1, $r->delete());
    }

    public function testConversation() {
        list($u1_obj, $u1) = $this->createTestUser(NULL, NULL, 'Test User 1', 'test1@test.com', 'testpw');
        list($u2_obj, $u2) = $this->createTestUser(NULL, NULL, 'Test User 2', 'test2@test.com', 'testpw');

        list ($r, $id, $blocked) = $this->createTestConversation($u1, $u2);
        $this->assertNotNull($id);

        # Counts coverage.
        $r->updateMessageCounts();
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, $r->getPrivate('msgvalid'));
        $this->assertEquals(0, $r->getPrivate('msginvalid'));

        # There's some code to recover from a bug.  Force the bug.
        $this->dbhm->preExec("DELETE FROM chat_roster WHERE userid = ?;", [
            $u1
        ]);
        $this->assertFalse($r->upToDateAll($u1));

        # Further creates should find the same one.
        list ($id2, $blocked) = $r->createConversation($u1, $u2);
        $this->assertEquals($id, $id2);

        list ($id2, $blocked) = $r->createConversation($u2, $u1);
        $this->assertEquals($id, $id2);

        $this->assertEquals(1, $r->delete());

        }

    public function testCannotCreateConversationWithSelf() {
        # Create a user
        list($u1, $user1) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User 1', 'test1@test.com', 'testpw');

        # Try to create a conversation with themselves - should return NULL and not create a chat
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($id, $blocked) = $r->createConversation($user1, $user1);

        $this->assertNull($id);
        $this->assertFalse($blocked);
    }

    public function testError() {
        $dbconfig = array (
            'host' => SQLHOST,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([
                "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8",
                $dbconfig['user'], $dbconfig['pass'], array(), TRUE
            ])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());
        $r->setDbhm($mock);

        $id = $r->createGroupChat('test', $this->groupid);
        $this->assertNull($id);
    }

    public function testBlockingUnpromises() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addMembership($this->groupid);
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);

        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addMembership($this->groupid);
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        list ($r, $id, $blocked) = $this->createTestConversation($u1, $u2);
        $this->assertNotNull($id);

        # u1 promises to u2.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test1@test.com', 'to@test.com', $msg);
        list ($msgid, $failok) = $m->save();
        $m->setPrivate('fromuser', $u1);
        $m->promise($u2);
        $this->assertTrue($m->promiseCount() == 1);

        # Make the first user block the second.
        $r->updateRoster($u1, NULL, ChatRoom::STATUS_BLOCKED);

        # Should have unpromised.
        $this->assertTrue($m->promiseCount() == 0);
    }

    public function testUser2Mod() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $u->addMembership($this->groupid, User::ROLE_MEMBER);
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $id = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $id for $u1 <-> $u2");
        $this->assertNotNull($id);

        $r->delete();

        global $dbconfig;

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willReturn(FALSE);
        $r->setDbhm($mock);

        $id = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $id for $u1 <-> $u2");
        $this->assertNull($id);

        self::assertEquals(0, $r->lastSeenForUser($u2), $u1);
    }

    private $msgsSent = [];

    public function mailer(\Swift_Message $message) {
        $this->log("Send " . $message->getSubject() . " to " . var_export($message->getTo(), TRUE));
        $groupid  = NULL;
        $headers = $message->getHeaders()->getAll();

        foreach ($headers as $header) {
            if ($header->getFieldName() == 'X-Freegle-Group-Volunteer') {
                $groupid = intval($header->getValue());
            }
        }

        $this->msgsSent[] = [
            'subject' => $message->getSubject(),
            'to' => $message->getTo(),
            'body' => $message->getBody(),
            'groupid' => $groupid,
            'contentType' => $message->getContentType()
        ];
    }

    public function testEmojiSplit()
    {
        $r = new ChatRoom($this->dbhr, $this->dbhm);

        self::assertEquals('Test', $r->splitEmoji('Test'));
        self::assertEquals('\\u1f923\\u', $r->splitEmoji('\\u1f923\\u'));
        self::assertEquals('Test', $r->splitEmoji('Test\\u1f923\\u'));
        self::assertEquals('Test', $r->splitEmoji('\\u1f923\\uTest'));

        }

    public function testBlockAndView() {
        $this->log(__METHOD__ );

        # Pretend to be FD.
        $_REQUEST['modtools'] = FALSE;

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addMembership($this->groupid);
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addMembership($this->groupid);
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        list ($r, $id, $blocked) = $this->createTestConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        $this->assertNotNull($id);

        $this->waitBackground();
        $this->assertNull($r->replyTime($u1));
        $this->assertNull($r->replyTime($u2));

        # Make the first user block the second.
        $r->updateRoster($u1, NULL, ChatRoom::STATUS_BLOCKED);

        # Now create the chat again.  We used to have bug where this unblocked the user.
        list ($id2, $blocked) = $r->createConversation($u1, $u2);
        $this->assertEquals($id2, $id);

        # Chat still shouldn't show in the list for this user.
        $this->assertNull($r->listForUser(FALSE, $u1, NULL, NULL));
        self::assertEquals(1, count($r->listForUser(FALSE, $u2, NULL, NULL)));
    }

    public function testInvalidId() {
        $r = new ChatRoom($this->dbhr, $this->dbhm, -1);
        $this->assertEquals(NULL, $r->getId());
    }

    public function testBanned() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, "Test User 1");
        $u1 = new User($this->dbhr, $this->dbhm, $uid1);
        $u1->addMembership($this->groupid);
        $uid2 = $u->create(NULL, NULL, "Test User 2");
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        $u2->addMembership($this->groupid);

        # Ban the initiating member on the group they have in common.  This should prevent a chat opening.
        $u1->removeMembership($this->groupid, TRUE);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($id, $blocked) = $r->createConversation($uid1, $uid2);
        $this->assertNull($id);
        $this->assertTrue($blocked);
    }

    public function testCanSeeAsSupport() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, "Test User 1");
        $u1 = new User($this->dbhr, $this->dbhm, $uid1);
        $u1->addMembership($this->groupid);
        $uid2 = $u->create(NULL, NULL, "Test User 2");
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        $u2->addMembership($this->groupid);
        $uid3 = $u->create(NULL, NULL, "Test User 3");
        $u3 = new User($this->dbhr, $this->dbhm, $uid3);
        $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($id, $blocked) = $r->createConversation($uid1, $uid2);
        $this->assertNotNull($id);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $_SESSION['id'] = $uid3;
        $this->assertTrue($r->canSee($uid3));
    }

    public function testCanSeeAsMod() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, "Test User 1");
        $u1 = new User($this->dbhr, $this->dbhm, $uid1);
        $u1->addMembership($this->groupid);
        $uid2 = $u->create(NULL, NULL, "Test User 2");
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        $u2->addMembership($this->groupid);
        $uid3 = $u->create(NULL, NULL, "Test User 3");
        $u3 = new User($this->dbhr, $this->dbhm, $uid3);
        $u3->addMembership($this->groupid, User::ROLE_MODERATOR);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($id, $blocked) = $r->createConversation($uid1, $uid2);
        $this->assertNotNull($id);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $_SESSION['id'] = $uid3;
        $this->assertFalse($r->canSee($uid3, FALSE));
        $this->assertTrue($r->canSee($uid3, TRUE));

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createUser2Mod($uid1, $this->groupid);
        $this->assertNotNull($id);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $_SESSION['id'] = $uid3;
        $this->assertFalse($r->canSee($uid2, FALSE));
        $this->assertTrue($r->canSee($uid3, TRUE));
    }

    public function testOldNotify() {
        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);

        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        list ($r, $id, $blocked) = $this->createTestConversation($u1, $u2);
        $this->assertNotNull($id);

        # Add a message.
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Testing", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("Created chat message $cm");

        # Make it old.
        $r->setPrivate('latestmessage', '2001-01-01');

        # Now send an email reply to this notification, but from a different email.  That email should be blocked
        # because it's old.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text'));
        $mr = new MailRouter($this->dbhm, $this->dbhm);
        list ($mid, $failok) = $mr->received(Message::EMAIL, 'from2@test.com', "notify-$id-$u2@" . USER_DOMAIN, $msg);
        $rc = $mr->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testGetMessagesForReviewNoModGroups() {
        # Test that getMessagesForReview returns empty array for non-moderator.
        # This was a bug where an empty IN () clause caused SQL syntax error.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, "Test User");
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $ctx = NULL;

        # User with no moderatorships should get empty result, not SQL error.
        $msgs = $r->getMessagesForReview($u, NULL, $ctx);
        $this->assertIsArray($msgs);
        $this->assertEmpty($msgs);
    }

    public function trueFalseProvider() {
        return [
            [ TRUE ],
            [ FALSE ]
        ];
    }

    /**
     * @dataProvider trueFalseProvider
     */
    public function testUserStopsReplyingReplyTime($expected) {
        # Stop background processing during this test to prevent race conditions
        touch('/tmp/iznik.background.abort');

        $this->log(__METHOD__ . " expected=" . ($expected ? 'TRUE' : 'FALSE'));

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addMembership($this->groupid);
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $this->log("Created user u1=$u1");

        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addMembership($this->groupid);
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);
        $this->log("Created user u2=$u2");

        # Check for any existing reply times in the database
        $existing = $this->dbhr->preQuery("SELECT * FROM users_replytime WHERE userid IN (?, ?);", [$u1, $u2]);
        $this->log("Existing reply times: " . var_export($existing, TRUE));

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($id, $blocked) = $r->createConversation($u1, $u2);
        $this->assertNotNull($id);
        $this->log("Created chatroom id=$id");

        # Create a message from u2 -> u1, then a message from u1 -> u2 with reply expected.  That sets us up
        # for calculating a reply time for u2.
        # Loop until entire sequence (message creation + replyTime call) happens within the same second to avoid timing issues.
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $maxRetries = 20;
        $actualTime = NULL;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            list ($cm1, $banned) = $m->create($id, $u2, "Testing");
            list ($cm2, $banned) = $m->create($id, $u1, "Testing");

            $m2 = new ChatMessage($this->dbhr, $this->dbhm, $cm2);
            $m2->setPrivate('replyexpected', $expected ? 1 : 0);

            # Get reply time immediately
            $actualTime = $r->replyTime($u2, TRUE);

            # Check if messages and replyTime call all happened in same second
            $msg2Data = $this->dbhr->preQuery("SELECT UNIX_TIMESTAMP(date) AS ts FROM chat_messages WHERE id = ?;", [$cm2]);
            $now = time();

            if ($actualTime == 0 || $msg2Data[0]['ts'] == $now) {
                $this->log("Sequence completed successfully (attempt " . ($attempt + 1) . ", actualTime=$actualTime)");
                break;
            }

            # Clean up and retry
            $this->dbhm->preExec("DELETE FROM chat_messages WHERE id IN (?, ?);", [$cm1, $cm2]);
            $this->dbhm->preExec("DELETE FROM users_replytime WHERE userid = ?;", [$u2]);
            $this->log("Timing issue, retrying (attempt " . ($attempt + 1) . ", actualTime=$actualTime)");
        }

        $this->log("Created message from u2: cm=$cm1");
        $this->log("Created message from u1: cm=$cm2, actualTime=$actualTime");

        # Reply time should be 0 since we ensured the call happened in the same second as message creation.
        $this->log("TEST CHECKPOINT 1: Got actualTime=" . var_export($actualTime, TRUE));
        $this->assertEquals(0, $actualTime);

        # Force recalculation
        sleep(2);
        $this->log("TEST CHECKPOINT 2: About to call replyTime($u2, TRUE) after 2 second sleep");
        $time1 = $r->replyTime($u2, TRUE);
        $this->log("TEST CHECKPOINT 2: Got time1=" . var_export($time1, TRUE));

        if ($expected) {
            # Should have a value, as we have not yet replied.
            $this->assertNotNull($time1);

            sleep(2);
            $this->log("TEST CHECKPOINT 3: About to call replyTime($u2, TRUE) after another 2 second sleep");
            $time2 = $r->replyTime($u2, TRUE);
            $this->log("TEST CHECKPOINT 3: Got time2=" . var_export($time2, TRUE));

            # Should have increased.
            $this->assertGreaterThan($time1, $time2);
        } else {
            $this->log("TEST CHECKPOINT 3: About to call replyTime($u2) - expecting NULL");
            $finalTime = $r->replyTime($u2);
            $this->log("TEST CHECKPOINT 3: Got finalTime=" . var_export($finalTime, TRUE));
            $this->assertNull($finalTime);
        }
        $this->log("TEST COMPLETED SUCCESSFULLY");
    }
}

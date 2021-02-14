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

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");

        $g = Group::get($dbhr, $dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
    }

    public function testGroup() {
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createGroupChat('test', $this->groupid);
        assertNotNull($id);

        $r->setAttributes(['name' => 'test']);
        assertEquals('testgroup Mods', $r->getPublic()['name']);
        
        assertEquals(1, $r->delete());

        }

    public function testConversation() {
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        assertNotNull($id);

        # Counts coverage.
        $r->updateMessageCounts();
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        assertEquals(0, $r->getPrivate('msgvalid'));
        assertEquals(0, $r->getPrivate('msginvalid'));

        # There's some code to recover from a bug.  Force the bug.
        $this->dbhm->preExec("DELETE FROM chat_roster WHERE userid = ?;", [
            $u1
        ]);
        assertFalse($r->upToDateAll($u1));

        # Further creates should find the same one.
        $id2 = $r->createConversation($u1, $u2);
        assertEquals($id, $id2);

        $id2 = $r->createConversation($u2, $u1);
        assertEquals($id, $id2);

        assertEquals(1, $r->delete());

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
        assertNull($id);

        }
    
    public function testNotifyUser2User() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addMembership($this->groupid);
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);

        # The "please introduce yourself" one.
        list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route) = $u->getNotificationPayload(FALSE);
        assertEquals("Why not introduce yourself to other freeglers?  You'll get a better response.", $title);

        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addMembership($this->groupid);
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        assertNotNull($id);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertEquals('Test User 1', $r->getPublic()['name']);
        assertEquals('Test User 1', $r->getPublic(NULL, NULL, TRUE)['name']);
        $_SESSION['id'] = $u1;
        assertEquals('Test User 2', $r->getPublic(NULL, NULL, TRUE)['name']);
        $_SESSION['id'] = $u2;
        assertEquals('Test User 1', $r->getPublic(NULL, NULL, TRUE)['name']);
        $_SESSION['id'] = NULL;

        assertNull($r->replyTime($u1));
        assertNull($r->replyTime($u2));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $msgid = $m->save();

        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_CHAT_MESSAGE);
        $attid = $a->create(NULL, 'image/jpeg', $data);
        assertNotNull($attid);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Testing", ChatMessage::TYPE_IMAGE, $msgid, TRUE, NULL, NULL, NULL, $attid);
        list ($cm, $banned) = $m->create($id, $u1, "Testing", ChatMessage::TYPE_INTERESTED, $msgid, TRUE, NULL, NULL, NULL, $attid);
        $this->log("Created chat message $cm");

        assertNull($r->replyTime($u1));
        assertNull($r->replyTime($u2));

        # Check notification payload - the 2 chat messages and the "please introduce yourself" one.
        list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route) = $u->getNotificationPayload(FALSE);
        assertEquals("You have 2 new messages and 1 notification", $title);

        # Exception first for coverage.
        $this->log("Fake exception");
        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('constructMessage'))
            ->getMock();

        $r->method('constructMessage')->willThrowException(new \Exception());

        assertEquals(0, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0));

        # We will have flagged this message as mailed to all even though we failed.
        $this->dbhm->preExec("UPDATE chat_messages SET mailedtoall = 0 WHERE id = ?;", [ $cm ]);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();
        
        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Notify - will email just one as we don't notify our own by default.
        $this->log("Will email justone");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0));
        assertEquals('Re: OFFER: Test item (location)', $this->msgsSent[0]['subject']);

        # Now pretend we've seen the messages.  Should flag the message as seen by all.
        $r->updateRoster($u1, $cm, ChatRoom::STATUS_ONLINE);
        $r->updateRoster($u2, $cm, ChatRoom::STATUS_ONLINE);
        $m = new ChatMessage($this->dbhr, $this->dbhm, $cm);
        assertEquals(1, $m->getPrivate('seenbyall'));

        # Shouldn't notify as we've seen them.
        $r->expects($this->never())->method('mailer');
        assertEquals(0, $r->notifyByEmail($id,  ChatRoom::TYPE_USER2USER));

        # Once more for luck - this time won't even check this chat.
        assertEquals(0, $r->notifyByEmail($id,  ChatRoom::TYPE_USER2USER));
        
        # Now send an email reply to this notification, but from a different email.  That email should
        # get attached to the correct user.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text'));
        $mr = new MailRouter($this->dbhm, $this->dbhm);
        $mid = $mr->received(Message::EMAIL, 'from2@test.com', "notify-$id-$u2@" . USER_DOMAIN, $msg);
        $rc = $mr->route();
        assertEquals(MailRouter::TO_USER, $rc);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        list($msgs, $users) = $r->getMessages();
        $this->log("Messages " . var_export($msgs, TRUE));
        assertEquals(ChatMessage::TYPE_DEFAULT, $msgs[1]['type']);
        assertEquals("Ok, here's a reply.", $msgs[1]['message']);
        assertEquals($u2, $msgs[1]['userid']);
        $u = User::get($this->dbhr, $this->dbhm, $u1);
        $u1emails = $u->getEmails();
        $this->log("U1 emails " . var_export($u1emails, TRUE));
        assertEquals(2, count($u1emails));
        $u = User::get($this->dbhr, $this->dbhm, $u2);
        $u2emails = $u->getEmails();
        $this->log("U2 emails " . var_export($u2emails, TRUE));
        assertEquals(3, count($u2emails));
        assertEquals('from2@test.com', $u2emails[1]['email']);

        assertNull($r->replyTime($u1));
        assertNotNull($r->replyTime($u2));

    }

    public function testNotifyUser2UserInterleaved() {
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

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);

        $m = new ChatMessage($this->dbhr, $this->dbhm);

        # Create:
        #
        # u1 -> u2 (platform)
        # u1 <- u2 (email)
        # u1 -> u2 (platform)
        #
        # Then notify u2.  Check that it contains both messages.
        list ($cm1id, $banned) = $m->create($id, $u1, "Test u1 -> u2 1");

        # Check notification payload.
        $uu2 = new User($this->dbhr, $this->dbhm, $u2);
        list ($total, $chatcount, $notifscount, $title, $message, $chatids, $route) = $uu2->getNotificationPayload(FALSE);
        assertEquals("Test User 1", $title);

        $cm1 = new ChatMessage($this->dbhr, $this->dbhm, $cm1id);
        assertEquals(0, $cm1->getPrivate('mailedtoall'));
        assertEquals(0, $cm1->getPrivate('seenbyall'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text'));
        $mr = new MailRouter($this->dbhm, $this->dbhm);
        $mid = $mr->received(Message::EMAIL, 'from2@test.com', "notify-$id-$u2@" . USER_DOMAIN, $msg);
        $rc = $mr->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $cm1 = new ChatMessage($this->dbhr, $this->dbhm, $cm1id);
        assertEquals(0, $cm1->getPrivate('mailedtoall'));
        assertEquals(0, $cm1->getPrivate('seenbyall'));

        list ($cm2id, $banned) = $m->create($id, $u1, "Test u1 -> u2 2");
        $this->log("u1 $u1 sent CM1 $cm1id, CM2 $cm2id to $u2");

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Notify - will email just one as we don't notify our own by default.
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0));

        $text = $this->msgsSent[0]['body'];
        assertTrue(strpos($text, 'Test u1 -> u2 1') !== FALSE);
    }

    public function testNotifyUser2UserOwn() {
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
        $u->setSetting('notifications', [
            User::NOTIFS_EMAIL_MINE => TRUE
        ]);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->willReturn(TRUE);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);


        # Send a message from 1 -> 2
        # Notify - should be 1 (notification to u2, no copy required)
        $m1 = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m1->create($id, $u1, "Testing", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email just $u2");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        # Reply from 2 -> 1
        # Notify - should be 1 (copy to u2 too soon, notification to u1 OK)
        $m2 = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m2->create($id, $u2, "Testing 1", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email just $u1");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        $m1->setPrivate('reviewrequired', 1);
        $m2->setPrivate('reviewrequired', 1);
        sleep(31); // Set review to reduce chance of background script kicking in on live system
        $m1->setPrivate('reviewrequired', 0);
        $m2->setPrivate('reviewrequired', 0);

        # Notify again - will send copy to u2.  There was a bug here where the previous notify was marking all as
        # sent and therefore this didn't happen.
        $this->log("$cm: Will email just $u2");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        # Reply back from 1 -> 2
        # Notify - none (too soon)
        list ($cm, $banned) = $m1->create($id, $u1, "Testing 2", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email none");
        assertEquals(0, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        # Reply back from 2 -> 1
        # Notify - just 1 (notification to u1 OK, too soon for copy to u2)
        list ($cm, $banned) = $m2->create($id, $u2, "Testing 2", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email just $u1");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        # Wait
        $m1->setPrivate('reviewrequired', 1);
        $m2->setPrivate('reviewrequired', 1);
        sleep(31); // Set review to reduce chance of background script kicking in on live system
        $m1->setPrivate('reviewrequired', 0);
        $m2->setPrivate('reviewrequired', 0);

        # Notify - should be 1 (delayed copy)
        $this->log("$cm: Will email just $u2");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));
    }

    public function testNotifyUser2UserOwn2() {
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
        $u->setSetting('notifications', [
            User::NOTIFS_EMAIL_MINE => TRUE
        ]);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->willReturn(TRUE);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);


        # Send a message from 2 -> 1
        # Notify - should be 2 (notification to u1, copy required)
        $m1 = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m1->create($id, $u2, "Testing", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email both $u1 and $u2");
        assertEquals(2, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        # Reply from 1 -> 2
        # Notify - should be 0 (copy to u2 too soon, notification to u1 too soon)
        $m2 = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m2->create($id, $u1, "Testing 1", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email none");
        assertEquals(0, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        # Reply back from 2 -> 1
        # Notify - none (still too soon)
        $m3 = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m3->create($id, $u2, "Testing 2", ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("$cm: Will email none");
        assertEquals(0, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));

        $m1->setPrivate('reviewrequired', 1);
        $m2->setPrivate('reviewrequired', 1);
        $m3->setPrivate('reviewrequired', 1);
        sleep(31); // Set review to reduce chance of background script kicking in on live system
        $m1->setPrivate('reviewrequired', 0);
        $m2->setPrivate('reviewrequired', 0);
        $m3->setPrivate('reviewrequired', 0);

        # Notify again - should be the delayed 2 now.
        $this->log("$cm: Will email both $u1 and $u2");
        assertEquals(2, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0, 30));
    }

    public function testNotifyAddress() {
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

        $a = new Address($this->dbhr, $this->dbhm);
        $pafs = $this->dbhr->preQuery("SELECT * FROM paf_addresses LIMIT 1;");
        foreach ($pafs as $paf) {
            $aid = $a->create($u1, $paf['id'], "Test desc");
        }

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, $aid, ChatMessage::TYPE_ADDRESS, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("Created chat message $cm");
        $m = new ChatMessage($this->dbhr, $this->dbhm, $cm);
        assertNotFalse(Utils::pres('address', $m->getPublic()));

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Notify - will email just one.
        $this->log("Will email justone");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0));
        assertContains('Test desc', $this->msgsSent[0]['body']);
        assertContains('sent you an address', $this->msgsSent[0]['body']);

        }

    public function testNotifyAvailability() {
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

        $this->log("Schedule for $u1");
        $s = new Schedule($this->dbhr, $this->dbhm, NULL, TRUE);
        $s->create($u1, [
            [
                "hour" => 0,
                "date" => "2018-05-24T00:00:00+01:00",
                "available" => 1
            ]
        ]);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, NULL, ChatMessage::TYPE_SCHEDULE_UPDATED, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("Created chat message $cm");
        $m = new ChatMessage($this->dbhr, $this->dbhm, $cm);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Notify - will email just one.
        $this->log("Will email justone");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, NULL, 0, TRUE));

        $this->log("Mailed " . var_export($this->msgsSent, TRUE));
        self::assertEquals("Test User 1 has updated when they may be available: Wednesday morning\r\n\r\n\r\n-------\r\nThis is a text-only version of the message; you can also view this message in HTML if you have it turned on, and on the website.  We're adding this because short text messages don't always get delivered successfully.\r\n", $this->msgsSent[0]['body']);

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
        assertNotNull($id);

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
        assertNull($id);

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
            'groupid' => $groupid
        ];
    }

    public function testNotifyUser2Mod() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);
        $u3 = $u->create(NULL, NULL, "Test User 3");
        $u->addEmail('test3@test.com');
        $u->addEmail('test3@' . USER_DOMAIN);

        $u1u = User::get($this->dbhr, $this->dbhm, $u1);
        $u2u = User::get($this->dbhr, $this->dbhm, $u2);
        $u3u = User::get($this->dbhr, $this->dbhm, $u3);
        $u1u->addMembership($this->groupid, User::ROLE_MEMBER);
        $u2u->addMembership($this->groupid, User::ROLE_OWNER);
        $u3u->addMembership($this->groupid, User::ROLE_MODERATOR);

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $id = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $id for $u1 <-> group {$this->groupid}");
        assertNotNull($id);

        # Create a query from the user to the mods
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Help me", ChatMessage::TYPE_DEFAULT, NULL, TRUE);
        $this->log("Created chat message $cm");

        # Mark the query as seen by one mod.
        $r->updateRoster($u3, $cm, ChatRoom::STATUS_ONLINE);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        # Notify mods; we don't notify user of our own by default, but we do mail the mod who has already seen it.
        $this->msgsSent = [];
        assertEquals(2, $r->notifyByEmail($id, ChatRoom::TYPE_USER2MOD, 0));
        assertEquals("Member conversation on testgroup with Test User 1 (test1@test.com)", $this->msgsSent[0]['subject']);
        assertNull($this->msgsSent[0]['groupid']);

        # Chase up mods after unreasonably short interval
        self::assertEquals(1, count($r->chaseupMods($id, 0)));

        # Fake mod reply
        list ($cm2, $banned) = $m->create($id, $u2, "Here's some help", ChatMessage::TYPE_DEFAULT, NULL, TRUE);

        # Notify user; this won't copy the mod who replied by default..
        $this->dbhm->preExec("UPDATE chat_roster SET lastemailed = NULL WHERE userid = ?;", [ $u1 ]);
        $this->msgsSent = [];
        assertEquals(2, $r->notifyByEmail($id, ChatRoom::TYPE_USER2MOD, 0));
        assertEquals("Your conversation with the testgroup volunteers", $this->msgsSent[0]['subject']);
        assertEquals($this->groupid, $this->msgsSent[0]['groupid']);
    }

    public function testNotifyUser2ModNonMember() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        $u1u = User::get($this->dbhr, $this->dbhm, $u1);
        $u2u = User::get($this->dbhr, $this->dbhm, $u2);
        $u2u->addMembership($this->groupid, User::ROLE_OWNER);

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $id = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $id for $u1 <-> group {$this->groupid}");
        assertNotNull($id);

        # Create a query from the user to the mods
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Help me", ChatMessage::TYPE_DEFAULT, NULL, TRUE);
        $this->log("Created chat message $cm");

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        # Notify mods; we don't notify user of our own by default, but we do mail the mod.
        $this->msgsSent = [];
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2MOD, 0));
        assertEquals("Member conversation on testgroup with Test User 1 (test1@test.com)", $this->msgsSent[0]['subject']);
        assertNull($this->msgsSent[0]['groupid']);
    }

    public function testNotifyUser2ModEmailsOff() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        $u1u = User::get($this->dbhr, $this->dbhm, $u1);

        $u1u->setSetting('notifications', [
            User::NOTIFS_EMAIL => FALSE
        ]);

        $u2u = User::get($this->dbhr, $this->dbhm, $u2);
        $u2u->addMembership($this->groupid, User::ROLE_OWNER);

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $id = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $id for $u1 <-> group {$this->groupid}");
        assertNotNull($id);

        # Create a query from the user to the mods
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Help me", ChatMessage::TYPE_DEFAULT, NULL, TRUE);
        $this->log("Created chat message $cm");

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        # Notify mods; we don't notify user of our own by default, but we do mail the mod.
        $this->msgsSent = [];
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2MOD, 0));
        assertEquals("Member conversation on testgroup with Test User 1 (test1@test.com)", $this->msgsSent[0]['subject']);
        assertNull($this->msgsSent[0]['groupid']);
    }

    public function testNotifyUser2ModReviewRequired() {
        $this->log(__METHOD__ );

        # Set up a chatroom
        $u = User::get($this->dbhr, $this->dbhm);
        $u1 = $u->create(NULL, NULL, "Test User 1");
        $u->addEmail('test1@test.com');
        $u->addEmail('test1@' . USER_DOMAIN);
        $u2 = $u->create(NULL, NULL, "Test User 2");
        $u->addEmail('test2@test.com');
        $u->addEmail('test2@' . USER_DOMAIN);

        $u1u = User::get($this->dbhr, $this->dbhm, $u1);

        $u1u->setSetting('notifications', [
            User::NOTIFS_EMAIL => FALSE
        ]);

        $u2u = User::get($this->dbhr, $this->dbhm, $u2);
        $u2u->addMembership($this->groupid, User::ROLE_OWNER);

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $id = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $id for $u1 <-> group {$this->groupid}");
        assertNotNull($id);

        # Create a query from the user to the mods
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Help me", ChatMessage::TYPE_DEFAULT, NULL, TRUE);
        $m->setPrivate('reviewrequired', 1);
        $this->log("Created chat message $cm");

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        # Notify mods; we don't notify user of our own by default, but we do mail the mod.
        $this->msgsSent = [];
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2MOD, 0));
        assertEquals("Member conversation on testgroup with Test User 1 (test1@test.com)", $this->msgsSent[0]['subject']);
        assertNull($this->msgsSent[0]['groupid']);
    }

    public function testEmojiSplit()
    {
        $r = new ChatRoom($this->dbhr, $this->dbhm);

        self::assertEquals('Test', $r->splitEmoji('Test'));
        self::assertEquals('\\u1f923\\u', $r->splitEmoji('\\u1f923\\u'));
        self::assertEquals('Test', $r->splitEmoji('Test\\u1f923\\u'));
        self::assertEquals('Test', $r->splitEmoji('\\u1f923\\uTest'));

        }

    public function testBlock() {
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

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);

        assertNull($r->replyTime($u1));
        assertNull($r->replyTime($u2));

        # Make the first user block the second.
        $r->updateRoster($u1, NULL, ChatRoom::STATUS_BLOCKED);

        # Chat shouldn't show in the list for this user now.
        assertNull($r->listForUser(Session::modtools(), $u1, NULL, NULL));
        self::assertEquals(1, count($r->listForUser(Session::modtools(), $u2, NULL, NULL)));

        # Mow send a message from the second to the first.
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $m->create($id, $u2, "Test");

        # Check that this message doesn't get notified.
        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Notify - will email none
        $this->log("Will email none");
        assertEquals(0, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0));

        # Chat still shouldn't show in the list for this user.
        assertNull($r->listForUser(FALSE, $u1, NULL, NULL, FALSE));
        self::assertEquals(1, count($r->listForUser(FALSE, $u2, NULL, NULL, FALSE)));
     }

    public function testReadReceipt() {
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

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createConversation($u1, $u2);
        $this->log("Chat room $id for $u1 <-> $u2");
        assertNotNull($id);

        assertNull($r->replyTime($u1));
        assertNull($r->replyTime($u2));

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($id, $u1, "Testing", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->log("Created chat message $cm");

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Notify - will email just one as we don't notify our own by default.
        $this->log("Will email justone");
        assertEquals(1, $r->notifyByEmail($id, ChatRoom::TYPE_USER2USER, 0));

        # Now fake a read receipt.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/notif_reply_text'));
        $mr = new MailRouter($this->dbhm, $this->dbhm);
        $mid = $mr->received(Message::EMAIL, 'from2@test.com', "readreceipt-$id-$u2-$cm@" . USER_DOMAIN, $msg);
        $rc = $mr->route();
        assertEquals(MailRouter::RECEIPT, $rc);

        # Should have updated the last message seen.
        self::assertEquals($r->lastSeenForUser($u2), $cm);
    }

    public function testSplitQuote() {
        $r = new ChatRoom($this->dbhr, $this->dbhm);

        assertEquals("> Testing", $r->splitAndQuote("Testing"));
        assertEquals("> Testing", $r->splitAndQuote("Testing\r\n"));
        assertEquals("> Testing Testing Testing Testing Testing Testing Testing\r\n> Testing Testing Testing Testing Testing Testing Testing\r\n> Testing Testing Testing Testing", $r->splitAndQuote("Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing Testing"));
        assertEquals("> TestingTestingTestingTestingTestingTestingTestingTestingTest\r\n> ingTestingTestingTestingTestingTestingTestingTestingTestingT\r\n> esting", $r->splitAndQuote("TestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTestingTesting"));
    }

    public function testInvalidId() {
        $r = new ChatRoom($this->dbhr, $this->dbhm, -1);
        assertEquals(NULL, $r->getId());
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
        $id = $r->createConversation($uid1, $uid2);
        assertNull($id);
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
        $id = $r->createConversation($uid1, $uid2);
        assertNotNull($id);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $_SESSION['id'] = $uid3;
        assertTrue($r->canSee($uid3, FALSE));
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
        $id = $r->createConversation($uid1, $uid2);
        assertNotNull($id);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $_SESSION['id'] = $uid3;
        assertFalse($r->canSee($uid3, FALSE));
        assertTrue($r->canSee($uid3, TRUE));

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createUser2Mod($uid1, $this->groupid);
        assertNotNull($id);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $id);
        $_SESSION['id'] = $uid3;
        assertFalse($r->canSee($uid2, FALSE));
        assertTrue($r->canSee($uid3, TRUE));
    }

    public function testNotifyModMailEmailsTurnedOff() {
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

        # Turn off mails to u1.
        $u = new User($this->dbhr, $this->dbhm, $u1);
        $u->setPrivate('onholidaytill', Utils::ISODate('@' . strtotime('tomorrow')));

        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $r->createUser2Mod($u1, $this->groupid);
        $this->log("Chat room $rid for $u1 <-> $u2");
        assertNotNull($rid);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm2, $banned) = $m->create($rid, $u2, "Here's some help", ChatMessage::TYPE_MODMAIL, NULL, TRUE);

        $r = $this->getMockBuilder('Freegle\Iznik\ChatRoom')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, NULL))
            ->setMethods(array('mailer'))
            ->getMock();

        $r->method('mailer')->will($this->returnCallback(function($message) {
            return($this->mailer($message));
        }));

        $this->msgsSent = [];

        # Should send even though mails turned off.
        $r->notifyByEmail($rid, ChatRoom::TYPE_USER2MOD);
        assertEquals(1, count($this->msgsSent));
    }
}



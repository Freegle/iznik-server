<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/chat/ChatRoom.php';
require_once IZNIK_BASE . '/include/chat/ChatMessage.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class chatMessagesTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");
        $users = $dbhr->preQuery("SELECT userid FROM users_emails WHERE email = 'from2@test.com'");
        foreach ($users as $user) {
            $dbhm->preExec("DELETE FROM users WHERE id = ?;", [ $user['userid']]);
        }


        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
    }

    public function testGroup() {
        error_log(__METHOD__);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createGroupChat('test', $this->groupid);
        assertNotNull($id);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $mid = $m->create($id, $this->uid, 'Test');
        assertNotNull($mid);

        $atts = $m->getPublic();
        assertEquals($id, $atts['chatid']);
        assertEquals('Test', $atts['message']);
        assertEquals($this->uid, $atts['userid']);

        $mid2 = $m->create($id, $this->uid, 'Test2');
        assertNotNull($mid2);
        list($msgs, $users) = $r->getMessages();
        assertEquals(2, count($msgs));
        error_log("Msgs " . var_export($msgs, TRUE));
        assertTrue($msgs[0]['sameasnext']);
        assertTrue($msgs[1]['sameaslast']);

        assertEquals(1, $m->delete());
        assertEquals(1, $r->delete());

        error_log(__METHOD__ . " end");
    }

    public function testSpamReply() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam.
        error_log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        # Check got flagged.
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid IN (SELECT userid FROM users_emails WHERE email = 'from2@test.com');");
        assertEquals(1, $msgs[0]['reviewrequired']);

        error_log(__METHOD__ . " end");
    }

    public function testSpamReply2() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam.
        error_log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        # Check got flagged.
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid IN (SELECT userid FROM users_emails WHERE email = 'from2@test.com');");
        assertEquals(1, $msgs[0]['reviewrequired']);

        error_log(__METHOD__ . " end");
    }

    public function testReplyFromSpammer() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Now create a sender on the spammer list.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Spam', 'User', 'Spam User');
        $u->addEmail('test2@test.com');
        $s = new Spam($this->dbhr, $this->dbhm);
        $s->addSpammer($uid, Spam::TYPE_SPAMMER, 'UT Test');

        # Now reply from them.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: a test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $replyid = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        error_log(__METHOD__ . " end");
    }

    public function testStripOurFooter() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test2@test.com');

        # Now reply from them.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/ourfooter'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: a test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $replyid = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $replyid);
        $uid = $u->findByEmail('test@test.com');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rooms = $r->listForUser($uid);
        self::assertEquals(1, count($rooms));
        $rid = $rooms[0];
        assertNotNull($rid);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
        $msgs = $r->getMessages();
        self::assertEquals('I\'d like to have these, then I can return them to Greece where they rightfully belong.', $msgs[0][0]['message']);

        error_log(__METHOD__ . " end");
    }

    public function testSpamReply4() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam spoof.
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
        $email = $u->inventEmail();
        $u->addEmail($email, FALSE, FALSE);

        error_log("Reply with to self $email");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, $email, $email, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        error_log(__METHOD__ . " end");
    }

    public function testSpamReply3() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam that is marked as to be junked (weight loss in spam_keywords).
        error_log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply3'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, 'spammer@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        error_log(__METHOD__ . " end");
    }

    public function testPairing() {
        error_log(__METHOD__);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('OFFER: a test item (location)', 'OFFER: A spade and broom handle (Conniburrow MK14', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid1 = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('OFFER: a test item (location)', 'Wanted: bike (Conniburrow MK14', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid2 = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: A spade and broom handle (Conniburrow MK14)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhm, $this->dbhm, $refmsgid1);
        $atts = $m->getPublic(FALSE, TRUE, TRUE);
        error_log("Message 1 " . var_export($atts, TRUE));
        assertEquals(1, count($atts['replies']));
        $m = new Message($this->dbhm, $this->dbhm, $refmsgid2);
        $atts = $m->getPublic(FALSE, TRUE, TRUE);
        assertEquals(0, count($atts['replies']));

        error_log(__METHOD__ . " end");
    }

    public function testError() {
        error_log(__METHOD__);

        $dbconfig = array (
            'host' => SQLHOST,
            'port_read' => SQLPORT_READ,
            'port_mod' => SQLPORT_MOD,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('LoggedPDO')
            ->setConstructorArgs([
                "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8",
                $dbconfig['user'], $dbconfig['pass'], array(), TRUE
            ])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new Exception());
        $m->setDbhm($mock);

        $mid = $m->create(NULL, $this->uid, 'Test');
        assertNull($mid);

        error_log(__METHOD__ . " end");
    }

    public function testCheckReview() {
        error_log(__METHOD__);

        $m = new ChatMessage($this->dbhr, $this->dbhm);

        assertTrue($m->checkReview(''));

        # Fine
        assertFalse($m->checkReview('Nothing to see here'));

        # Spam
        assertTrue($m->checkReview('https://spam'));
        assertTrue($m->checkReview('http://spam'));

        # Valid
        assertFalse($m->checkReview('http://' . USER_DOMAIN));
        assertFalse($m->checkReview('http://freegle.in'));

        # Mixed urls, one valid one not.
        assertTrue($m->checkReview("http://" . USER_DOMAIN . "\r\nhttps://spam.com"));

        # Others.
        assertTrue($m->checkReview('<script'));

        # Keywords
        assertTrue($m->checkReview('spamspamspam'));

        # Money
        assertTrue($m->checkReview("£100"));

        assertTrue($m->checkReview('No word boundary:http://spam'));

        # Porn
        assertTrue($m->checkReview('http://spam&#12290;ru'));

        # Email
        assertTrue($m->checkReview("Test\r\nTest email@domain.com\r\n"));
        assertFalse($m->checkReview("Test\r\nTest email@" . USER_DOMAIN . " email\r\n"));

        # French
        assertFalse($m->checkReview("Suzanne et Joseph étaient nés dans les deux premières années de leur arrivée à la colonie. Après la naissance de Suzanne, la mère abandonna l’enseignement d’état. Elle ne donna plus que des leçons particulières de français. Son mari avait été nommé directeur d’une école indigène et, disaient-elle, ils avaient vécu très largement malgré la charge de leurs enfants. Ces années-là furent sans conteste les meilleures de sa vie, des années de bonheur. Du moins c’étaient ce qu’elle disait. Elle s’en souvenait comme d’une terre lointaine et rêvée, d’une île. Elle en parlait de moins en moins à mesure qu’elle vieillissait, mais quand elle en parlait c’était toujours avec le même acharnement. Alors, à chaque fois, elle découvrait pour eux de nouvelles perfections à cette perfection, une nouvelle qualité à son mari, un nouvel aspect de l’aisance qu’ils connaissaient alors, et qui tendaient à devenir une opulence dont Joseph et Suzanne doutaient un peu."));
        assertTrue($m->checkReview("Suzanne et Joseph étaient nés dans les deux premières années de leur arrivée à la colonie. Après la naissance de Suzanne, la mère abandonna l’enseignement d’état. Elle ne donna plus que des leçons particulières de français. Son mari avait été nommé directeur d’une école indigène et, disaient-elle, ils avaient vécu très largement malgré la charge de leurs enfants. Ces années-là furent sans conteste les meilleures de sa vie, des années de bonheur. Du moins c’étaient ce qu’elle disait. Elle s’en souvenait comme d’une terre lointaine et rêvée, d’une île. Elle en parlait de moins en moins à mesure qu’elle vieillissait, mais quand elle en parlait c’était toujours avec le même acharnement. Alors, à chaque fois, elle découvrait pour eux de nouvelles perfections à cette perfection, une nouvelle qualité à son mari, un nouvel aspect de l’aisance qu’ils connaissaient alors, et qui tendaient à devenir une opulence dont Joseph et Suzanne doutaient un peu.", TRUE));

        # Butt (spam) and Water butt (not spam)
        assertTrue($m->checkReview("Something butt-related"));
        assertFalse($m->checkReview("Innocent water butt"));
        assertFalse($m->checkReview("something in butt rd"));

        error_log(__METHOD__ . " end");
    }

    public function testCheckSpam() {
        error_log(__METHOD__);

        $m = new ChatMessage($this->dbhr, $this->dbhm);

        # Keywords
        assertTrue($m->checkSpam('viagra'));
        assertTrue($m->checkSpam('weight loss'));

        # Domain
        if (!getenv('STANDALONE')) {
            # TODO This doesn't work on Travis because Spamhaus doesn't let you test when you're using a
            # general public DNS server.
            assertTrue($m->checkSpam("TEst message which includes http://dbltest.com which is blocked."));
        }

        error_log(__METHOD__ . " end");
    }

    public function testReferToSpammer() {
        error_log(__METHOD__);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $u->addEmail($email);

        $this->dbhm->preExec("INSERT INTO spam_users (userid, collection, reason) VALUES (?, ?, ?);", [
            $uid,
            Spam::TYPE_SPAMMER,
            'UT Test'
        ]);

        $m = new ChatMessage($this->dbhr, $this->dbhm);

        # Keywords
        assertTrue($m->checkReview("Please reply to $email"));

        error_log(__METHOD__ . " end");
    }

    public function testReplyWithAttachment() {
        error_log(__METHOD__);

        # Put a valid message on a group.
        error_log("Put valid message on");
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::YAHOO_APPROVED, 'test@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $fromuid = $m->getFromuser();
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with an attachment.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replyimage'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $replyid = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $replyid);
        $atts = $m->getAttachments();

        # Notification payload for recipient.
        $u = new User($this->dbhr, $this->dbhm, $fromuid);
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route) = $u->getNotificationPayload(FALSE);
        assertEquals(1, $total);
        assertEquals("You have 1 notification", $title);

        # Expect one of these to have been stripped as too small
        self::assertEquals(1, count($atts));
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $replyid);
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rooms = $r->listForUser($uid);
        self::assertEquals(1, count($rooms));
        $rid = $rooms[0];
        assertNotNull($rid);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
        $msgs = $r->getMessages();
        self::assertEquals(2, count($msgs));
        error_log("Chat messages " . var_export($msgs, TRUE));
        self::assertEquals('', $msgs[0][0]['message']);

        # Should be an image in the second one.
        assertTrue(array_key_exists('image', $msgs[0][0]));

        error_log(__METHOD__ . " end");
    }

    public function testUser2ModSpam() {
        error_log(__METHOD__);
        $gid = $this->groupid;
        error_log("Created group $gid");
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create("Test", "User", "Test User");
        $u->addMembership($gid, User::ROLE_MODERATOR);
        $uid2 = $u->create("Test", "User", "Test User");
        $u->addMembership($gid);
        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $r->createUser2Mod($uid2, $gid);

        # Add a spammy chat message
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $mid = $m->create($rid, $uid2, "<script");
        $m = new ChatMessage($this->dbhr, $this->dbhm, $mid);
        $atts = $m->getPublic();

        # Should be unseen by mod even though spam.
        self::assertEquals(1, $r->unseenCountForUser($uid1));

        error_log(__METHOD__ . " end");
    }
}



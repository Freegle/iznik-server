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
class chatMessagesTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
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
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);

        $this->user->addMembership($this->groupid);
        $this->user->addEmail('test@test.com');
        $this->user->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);
    }

    public function testGroup() {
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $id = $r->createGroupChat('test', $this->groupid);
        $this->assertNotNull($id);

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $m->create($id, $this->uid, 'Test');
        $this->assertNotNull($mid);

        $atts = $m->getPublic();
        $this->assertEquals($id, $atts['chatid']);
        $this->assertEquals('Test', $atts['message']);
        $this->assertEquals($this->uid, $atts['userid']);

        list ($mid2, $banned) = $m->create($id, $this->uid, 'Test2');
        $this->assertNotNull($mid2);
        list($msgs, $users) = $r->getMessages();
        $this->assertEquals(2, count($msgs));
        $this->log("Msgs " . var_export($msgs, TRUE));
        $this->assertTrue($msgs[0]['sameasnext']);
        $this->assertTrue($msgs[1]['sameaslast']);

        $this->assertEquals(1, $m->delete());
        $this->assertEquals(1, $r->delete());

        }

    public function testSpamReply() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam.
        $this->log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Check got flagged.
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid IN (SELECT userid FROM users_emails WHERE email = 'from2@test.com');");
        $this->assertEquals(1, $msgs[0]['reviewrequired']);
    }

    public function testSpamReply2() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam.
        $this->log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Check got flagged.
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid IN (SELECT userid FROM users_emails WHERE email = 'from2@test.com');");
        $this->assertEquals(1, $msgs[0]['reviewrequired']);

        # Check review counts.
        $_SESSION['id'] = $this->uid;
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $this->assertEquals(1, $m->getReviewCount($this->user)['chatreview']);
        $this->assertEquals(0, $m->getReviewCount($this->user)['chatreviewother']);
        $this->user->setGroupSettings($this->groupid, [ 'active' => 0 ]);
        $this->assertEquals(0, $m->getReviewCount($this->user)['chatreview']);
        $this->assertEquals(1, $m->getReviewCount($this->user)['chatreviewother']);

        $g = new Group($this->dbhr, $this->dbhm);
        $gid2 = $g->create('testgroup1', Group::GROUP_UT);
        $this->user->addMembership($gid2, User::ROLE_MODERATOR);
        $this->assertEquals(0, $m->getReviewCount($this->user)['chatreview']);
        $this->assertEquals(1, $m->getReviewCount($this->user)['chatreviewother']);
    }

    public function testSpamReply5() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with something that isn't actually spam.
        $this->log("Reply with not spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply5'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Check not flagged.
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid IN (SELECT userid FROM users_emails WHERE email = 'from2@test.com');");
        $this->assertEquals(0, $msgs[0]['reviewrequired']);
    }

    public function testReplyFromSpammer() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now create a sender on the spammer list.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Spam', 'User', 'Spam User');
        $u->addEmail('test2@test.com');
        $s = new Spam($this->dbhr, $this->dbhm);
        $s->addSpammer($uid, Spam::TYPE_SPAMMER, 'UT Test');

        # Check they show as on the list, but only when we're a mod
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $this->assertEquals('boolean', gettype($atts['spammer']));
        $u2 = new User($this->dbhr, $this->dbhm);
        $uid2 = $u2->create('Test', 'User', 'Test User');
        $this->assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u2->login('testpw'));
        $u2->setPrivate('systemrole', User::ROLE_MODERATOR);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $this->assertTrue(array_key_exists('spammer', $atts));

        # Now reply from them.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: a test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $replyid = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testStripOurFooter() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test2@test.com');

        # Now reply from them.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/ourfooter'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: a test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $replyid = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $replyid);
        $uid = $u->findByEmail('test@test.com');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rooms = $r->listForUser(Session::modtools(), $uid);
        self::assertEquals(1, count($rooms));
        $rid = $rooms[0];
        $this->assertNotNull($rid);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
        $msgs = $r->getMessages();
        self::assertEquals('I\'d like to have these, then I can return them to Greece where they rightfully belong.', $msgs[0][0]['message']);

        }

    public function testSpamReply4() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam spoof.
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
        $email = $u->inventEmail();
        $u->addEmail($email, FALSE, FALSE);

        $this->log("Reply with to self $email");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, $email, $email, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

    }

    public function testSpamReply3() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam that is marked as to be junked (weight loss in spam_keywords).
        $this->log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply3'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'spammer@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
    }

    public function testSpamReply6() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('OFFER: a test item (location)', 'Testing', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
        $email = $u->inventEmail();
        $u->addEmail($email, FALSE, FALSE);

        # Send a reply direct to the user - should go to spam but marked for review, as this will fail Spam Assassin
        # via the GTUBE string.
        $this->log("Reply direct to $email");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply6'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'spammer@test.com', $email, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);
        $chatmessages = $this->dbhr->preQuery("SELECT chat_messages.id, chatid, reviewrequired, reviewrejected FROM chat_messages INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id WHERE chat_rooms.user2 = ?", [
            $m->getFromuser()
        ]);
        $this->assertEquals(1, count($chatmessages));
        $chatid = NULL;
        foreach ($chatmessages as $c) {
            $chatid = $c['chatid'];
            $this->assertEquals(1, $c['reviewrequired']);
            $this->dbhm->preExec("DELETE FROM chat_messages WHERE id = ?;", [
                $c['id']
            ]);
        }

        # Send a reply to a replyto email - ditto.
        $this->log("Reply to reply-to");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply6'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'spammer@test.com', "replyto-$refmsgid-" . $m->getFromuser() . '@' . USER_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);
        $chatmessages = $this->dbhr->preQuery("SELECT chat_messages.id, reviewrequired FROM chat_messages INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id WHERE chat_rooms.user2 = ?", [
            $m->getFromuser()
        ]);
        $this->assertEquals(1, count($chatmessages));
        foreach ($chatmessages as $c) {
            $this->assertEquals(1, $c['reviewrequired']);
            $this->dbhm->preExec("DELETE FROM chat_messages WHERE id = ?;", [
                $c['id']
            ]);
        }

        # Send a reply to a notify email - ditto.
        $this->log("Reply to reply-to");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply6'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'spammer@test.com', "notify-$chatid-" . $m->getFromuser() . '@' . USER_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);
        $chatmessages = $this->dbhr->preQuery("SELECT chat_messages.id, reviewrequired FROM chat_messages INNER JOIN chat_rooms ON chat_messages.chatid = chat_rooms.id WHERE chat_rooms.user2 = ?", [
            $m->getFromuser()
        ]);
        $this->assertEquals(1, count($chatmessages));
        $chatid = NULL;
        foreach ($chatmessages as $c) {
            $this->assertEquals(1, $c['reviewrequired']);
            $this->dbhm->preExec("DELETE FROM chat_messages WHERE id = ?;", [
                $c['id']
            ]);
        }
    }

    public function testSpamReply7() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam spoof.
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
        $email = $u->inventEmail();
        $u->addEmail($email, FALSE, FALSE);

        error_log("Spam reply.");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply7'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'spammer@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
    }

    public function testReplyJobSpam() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with spam that is marked as to be junked (weight loss in spam_keywords).
        $this->log("Reply with spam");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replyjobspam'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'spammer@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

    }

    public function testPairing() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('OFFER: a test item (location)', 'OFFER: A spade and broom handle (Conniburrow MK14', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid1, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('OFFER: a test item (location)', 'Wanted: bike (Conniburrow MK14', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid2, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: A spade and broom handle (Conniburrow MK14)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'from2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhm, $this->dbhm, $refmsgid1);

        # Can only see replies logged in.
        $fromu = $m->getFromuser();
        $u = new User($this->dbhr, $this->dbhm, $fromu);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $atts = $m->getPublic(FALSE, TRUE, TRUE);
        $this->log("Message 1 " . var_export($atts, TRUE));
        $this->assertEquals(1, count($atts['replies']));
        $m = new Message($this->dbhm, $this->dbhm, $refmsgid2);
        $atts = $m->getPublic(FALSE, TRUE, TRUE);
        $this->assertEquals(0, count($atts['replies']));
    }

    public function testError() {
        $dbconfig = array (
            'host' => SQLHOST,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([
                "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8",
                $dbconfig['user'], $dbconfig['pass'], array(), TRUE
            ])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());
        $m->setDbhm($mock);

        list ($mid, $banned) = $m->create(NULL, $this->uid, 'Test');
        $this->assertNull($mid);
    }

    public function testCheckReview() {
        $m = new ChatMessage($this->dbhr, $this->dbhm);

        # Fine
        $this->assertNull($m->checkReview('Nothing to see here'));

        # Spam
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview('https://spam'));
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview('http://spam'));

        # Valid
        $this->assertNull($m->checkReview('http://' . USER_DOMAIN));
        $this->assertNull($m->checkReview('http://freegle.in'));

        # Mixed urls, one valid one not.
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("http://" . USER_DOMAIN . "\r\nhttps://spam.com"));

        # Others.
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview('<script'));

        # Keywords
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview('spamspamspam'));

        # Money
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("£100"));

        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview('No word boundary:http://spam'));

        # Porn
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview('http://spam&#12290;ru'));

        # Email
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("Test\r\nTest email@domain.com\r\n"));
        $this->assertNull($m->checkReview("Test\r\nTest email@" . USER_DOMAIN . " email\r\n"));

        # French
        $this->assertNull($m->checkReview("Suzanne et Joseph étaient nés dans les deux premières années de leur arrivée à la colonie. Après la naissance de Suzanne, la mère abandonna l’enseignement d’état. Elle ne donna plus que des leçons particulières de français. Son mari avait été nommé directeur d’une école indigène et, disaient-elle, ils avaient vécu très largement malgré la charge de leurs enfants. Ces années-là furent sans conteste les meilleures de sa vie, des années de bonheur. Du moins c’étaient ce qu’elle disait. Elle s’en souvenait comme d’une terre lointaine et rêvée, d’une île. Elle en parlait de moins en moins à mesure qu’elle vieillissait, mais quand elle en parlait c’était toujours avec le même acharnement. Alors, à chaque fois, elle découvrait pour eux de nouvelles perfections à cette perfection, une nouvelle qualité à son mari, un nouvel aspect de l’aisance qu’ils connaissaient alors, et qui tendaient à devenir une opulence dont Joseph et Suzanne doutaient un peu."));
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("Suzanne et Joseph étaient nés dans les deux premières années de leur arrivée à la colonie. Après la naissance de Suzanne, la mère abandonna l’enseignement d’état. Elle ne donna plus que des leçons particulières de français. Son mari avait été nommé directeur d’une école indigène et, disaient-elle, ils avaient vécu très largement malgré la charge de leurs enfants. Ces années-là furent sans conteste les meilleures de sa vie, des années de bonheur. Du moins c’étaient ce qu’elle disait. Elle s’en souvenait comme d’une terre lointaine et rêvée, d’une île. Elle en parlait de moins en moins à mesure qu’elle vieillissait, mais quand elle en parlait c’était toujours avec le même acharnement. Alors, à chaque fois, elle découvrait pour eux de nouvelles perfections à cette perfection, une nouvelle qualité à son mari, un nouvel aspect de l’aisance qu’ils connaissaient alors, et qui tendaient à devenir une opulence dont Joseph et Suzanne doutaient un peu.", TRUE));

        # Butt (spam) and Water butt (not spam)
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("Something butt-related"));
        $this->assertNull($m->checkReview("Innocent water butt"));
        $this->assertNull($m->checkReview("something in butt rd"));
    }

    public function testCheckSpam() {
        $m = new ChatMessage($this->dbhr, $this->dbhm);

        # Keywords
        $this->assertEquals(Spam::REASON_KNOWN_KEYWORD, $m->checkSpam('viagra'));
        $this->assertEquals(Spam::REASON_KNOWN_KEYWORD, $m->checkSpam('weight loss'));

        # Domain
        if (!getenv('STANDALONE')) {
            # TODO This doesn't work on Travis because Spamhaus doesn't let you test when you're using a
            # general public DNS server.
            $this->assertEquals(Spam::REASON_DBL, $m->checkSpam("TEst message which includes http://evil.fakery." . GROUP_DOMAIN));
            $this->assertEquals(Spam::REASON_DBL, $m->checkSpam("TEst message which includes http://dbltest.com which is blocked."));
        }
    }

    public function testReferToSpammer() {
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
        $this->assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("Please reply to $email"));
    }

    public function testReplyWithAttachment() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $fromuid = $m->getFromuser();
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Now reply to it with an attachment.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replyimage'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($replyid, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $replyid);
        $atts = $m->getAttachments();

        # Notification payload - just usual intro
        $u = new User($this->dbhr, $this->dbhm, $fromuid);
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route) = $u->getNotificationPayload(FALSE);
        $this->log("Payload $title for $total");
        $this->assertEquals(1, $total);
        $this->assertEquals("Why not introduce yourself to other freeglers?  You'll get a better response.", $title);

        # Expect one of these to have been stripped as too small
        self::assertEquals(1, count($atts));
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $replyid);
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rooms = $r->listForUser(Session::modtools(), $uid);
        self::assertEquals(1, count($rooms));
        $rid = $rooms[0];
        $this->assertNotNull($rid);
        $r = new ChatRoom($this->dbhr, $this->dbhm, $rid);
        $msgs = $r->getMessages();
        self::assertEquals(2, count($msgs));
        $this->log("Chat messages " . var_export($msgs, TRUE));
        self::assertEquals('Hi there', $msgs[0][0]['message']);

        # Should be an image in the second one.
        $this->assertTrue(array_key_exists('image', $msgs[0][1]));

    }

    public function testUser2ModSpam() {
        $gid = $this->groupid;
        $this->log("Created group $gid");
        $u = new User($this->dbhr, $this->dbhm);
        $uid1 = $u->create("Test", "User", "Test User");
        $u->addMembership($gid, User::ROLE_MODERATOR);
        $uid2 = $u->create("Test", "User", "Test User");
        $u->addMembership($gid);
        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $r->createUser2Mod($uid2, $gid);

        # Add a spammy chat message
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $m->create($rid, $uid2, "<script");
        $m = new ChatMessage($this->dbhr, $this->dbhm, $mid);
        $atts = $m->getPublic();

        # Should be unseen by mod even though spam.
        self::assertEquals(1, $r->unseenCountForUser($uid1));
    }

    public function testWidespreadReplies() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_replace('OFFER: a test item (location)', 'OFFER: spade', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid1, $failok) = $r->received(Message::EMAIL, 'test1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_replace('OFFER: a test item (location)', 'OFFER: fork', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid2, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Create two messages very far apart but still within walking distance.
        $m1 = new Message($this->dbhr, $this->dbhm, $refmsgid1);
        $m2 = new Message($this->dbhr, $this->dbhm, $refmsgid2);
        $m1->setPrivate('lat', 50.0657);
        $m1->setPrivate('lng', -5.7132);
        $m2->setPrivate('lat', 58.6373);
        $m2->setPrivate('lng', -3.0689);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: spade (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($replyid1, $failok) = $r->received(Message::EMAIL, 'from2@test.com', $m1->getFromaddr(), $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: fork (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($replyid2, $failok) = $r->received(Message::EMAIL, 'from2@test.com', $m2->getFromaddr(), $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $replyid1);
        $uid = $m->getFromuser();

        $review = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM chat_messages WHERE userid = ? AND reviewrequired = 1;", [
            $uid
        ]);
        $this->assertEquals(1, $review[0]['count']);
    }

    public function testBannedInCommon() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u->addMembership($this->groupid);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $banned) = $r->createConversation($this->uid, $uid2);
        $this->assertNotNull($rid);
        $this->assertFalse($banned);

        # Send message from one to the other - should work.
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $m->create($rid, $this->uid, 'Test');
        $this->assertNotNull($mid);

        # Ban the sender on the sole group they have in common.
        $this->user->removeMembership($this->groupid, TRUE);

        # Shouldn't be able to message.
        list ($mid, $banned) = $m->create($rid, $this->uid, 'Test');
        $this->assertTrue($banned);
        $this->assertNull($mid);

        # ...or even start the conversation.
        $r->delete();
        list ($rid, $banned) = $r->createConversation($this->uid, $uid2);
        $this->assertNull($rid);
        $this->assertTrue($banned);
    }

    public function testAttachmentSentManyTimes() {
        # Put a valid message on a group.
        $this->log("Put valid message on");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($refmsgid, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        for ($i = 0; $i < Spam::IMAGE_THRESHOLD + 1; $i++) {
            # Now reply to it with an attachment.
            $msg2 = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replyimage'));
            $r2 = new MailRouter($this->dbhr, $this->dbhm);
            list ($replyid, $failok) = $r2->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg2);
            $this->assertNotNull($replyid);
            $rc = $r2->route();
            $this->assertEquals(MailRouter::TO_USER, $rc);

            $cmsgs = $this->dbhr->preQuery("SELECT * FROM chat_messages ORDER BY id DESC LIMIT 1;");
            $this->assertNotNull($cmsgs[0]['id']);
            $cm = new ChatMessage($this->dbhr, $this->dbhm, $cmsgs[0]['id']);

            if ($i < Spam::IMAGE_THRESHOLD) {
                $this->assertEquals(0, $cm->getPrivate('reviewrequired'));
            } else {
                $this->assertEquals(1, $cm->getPrivate('reviewrequired'));
                $this->assertEquals(Spam::REASON_IMAGE_SENT_MANY_TIMES, $cm->getPrivate('reportreason'));
            }
        }
    }
}



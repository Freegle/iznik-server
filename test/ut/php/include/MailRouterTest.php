<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/message/Message.php';
require_once IZNIK_BASE . '/include/message/WorryWords.php';
require_once IZNIK_BASE . '/include/misc/plugin.php';
require_once IZNIK_BASE . '/include/chat/ChatRoom.php';
require_once IZNIK_BASE . '/include/chat/ChatMessage.php';
require_once IZNIK_BASE . '/include/group/Group.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class MailRouterTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        # Whitelist this IP
        $this->dbhm->preExec("INSERT IGNORE INTO spam_whitelist_ips (ip, comment) VALUES ('1.2.3.4', 'UT whitelist');", []);

        # Tidy test subjects
        $this->dbhm->preExec("DELETE FROM spam_whitelist_subjects WHERE subject LIKE 'Test spam subject%';");
        $this->dbhm->preExec("DELETE FROM worrywords WHERE keyword LIKE 'UTtest%';");

        # Delete any UT playground messages
        $g = Group::get($dbhr, $dbhm);
        $gid = $g->findByShortName('FreeglePlayground');
        $sql = "DELETE FROM messages_groups WHERE groupid = $gid AND yahooapprovedid < 500;";
        $this->dbhm->preExec($sql);
    }

    protected function tearDown() {
        parent::tearDown ();

        $this->dbhm->preExec("DELETE FROM spam_whitelist_ips WHERE ip = '1.2.3.4';", []);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip = '1.2.3.4';", []);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip = '4.3.2.1';", []);
    }

    public function testHam() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->setPrivate('yahooUserId', -1);
        assertGreaterThan(0, $u->addEmail('test@test.com'));

        # Create a different user which will cause a merge.
        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u2 = User::get($this->dbhr, $this->dbhm, $uid2);
        $u2->setPrivate('yahooUserId', -time());
        assertGreaterThan(0, $u->addEmail('test2@test.com'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("X-Yahoo-Group-Post: member; u=420816297", "X-Yahoo-Group-Post: member; u=-1", $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($uid, $m->getFromuser());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/fromyahoo'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('Yahoo-Web', $m->getSourceheader());
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Test group override
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/fromyahoo'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('Yahoo-Web', $m->getSourceheader());
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $groups = $m->getGroups();
        $this->log("Groups " . var_export($groups, true));
        assertEquals($gid, $groups[0]);
        assertTrue($m->isApproved($gid));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($uid, $m->getFromuser());

        }

    public function testHamNoGroup() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("freegleplayground@yahoogroups.com", "nogroup@yahoogroups.com", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNull($id);

        }

    public function testConfirmMod() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/confirmmod_real');

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);

        # Try with an invalid from
        $this->log("Invalid key");
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "wibble-$gid-88-hmnXWqaGKir0fNTXgveSuj7ULOn44SEm@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        # Try with an invalid key
        $this->log("Invalid key");
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "modconfirm-$gid-88-hmnXWqaGKir0fNTXgveSuj7ULOn44SEm@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        # Try with a valid key but not a valid user
        $this->log("Invalid user");
        $key = $g->getConfirmKey();
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "modconfirm-$gid-0-$key@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        # A user who is not a member
        $this->log("Not member");
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $key = $g->getConfirmKey();
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "modconfirm-$gid-$uid-$key@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        # A user who is a member
        $this->log("Already member");
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($gid, User::ROLE_MEMBER);
        $key = $g->getConfirmKey();
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "modconfirm-$gid-$uid-$key@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        assertEquals(User::ROLE_MODERATOR, $u->getRoleForGroup($gid));

        # A user who is an owner - shouldn't be demoted
        $this->log("Owner");
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($gid, User::ROLE_OWNER);
        $key = $g->getConfirmKey();
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "modconfirm-$gid-$uid-$key@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        assertEquals(User::ROLE_OWNER, $u->getRoleForGroup($gid));

        # Try a fake confirm
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/confirmmod_fake');
        $this->log("Fake confirm");
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::YAHOO_SYSTEM, NULL, "modconfirm-$gid-$uid-$key@iznik.modtools.org", $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testSpamNoGroup() {
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_replace("FreeglePlayground <freegleplayground@yahoogroups.com>", "Nowhere <nogroup@yahoogroups.com>", $msg);
        $id = $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

    }

    public function testSpamSubject() {
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $subj = "Test spam subject " . microtime();
        $groups = [];

        for ($i = 0; $i < Spam::SUBJECT_THRESHOLD + 2; $i++) {
            $g = Group::get($this->dbhr, $this->dbhm);
            $g->create("testgroup$i", Group::GROUP_REUSE);
            $groups[] = $g;

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_replace('Basic test', $subj, $msg);
            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;

            $msgid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
            $rc = $r->route();

            if ($i < Spam::SUBJECT_THRESHOLD - 1) {
                assertEquals(MailRouter::APPROVED, $rc);
            } else {
                assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }

        # Now mark the last subject as not spam.  Once we've done that, we should be able to route it ok.
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $m->notSpam();
        $msgid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        foreach ($groups as $group) {
            $group->delete();
        }
    }

    public function testSpam() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from1@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::YAHOO_APPROVED, $m->getSource());

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $spam = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('sender@example.net', $spam->getFromaddr());
        assertNull($spam->getFromIP());
        assertNull($spam->getFromhost());
        assertEquals(1, count($spam->getGroups()));
        assertEquals($id, $spam->getID());
        assertEquals(0, strpos($spam->getMessageID(), 'GTUBE1.1010101@example.net'));
        assertEquals(str_replace("\r\n", "\n", $msg), str_replace("\r\n", "\n", $spam->getMessage()));
        assertEquals(Message::YAHOO_APPROVED, $spam->getSource());
        assertEquals('from1@test.com', $spam->getEnvelopefrom());
        assertEquals('to@test.com', $spam->getEnvelopeto());
        assertNotNull($spam->getTextbody());
        assertNull($spam->getHtmlbody());
        assertEquals($spam->getSubject(), $spam->getHeader('subject'));
        assertEquals('freegleplayground@yahoogroups.com', $spam->getTo()[0]['address']);
        assertEquals('Sender', $spam->getFromname());
        assertTrue(strpos($spam->getSpamreason(), 'SpamAssassin flagged this as possible spam') !== FALSE);
        $spam->delete();

        }

    public function testMoreSpam() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamcam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route(NULL);
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # Force some code coverage for approvedby.
        $r->markApproved();
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $p = $m->getPublic();

        }

    public function testGreetingSpam() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/greetingsspam');
        $id = $r->received(Message::EMAIL, 'notify@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/greetingsspam2');
        $id = $r->received(Message::EMAIL, 'notify@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/greetingsspam3');
        $id = $r->received(Message::EMAIL, 'notify@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testReferToSpammer() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $u->addEmail($email);

        $this->dbhm->preExec("INSERT INTO spam_users (userid, collection, reason) VALUES (?, ?, ?);", [
            $uid,
            Spam::TYPE_SPAMMER,
            'UT Test'
        ]);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = str_replace('Hey', "Please reply to $email", $msg);

        $id = $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testSpamOverride() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::YAHOO_APPROVED, $m->getSource());

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route(NULL, TRUE);
        assertEquals(MailRouter::APPROVED, $rc);

        }

    public function testWhitelist() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_replace('Precedence: junk', 'X-Freegle-IP: 1.2.3.4', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from1@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testPending() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        
        $pend = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('test@test.com', $pend->getFromaddr());
        assertEquals('1.2.3.4', $pend->getFromIP());
        assertNull($pend->getFromhost());
        assertNotNull($pend->getGroups()[0]);
        assertEquals($id, $pend->getID());
        assertEquals(Message::YAHOO_PENDING, $pend->getSource());
        assertEquals('from@test.com', $pend->getEnvelopefrom());
        assertEquals('to@test.com', $pend->getEnvelopeto());
        assertNotNull($pend->getTextbody());
        assertNotNull($pend->getHtmlbody());
        assertEquals($pend->getSubject(), $pend->getHeader('subject'));
        assertEquals('freegleplayground@yahoogroups.com', $pend->getTo()[0]['address']);
        assertEquals('Test User', $pend->getFromname());
        $this->log("Delete $id from " . var_export($pend->getGroups(), true));
        $pend->delete(NULL, $pend->getGroups()[0]);

        }

    public function testAutoApprove() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setSettings([ 'autoapprove' => [ 'members' => 1 ]]);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approvemember'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();
        $this->log("Created $id");

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        # Should be in approved not pending.
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        assertEquals(0, count($membs));
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::APPROVED, [ $gid ]);
        assertEquals(1, count($membs));

        }

    function testPendingToApproved() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $this->log("First copy on $gid");

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        assertNotNull(new Message($this->dbhr, $this->dbhm, $id));
        $this->log("Pending id $id");

        # Approve
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($gid, User::ROLE_OWNER);
        assertTrue($u->login('testpw'));

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($gid, NULL, NULL, NULL);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id2 = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);

        # We should recognise the approved version as the same message.
        assertEquals($id, $id2);

        # The approvedby should be preserved
        $m = new Message($this->dbhr, $this->dbhm, $id2);
        $groups = $m->getPublic()['groups'];
        $this->log("Groups " . var_export($groups, TRUE));
        assertEquals($uid, $groups[0]['approvedby']['id']);

        # Now the same, but with a TN post which has no messageid.
        $this->log("Now TN post");
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        assertEquals('20065945', $m->getTnpostid());
        assertEquals('TN-email', $m->getSourceheader());
        list($id, $already) = $m->save();
        $this->log("Saved $id");

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        assertNotNull(new Message($this->dbhr, $this->dbhm, $id));
        $this->log("Pending id $id");

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id2 = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertEquals($id, $id2);

        }

    function testTNSpamToApproved() {
        # Force a TN message to spam
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from1@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(true);
        $mock->result['SCORE'] = 100;
        $r->setSpamc($mock);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);
        assertNotNull(new Spam($this->dbhr, $this->dbhm, $id));
        $this->log("Spam id $id");

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $r = new MailRouter($this->dbhr, $this->dbhm);

        $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        }

    function testDelayedPending() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $this->log("First copy on $gid");

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);

        # Send to approved first.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id1 = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $r->route();

        $m = new Message($this->dbhr, $this->dbhm, $id1);
        $this->log(var_export($m->getPublic(), TRUE));
        assertEquals(MessageCollection::APPROVED, $m->getPublic()['groups'][0]['collection']);

        # Now to pending, which is possible Yahoo is slow.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id2 = $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $r->route();

        assertEquals($id1, $id2);
        $m = new Message($this->dbhr, $this->dbhm, $id1);
        $this->log(var_export($m->getPublic(), TRUE));
        assertEquals(MessageCollection::APPROVED, $m->getPublic()['groups'][0]['collection']);
//        $m->delete("UT");
//
//        $p = new Plugin($this->dbhr, $this->dbhm);
//        $work = $p->get($gid);
//        $this->log("Work " . var_export($work, TRUE));
//        $data = json_decode($work[0]['data'], TRUE);
//        assertEquals('DeletePendingMessage', $data['type']);

        }

    public function testSpamIP() {
        # Sorry, Cameroon folk.
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/cameroon');
        $msg = str_replace('freegleplayground@yahoogroups.com', 'b.com', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # This should have stored the IP in the message.
        $m = new Message($this->dbhm, $this->dbhm, $id);
        assertEquals('41.205.16.153', $m->getFromIP());

        }

    public function testFailSpam() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');

        # Make the attempt to mark as spam fail.
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('markAsSpam'))
            ->getMock();
        $r->method('markAsSpam')->willReturn(false);

        $r->received(Message::YAHOO_APPROVED, 'from1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        # Make the spamc check itself fail - should still go through.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(false);
        $r->setSpamc($mock);

        $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Make the geo lookup throw an exception, which it does for unknown IPs
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('X-Originating-IP: 1.2.3.4', 'X-Originating-IP: 238.162.112.228', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);

        $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        }

    public function testFailHam() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

        # Make the attempt to mark the message as approved
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('markApproved', 'markPending'))
            ->getMock();
        $r->method('markApproved')->willReturn(false);
        $r->method('markPending')->willReturn(false);

        $this->log("Expect markApproved fail");
        $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        # Make the spamc check itself fail - should still go through.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(false);
        $r->setSpamc($mock);

        $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        }

    public function testMultipleUsers() {
        for ($i = 0; $i < Spam::USER_THRESHOLD + 2; $i++) {
            $this->log("User $i");

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_replace(
                'From: "Test User" <test@test.com>',
                'From: "Test User ' . $i . '" <test' . $i . '@test.com>',
                $msg);
            $msg = str_replace('1.2.3.4', '4.3.2.1', $msg);
            $msg = str_replace('X-Yahoo-Group-Post: member; u=420816297', 'X-Yahoo-Group-Post: member; u=' . $i, $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
            $rc = $r->route();

            if ($i < Spam::USER_THRESHOLD) {
                assertEquals(MailRouter::APPROVED, $rc);
            } else {
                assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }

        }

    public function testMultipleSubjects() {
        $this->dbhm->exec("INSERT IGNORE INTO spam_whitelist_subjects (subject, comment) VALUES ('Basic test', 'For UT');");

        # Our subject is whitelisted and therefore should go through ok
        for ($i = 0; $i < Spam::SUBJECT_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            $g = Group::get($this->dbhr, $this->dbhm);
            $g->create("testgroup$i", Group::GROUP_REUSE);

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_replace(
                'To: "freegleplayground@yahoogroups.com" <freegleplayground@yahoogroups.com>',
                'To: "testgroup' . $i . '@yahoogroups.com" <testgroup' . $i . '@yahoogroups.com>',
                $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
            $rc = $r->route();

            assertEquals(MailRouter::APPROVED, $rc);
        }

        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip LIKE ?;", ['4.3.2.%']);

        # Now try with a non-whitelisted subject
        for ($i = 0; $i < Spam::SUBJECT_THRESHOLD + 2; $i++) {
            $this->log("Group $i");

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_replace('Subject: Basic test', 'Subject: Modified subject', $msg);
            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
            $rc = $r->route();

            if ($i + 1 < Spam::SUBJECT_THRESHOLD) {
                assertEquals(MailRouter::APPROVED, $rc);
            } else {
                assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }

        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip LIKE ?;", ['4.3.2.%']);

        }

    public function testMultipleGroups() {
        $g = Group::get($this->dbhr, $this->dbhm);

        for ($i = 0; $i < Spam::GROUP_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            $gid = $g->create("testgroup$i", Group::GROUP_OTHER);

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;
            $msg = str_replace('1.2.3.4', '4.3.2.1', $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
            $this->log("Msg $id");
            $rc = $r->route();

            # The user will get marked as suspect.
            if ($i < Spam::SEEN_THRESHOLD) {
                $work = $g->getWorkCounts([
                    $gid => [
                        'active' => TRUE
                    ]
                ], [ $gid ]);

                assertEquals(0, $work[$gid]['spammembers']);
                assertEquals(0, $work[$gid]['spammembersother']);
            } else {

                $work = $g->getWorkCounts([
                    $gid => [
                        'active' => TRUE
                    ]
                ], [ $gid ]);

                assertEquals(1, $work[$gid]['spammembers']);
                assertEquals(0, $work[$gid]['spammembersother']);

                $work = $g->getWorkCounts([
                    $gid => [
                        'active' => FALSE
                    ]
                ], [ $gid ]);

                assertEquals(0, $work[$gid]['spammembers']);
                assertEquals(1, $work[$gid]['spammembersother']);
            }

            # The message can get marked as spam.
            if ($i < Spam::GROUP_THRESHOLD - 1) {
                assertEquals(MailRouter::APPROVED, $rc);
            } else {
                assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }

            # Should also show in work.
        }

    }

    public function testBulkOwnerMail() {
        $g = Group::get($this->dbhr, $this->dbhm);

        for ($i = 0; $i < Spam::GROUP_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            $gid = $g->create("testgroup$i", Group::GROUP_OTHER);

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $id = $r->received(Message::EMAIL, 'from@test.com', "testgroup$i-volunteers@" . GROUP_DOMAIN, $msg);
            $this->log("Msg $id");
            $rc = $r->route();

            # The message can get marked as spam.
            if ($i < Spam::GROUP_THRESHOLD - 1) {
                assertEquals(MailRouter::TO_VOLUNTEERS, $rc);
            } else {
                assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }
    }

    function testRouteAll() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($m->getGroupId());
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->routeAll();

        # Force exception
        $this->log("Now force exception");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $m->save();

        $mock = $this->getMockBuilder('LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec', 'rollBack', 'beginTransaction'))
            ->getMock();
        $mock->method('preExec')->will($this->throwException(new Exception()));
        $mock->method('rollBack')->willReturn(true);
        $mock->method('beginTransaction')->willReturn(true);
        $r->setDbhm($mock);
        $r->routeAll();

        }

    public function testLargeAttachment() {
        # Large attachments should get scaled down during the save.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment_large');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();
        assertNotNull($id);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $atts = $m->getAttachments();
        assertEquals(1, count($atts));
        assertEquals('image/jpeg', $atts[0]->getContentType());
        assertLessThan(300000, strlen($atts[0]->getData()));

        }

    public function testConfirmApplication() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        # A request to confirm an application
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/application');
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();

        # Should be to system - member now pending
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        }

    public function testConfirmNoEmail() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        # A request to confirm an application
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/confirmnoemail');
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        }

    public function testYahooNotify() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        # A request to confirm an application
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext');
        $id = $r->received(Message::EMAIL, 'notify@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testMemberApplication() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);

        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        # A request to confirm an application
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approvemember');
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        $this->log(var_export($membs, true));
        assertEquals(1, count($membs));
        assertEquals('test@test.com', $membs[0]['email']);
        assertNotNull($membs[0]['fullname']);

        # And again.  Should work, but slightly different codepath.
        $this->dbhm->preExec("DELETE FROM memberships WHERE groupid = $gid;");
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        $this->log(var_export($membs, true));
        assertEquals(1, count($membs));
        assertEquals('test@test.com', $membs[0]['email']);
        assertNotNull($membs[0]['fullname']);

        # And again with a friendly name.  The user exists and should have the name upgraded.
        error_log("Upgrade");
        $this->dbhm->preExec("DELETE FROM memberships WHERE groupid = $gid;");
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approvemember2');
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        $this->log(var_export($membs, true));
        assertEquals(1, count($membs));
        assertEquals('test@test.com', $membs[0]['email']);
        assertEquals('Test User', $membs[0]['fullname']);

        # And again with a join comment.
        $this->dbhm->preExec("DELETE FROM memberships WHERE groupid = $gid;");
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approvemembercomment');
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        assertEquals(1, count($membs));
        assertEquals('test@test.com', $membs[0]['email']);
        assertEquals('Test User', $membs[0]['fullname']);
    }

    public function testMemberJoinedApplication() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);

        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        # Set up a pending member.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@direct.ilovefreegle.org');
        $this->log("Add membership to $gid");
        $u->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::PENDING);
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        assertEquals(1, count($membs));

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/memberjoined');
        $msg = str_replace('test@test.com', 'test@direct.ilovefreegle.org', $msg);
        $r->log = TRUE;
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'test@direct.ilovefreegle.org', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        assertEquals(0, count($membs));

        $u->delete();

        }

    public function testAlreadyJoinedApplication() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);

        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        # Set up a pending member.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $this->log("Add membership to $gid");
        $u->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::PENDING);
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        assertEquals(1, count($membs));

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/alreadyjoined');
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
        $ctx = NULL;
        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::PENDING, [ $gid ]);
        assertEquals(0, count($membs));

        }

    public function testInvite() {
        # Suppress emails
        $r = $this->getMockBuilder('MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(false);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/invite');
        $id = $r->received(Message::YAHOO_SYSTEM, 'from@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        }

    public function testNullFromUser() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nullfromuser');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $this->log("Fromuser " . $m->getFromuser());
        assertNotNull($m->getFromuser());

        }

    public function testMail() {
        # For coverage.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->mail("test@blackhole.io", "test2@blackhole.io", "Test", "test");
        assertTrue(TRUE);

        }

    public function testPound() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/poundsign'));

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        }
    
    public function testReply() {
        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Mark the message as promised - this should suppress the email notification.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->promise($uid2);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $uid2 = $u->findByEmail('test2@test.com');

        # Now get the chat room that this should have been placed into.
        assertNotNull($uid2);
        assertNotEquals($uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($uid, $uid2);
        assertNotNull($rid);

        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        assertEquals("I'd like to have these, then I can return them to Greece where they rightfully belong.", $msgs[0]['message']);
        assertEquals($origid, $msgs[0]['refmsg']['id']);

        $this->log("Chat users " . var_export($users, TRUE));
        assertEquals(1, count($users));
        foreach ($users as $user) {
            assertEquals('Some replying person', $user['displayname']);
        }

        # Check that the reply is flagged as having been seen by email, as it should be since the original has
        # been promised.
        $roster = $c->getRoster();
        $this->log("Roster " . var_export($roster, TRUE));
        foreach ($roster as $rost) {
            if ($rost['user']['id'] == $uid) {
                self::assertEquals($msgs[0]['id'], $rost['lastmsgemailed']);
            }
        }

        # The reply should be visible in the message, but only when logged in as the recipient.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $atts = $m->getPublic(FALSE, FALSE, FALSE);
        assertEquals(0, count($atts['replies']));
        assertTrue($u->login('testpw'));
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $atts = $m->getPublic(FALSE, FALSE, FALSE);
        assertEquals(1, count($atts['replies']));

        # Now send another reply, but in HTML with no text body.
        $this->log("Now HTML");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replyhtml'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test3@test.com', 'test@test.com', $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $uid2 = $u->findByEmail('test3@test.com');

        # Now get the chat room that this should have been placed into.
        assertNotNull($uid2);
        assertNotEquals($uid, $uid2);
        $c = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $c->createConversation($uid, $uid2);
        assertNotNull($rid);
        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        $lines = explode("\n", $msgs[0]['message']);
        $this->log(var_export($lines, TRUE));
        assertEquals('This is a rich text reply.', trim($lines[0]));
        assertEquals('', trim($lines[1]));
        assertEquals('Hopefully you\'ll receive it and it\'ll get parsed ok.', $lines[2]);
        assertEquals($origid, $msgs[0]['refmsg']['id']);

        $this->log("Chat users " . var_export($users, TRUE));
        assertEquals(1, count($users));
        foreach ($users as $user) {
            assertEquals('Some other replying person', $user['displayname']);
        }

        # Now mark the message as complete - should put a message in the chatroom.
        $this->log("Mark $origid as TAKEN");
        $m = new Message($this->dbhm, $this->dbhm, $origid);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", User::HAPPY, NULL);
        list($msgs, $users) = $c->getMessages();
        $this->log("Chat messages " . var_export($msgs, TRUE));
        self::assertEquals(ChatMessage::TYPE_COMPLETED, $msgs[1]['type']);

    }

    public function testTNHeader() {
        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytnheader'));
        $msg = str_replace('zzzz', $origid, $msg);
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);
    }

    public function testTwoTexts() {
        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $this->log("Send reply with two texts");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/twotexts'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        $uid2 = $u->findByEmail('testreplier@test.com');

        # Now get the chat room that this should have been placed into.
        assertNotNull($uid2);
        assertNotEquals($uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($uid, $uid2);
        assertNotNull($rid);

        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        assertEquals("Not sure how to send to a phone so hope this is OK instead. Two have been taken, currently have 6 others.Bev", str_replace("\n", "", str_replace("\r", "", $msgs[0]['message'])));
        assertEquals($origid, $msgs[0]['refmsg']['id']);

        }

    public function testReplyToImmediate() {
        # Immediate emails have a reply address of replyto-msgid-userid
        #
        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test2@test.com');

        # And a promise
        $u = User::get($this->dbhr, $this->dbhm);
        $uid3 = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test3@test.com');

        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Mark the message as promised - this should suppress the email notification.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->promise($uid3);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test2@test.com', "replyto-$origid-$uid2@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        # Now get the chat room that this should have been placed into.
        assertNotEquals($uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($uid, $uid2);
        assertNotNull($rid);

        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        assertEquals($origid, $msgs[0]['refmsg']['id']);

        # Check that the reply is flagged as having been seen by email, as it should be since the original has
        # been promised.
        $roster = $c->getRoster();
        $this->log("Roster " . var_export($roster, TRUE));
        foreach ($roster as $rost) {
            if ($rost['user']['id'] == $uid) {
                self::assertEquals($msgs[0]['id'], $rost['lastmsgemailed']);
            }
        }

        }

    public function testMailOff() {
        # Create the sending user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('from@test.com');
        $this->log("Created user $uid");

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        $u->setMembershipAtt($gid, 'emailfrequency', 24);

        # Turn off by email
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', "digestoff-$uid-$gid@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testEventsOff() {
        # Create the sending user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn events off by email
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', "eventsoff-$uid-$gid@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testNotificationOff() {
        # Create the sending user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");

        # Can only see settings logged in.
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $atts = $u->getPublic();
        assertTrue($atts['settings']['notificationmails']);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn off by email
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', "notificationmailsoff-$uid@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals($rc, MailRouter::TO_SYSTEM);

        $u = User::get($this->dbhm, $this->dbhm, $uid);
        $atts = $u->getPublic();
        assertFalse($atts['settings']['notificationmails']);

        }

    public function testRelevantOff() {
        # Create the sending user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn events off by email
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', "relevantoff-$uid@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testNewslettersOff() {
        # Create the sending user
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn events off by email
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', "newslettersoff-$uid@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testVols() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-volunteers@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_VOLUNTEERS, $rc);

        # And again now we know them, using the auto this time.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-auto@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_VOLUNTEERS, $rc);

        # And with spam
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply')  . "\r\nviagra\r\n");
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $this->log("Reply with spam $msg");
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-auto@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testSubMailUnsub() {
        # Subscribe

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 0);

        # Post by email when not a member.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id when not a member");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::DROPPED, $rc);

        # Now subscribe.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-subscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $membs = $u->getMemberships();
        assertEquals(1, count($membs));

        $this->waitBackground();
        $_SESSION['id'] = $uid;
        $logs = $u->getPublic(NULL, FALSE, TRUE)['logs'];
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs);
        assertEquals($gid, $log['group']['id']);

        # Mail - first to pending for new member, noderated by default, then to approved for group settings.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::PENDING, $rc);
        assertTrue($m->isPending($gid));

        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        self::assertEquals(MessageCollection::APPROVED, $u->postToCollection($gid));
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::APPROVED, $rc);
        assertTrue($m->isApproved($gid));

        # Test moderated
        $g->setSettings([ 'moderated' => TRUE ]);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::PENDING, $rc);
        assertTrue($m->isPending($gid));

        # Unsubscribe

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-unsubscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $membs = $u->getMemberships();
        assertEquals(0, count($membs));

        $this->waitBackground();
        $_SESSION['id'] = $uid;
        $logs = $u->getPublic(NULL, FALSE, TRUE)['logs'];
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_LEFT, $logs);
        assertEquals($gid, $log['group']['id']);

        }

    public function testReplyAll() {
        # Some people reply all to both our user and the Yahoo group.

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $eid = $u->addEmail('test2@test.com');
        $u->addMembership($gid, User::ROLE_MEMBER, $eid);

        # Create the sending user
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail($email));

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::YAHOO_APPROVED, $email, 'testgroup1@yahoogroups.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_replace("To: test@test.com", "To: $email, testgroup1@yahoogroups.com", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test2@test.com', $email, $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        # Check it didn't go to a group
        $groups = $m->getGroups();
        self::assertEquals(0, count($groups));

        $uid2 = $u->findByEmail('test2@test.com');

        # Now get the chat room that this should have been placed into.
        assertNotNull($uid2);
        assertNotEquals($uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($uid, $uid2);
        assertNotNull($rid);
        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        assertEquals("I'd like to have these, then I can return them to Greece where they rightfully belong.", $msgs[0]['message']);
        assertEquals($origid, $msgs[0]['refmsg']['id']);

        }

    public function testYahooApproved() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->setPrivate('yahooid', 'testid');
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/yahooapproved'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $groups = $m->getPublic()['groups'];
        $this->log("Groups " . var_export($groups, TRUE));
        self::assertEquals($uid, $groups[0]['approvedby']);

        }

    public function testOldYahoo() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->setPrivate('yahooid', 'testid');
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 0);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = str_replace('test@test.com', 'from@yahoogroups.com', $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/yahooapproved')));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::YAHOO_APPROVED, 'from@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testModChat() {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail(MODERATOR_EMAIL);

        if (!$uid) {
            $uid = $u->create("Test", "User", "Test User");
            $u->addEmail(MODERATOR_EMAIL);
        }

        $u = new User($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($gid, User::ROLE_MEMBER);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('To: "freegleplayground@yahoogroups.com" <freegleplayground@yahoogroups.com>', 'To: ' . MODERATOR_EMAIL, $msg);
        $msg = str_replace('X-Apparently-To: freegleplayground@yahoogroups.com', 'X-Apparently-To: ' . MODERATOR_EMAIL, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'testgroup-volunteers@groups.ilovefreegle.org', MODERATOR_EMAIL, $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testTwitterAppeal() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->setPrivate('yahooid', 'testid');
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 0);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = str_replace('test@test.com', 'support@twitter.com', $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/twitterappeal')));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->log = TRUE;
        $mid = $r->received(Message::EMAIL, 'support@twitter.com', 'testgroup1-volunteers@groups.ilovefreegle.org', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);
    }

    public function testWorry() {
        $this->dbhm->preExec("INSERT INTO worrywords (keyword, type) VALUES (?, ?);", [
            'UTtest1',
            WorryWords::TYPE_REPORTABLE
        ]);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        # Create a user not on moderation.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/worry');
        $id = $r->received(Message::EMAIL, 'notify@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        assertNotNull($m->getPublic()['worry']);
    }

    public function testBigSwitch() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 0);
        $g->setPrivate('overridemoderation', Group::OVERRIDE_MODERATION_ALL);

        # Now subscribe.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-subscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $membs = $u->getMemberships();
        assertEquals(1, count($membs));

        # Mail - should be pending because of big switch.
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::PENDING, $rc);
        assertTrue($m->isPending($gid));
    }

    public function testBanned() {
        $u1 = User::get($this->dbhr, $this->dbhr);
        $uid1 = $u1->create(NULL, NULL, 'Test User');
        assertNotNull($uid1);
        $u1->addEmail('test@test.com');

        # Ban u1
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');
        $u1->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $u1->removeMembership($gid, TRUE);

        # u1 shouldn't be able to post by email.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msgid = $r->received(Message::EMAIL, 'test2@test.com', 'freegleplayground@' . GROUP_DOMAIN, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);
    }

    //    public function testSpecial() {
//        //
//        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/special'));
//        $r = new MailRouter($this->dbhr, $this->dbhm);
//        $m = new Message($this->dbhr, $this->dbhm, 25206247);
//        $r->route($m);
//        $id = $r->received(Message::EMAIL, 'xxxxxxx@ntlworld.com', "hertfordfreegle-volunteers@groups.ilovefreegle.org", $msg);
//        assertNotNull($id);
//        $rc = $r->route();
//        assertEquals(MailRouter::TO_VOLUNTEERS, $rc);
//
//        //    }
}


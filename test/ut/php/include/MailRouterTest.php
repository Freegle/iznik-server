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
class MailRouterTest extends IznikTestCase {
    private $dbhr, $dbhm;
    private $msgsSent = [];

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

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        assertNotNull($this->gid);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertEquals(1, $u->addMembership($this->gid));
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->user = $u;
    }

    public function mailer($to, $from, $subject, $body) {
        $this->msgsSent[] = [
            'subject' => $subject,
            'to' => $to,
            'body' => $body
        ];
    }

    protected function tearDown() {
        parent::tearDown ();

        $this->dbhm->preExec("DELETE FROM spam_whitelist_ips WHERE ip = '1.2.3.4';", []);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip = '1.2.3.4';", []);
        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip = '4.3.2.1';", []);
    }

    public function testHam() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');
        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $u = User::get($this->dbhr, $this->dbhm);

        # Create a different user which will cause a merge.
        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u2 = User::get($this->dbhr, $this->dbhm, $uid2);
        assertGreaterThan(0, $u->addEmail('test2@test.com'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("X-Yahoo-Group-Post: member; u=420816297", "X-Yahoo-Group-Post: member; u=-1", $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($this->uid, $m->getFromuser());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/fromyahoo'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('Yahoo-Web', $m->getSourceheader());
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Test group override
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/fromyahoo'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('Yahoo-Web', $m->getSourceheader());
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $groups = $m->getGroups();
        $this->log("Groups " . var_export($groups, true));
        assertEquals($gid, $groups[0]);
        assertTrue($m->isApproved($gid));
    }

    public function testHamNoGroup() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("freegleplayground@yahoogroups.com", "nogroup@yahoogroups.com", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($id);
        assertEquals(MailRouter::DROPPED, $r->route());
    }

    public function testSpamNoGroup() {
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_replace("FreeglePlayground <freegleplayground@yahoogroups.com>", "Nowhere <nogroup@yahoogroups.com>", $msg);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testSpamSubject() {
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $subj = "Test spam subject " . microtime();
        $groups = [];

        for ($i = 0; $i < Spam::SUBJECT_THRESHOLD + 2; $i++) {
            $g = Group::get($this->dbhr, $this->dbhm);
            $g->create("testgroup$i", Group::GROUP_REUSE);
            $groups[] = $g;

            $this->user->addMembership($g->getId());
            $this->user->setMembershipAtt($g->getId(), 'ourPostingStatus', Group::POSTING_DEFAULT);
            User::clearCache();

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_replace('Basic test', $subj, $msg);
            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;

            $msgid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
            $rc = $r->route();

            if ($i < Spam::SUBJECT_THRESHOLD - 1) {
                assertEquals(MailRouter::APPROVED, $rc);
            } else {
                assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }

        # Now mark the last subject as not spam.  Once we've done that, we should be able to route it ok.
        error_log("Do last");
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $m->notSpam();
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        foreach ($groups as $group) {
            $group->delete();
        }
    }

    public function testSpam() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::EMAIL, $m->getSource());

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
        assertEquals(Message::EMAIL, $spam->getSource());
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
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

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
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
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
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
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

        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testSpamOverride() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');
        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::EMAIL, $m->getSource());

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route(NULL, TRUE);
        assertEquals(MailRouter::APPROVED, $rc);

        }

    public function testWhitelist() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_replace('Precedence: junk', 'X-Freegle-IP: 1.2.3.4', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testPending() {
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        
        $pend = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('test@test.com', $pend->getFromaddr());
        assertEquals('1.2.3.4', $pend->getFromIP());
        assertNull($pend->getFromhost());
        assertNotNull($pend->getGroups()[0]);
        assertEquals($id, $pend->getID());
        assertEquals(Message::EMAIL, $pend->getSource());
        assertEquals('from@test.com', $pend->getEnvelopefrom());
        assertEquals('to@test.com', $pend->getEnvelopeto());
        assertNotNull($pend->getTextbody());
        assertEquals($pend->getSubject(), $pend->getHeader('subject'));
        assertEquals('testgroup@yahoogroups.com', $pend->getTo()[0]['address']);
        assertEquals('Test User', $pend->getFromname());
        $this->log("Delete $id from " . var_export($pend->getGroups(), true));
        $pend->delete(NULL, $pend->getGroups()[0]);

        }

    function testPendingToApproved() {
        $this->log("First copy on $this->gid");

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);

        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        assertNotNull(new Message($this->dbhr, $this->dbhm, $id));
        $this->log("Pending id $id");

        # Approve
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->gid, User::ROLE_OWNER);
        assertTrue($u->login('testpw'));

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($this->gid, NULL, NULL, NULL);

        # The approvedby should be preserved
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $groups = $m->getPublic()['groups'];
        $this->log("Groups " . var_export($groups, TRUE));
        assertEquals($uid, $groups[0]['approvedby']['id']);

        # Now the same, but with a TN post which has no messageid.
        $this->log("Now TN post");
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $msg = str_replace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('20065945', $m->getTnpostid());
        assertEquals('TN-email', $m->getSourceheader());
        $id = $m->save();
        $this->log("Saved $id");

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        assertNotNull(new Message($this->dbhr, $this->dbhm, $id));
        $this->log("Pending id $id");
    }

    function testTNSpamToApproved() {
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        # Force a TN message to spam
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $id = $m->save();

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
    }

    public function testSpamIP() {
        # Sorry, Cameroon folk.
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/cameroon');
        $msg = str_replace('freegleplayground@yahoogroups.com', 'b.com', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # This should have stored the IP in the message.
        $m = new Message($this->dbhm, $this->dbhm, $id);
        assertEquals('41.205.16.153', $m->getFromIP());

        }

    public function testFailSpam() {
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);

        # Make the attempt to mark as spam fail.
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('markAsSpam'))
            ->getMock();
        $r->method('markAsSpam')->willReturn(false);

        $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
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

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Make the geo lookup throw an exception, which it does for unknown IPs
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('X-Originating-IP: 1.2.3.4', 'X-Originating-IP: 238.162.112.228', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);

        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
    }

    public function testFailHam() {
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();

        # Set us unmoderated so that we route to approved.
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Make the attempt to mark the message as approved
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('markApproved', 'markPending'))
            ->getMock();
        $r->method('markApproved')->willReturn(false);
        $r->method('markPending')->willReturn(false);

        $this->log("Expect markApproved fail");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::FAILURE, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
    }

    public function testMultipleUsers() {
        for ($i = 0; $i < Spam::USER_THRESHOLD + 2; $i++) {
            $this->log("User $i");

            $u = new User($this->dbhr, $this->dbhm);
            $u->create('Test', 'User', 'Test User');
            $u->addEmail("test$i@test.com");
            $u->addMembership($this->gid);
            $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
            $msg = str_replace(
                'From: "Test User" <test@test.com>',
                'From: "Test User ' . $i . '" <test' . $i . '@test.com>',
                $msg);
            $msg = str_replace('1.2.3.4', '4.3.2.1', $msg);
            $msg = str_replace('X-Yahoo-Group-Post: member; u=420816297', 'X-Yahoo-Group-Post: member; u=' . $i, $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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
            $gid = $g->create("testgroup$i", Group::GROUP_REUSE);

            $this->user->addMembership($gid);
            $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
            User::clearCache();

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_ireplace("FreeglePlayground", "testgroup$i", $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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
            $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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

        # Remove a membership but will still count for spam checking.
        $this->user->removeMembership($this->gid);

        for ($i = 0; $i < Spam::GROUP_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            $gid = $g->create("testgroup$i", Group::GROUP_OTHER);

            $this->waitBackground();

            $this->user->addMembership($gid);
            $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
            User::clearCache();

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;
            $msg = str_replace('1.2.3.4', '4.3.2.1', $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
            $this->log("Msg $id");
            $rc = $r->route();

            # The user will get marked as suspect.
            if ($i < Spam::SEEN_THRESHOLD - 1) {
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
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($m->getGroupId());
        $id = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->routeAll();

        # Force exception
        $this->log("Now force exception");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $m->save();

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec', 'rollBack', 'beginTransaction'))
            ->getMock();
        $mock->method('preExec')->will($this->throwException(new \Exception()));
        $mock->method('rollBack')->willReturn(true);
        $mock->method('beginTransaction')->willReturn(true);
        $r->setDbhm($mock);
        $r->routeAll();

        }

    public function testLargeAttachment() {
        # Large attachments should get scaled down during the save.
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment_large');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();
        assertNotNull($id);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $atts = $m->getAttachments();
        assertEquals(1, count($atts));
        assertEquals('image/jpeg', $atts[0]->getContentType());
        assertLessThan(300000, strlen($atts[0]->getData()));

        }

    public function testYahooNotify() {
        # Suppress emails
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
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

    public function testNullFromUser() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nullfromuser');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/poundsign'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
    }
    
    public function testReply() {
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();

        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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
        assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($this->uid, $uid2);
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
            if ($rost['user']['id'] == $this->uid) {
                self::assertEquals($msgs[0]['id'], $rost['lastmsgemailed']);
            }
        }

        # The reply should be visible in the message, but only when logged in as the recipient.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $atts = $m->getPublic(FALSE, FALSE, FALSE);
        assertEquals(0, count($atts['replies']));
        assertTrue($this->user->login('testpw'));
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
        assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $c->createConversation($this->uid, $uid2);
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

    public function testToClosed() {
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();

        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Mark the group as closed.
        $this->group->setSettings([ 'closed' => TRUE ]);

        # Send a reply.  This should result in in an automatic reply..
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $mock = $this->getMockBuilder('Freegle\Iznik\MailRouter')
            ->setConstructorArgs(array($this->dbhm, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $mock->method('mail')->will($this->returnCallback(function($to, $from, $subject, $body) {
            return($this->mailer($to, $from, $subject, $body));
        }));

        $mock->received(Message::EMAIL, 'test2@test.com', 'replyto-' . $origid . '-' . $uid2 . '@' . USER_DOMAIN, $msg);
        $mock->route();
        assertEquals(1, count($this->msgsSent));
        assertEquals("This community is currently closed", $this->msgsSent[0]['subject']);
    }

    public function testReplyToMissing() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test User');

        # Send a purported reply to that user.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply2'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test2@test.com', "user-$uid1@" . USER_DOMAIN, $msg);
        assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertNotNull($m->getFromuser());
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        # Check marked as spam
        $uid2 = $u->findByEmail('from2@test.com');
        assertNotNull($uid2);
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid = ?;", [
            $uid2
        ]);
        assertEquals(1, count($msgs));
        assertEquals(1, $msgs[0]['reviewrejected']);
    }

    public function testTNHeader() {
        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $this->log("Send reply with two texts");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/twotexts'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
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
        assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($this->uid, $uid2);
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

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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
        assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($this->uid, $uid2);
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
            if ($rost['user']['id'] == $this->uid) {
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
        # Post by email when not a member.
        $this->user->removeMembership($this->gid);
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
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$u->getId()]['logs']);
        assertEquals($this->gid, $log['group']['id']);

        # Mail - first to pending for new member, moderated by default, then to approved for group settings.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::PENDING, $rc);
        assertTrue($m->isPending($this->gid));

        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        self::assertEquals(MessageCollection::APPROVED, $u->postToCollection($this->gid));
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::APPROVED, $rc);
        assertTrue($m->isApproved($this->gid));

        # Test moderated
        $this->group->setSettings([ 'moderated' => TRUE ]);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::PENDING, $rc);
        assertTrue($m->isPending($this->gid));

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
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_LEFT, $logs[$u->getId()]['logs']);
        assertEquals($this->gid, $log['group']['id']);

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
        assertEquals(1, $u->addMembership($gid));
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup1", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, $email, 'testgroup1@yahoogroups.com', $msg);
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

    public function testApproved() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create("Test", "User", "Test User");
        $u->setPrivate('yahooid', 'testid');
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approved'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
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
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = str_replace('test@test.com', 'from@yahoogroups.com', $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approved')));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'from@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testModChat() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail(MODERATOR_EMAIL);

        if (!$uid) {
            $uid = $u->create("Test", "User", "Test User");
            $u->addEmail(MODERATOR_EMAIL);
        }

        $u = new User($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid, User::ROLE_MEMBER);

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

        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/worry');
        $id = $r->received(Message::EMAIL, 'notify@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        assertNotNull($m->getPublic()['worry']);
    }

    public function testBigSwitch() {
        $this->group->setPrivate('overridemoderation', Group::OVERRIDE_MODERATION_ALL);

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
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::PENDING, $rc);
        assertTrue($m->isPending($this->gid));
    }

    public function testBanned() {
        # Ban u1
        $this->user->addMembership($this->gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $this->user->removeMembership($this->gid, TRUE);

        # u1 shouldn't be able to post by email.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msgid = $r->received(Message::EMAIL, 'test2@test.com', 'freegleplayground@' . GROUP_DOMAIN, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testCantPost() {
        # Subscribe

        # Now subscribe.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-subscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        # Set ourselves to can't post.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_PROHIBITED);

        # Mail - should be dropped.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testSwallowTaken() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $msg = str_replace('Test native', 'OFFER: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $msg = str_replace('Test native', 'TAKEN: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        assertEquals(MailRouter::TO_SYSTEM, $rc);
    }

    public function testReplyWithGravatar() {
        # This reply contains a gravatar which should be tripped.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/reply_with_gravatar'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(0, count($m->getAttachments()));
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


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

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        # Whitelist this IP
        $this->dbhm->preExec("INSERT IGNORE INTO spam_whitelist_ips (ip, comment) VALUES ('1.2.3.4', 'UT whitelist');", []);

        # Tidy test subjects
        $this->dbhm->preExec("DELETE FROM spam_whitelist_subjects WHERE subject LIKE 'Test spam subject%';");
        $this->dbhm->preExec("DELETE FROM worrywords WHERE keyword LIKE 'UTtest%';");

        list($this->group, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $this->assertNotNull($this->gid);
        $this->group->setPrivate('onhere', 1);

        list($this->user, $this->uid) = $this->createTestUserWithMembership($this->gid, User::ROLE_MEMBER, 'Test User', 'test@test.com', 'testpw');
        $this->user->addEmail('sender@example.net');
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
    }

    public function mailer($to, $from, $subject, $body) {
        $this->msgsSent[] = [
            'subject' => $subject,
            'to' => $to,
            'body' => $body
        ];
    }

    protected function tearDown() : void {
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
        $this->assertGreaterThan(0, $u->addEmail('test2@test.com'));

        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'FreeglePlayground', 'from@test.com', 'to@test.com', $gid, $this->uid, ["X-Yahoo-Group-Post: member; u=420816297" => "X-Yahoo-Group-Post: member; u=-1"]);
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals($this->uid, $m->getFromuser());

        list($r, $id, $failok, $rc) = $this->createTestMessage('fromyahoo', 'FreeglePlayground', 'from@test.com', 'to@test.com', $gid, $this->uid);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('Yahoo-Web', $m->getSourceheader());
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Test group override
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/fromyahoo'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('Yahoo-Web', $m->getSourceheader());
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $groups = $m->getGroups();
        $this->log("Groups " . var_export($groups, TRUE));
        $this->assertEquals($gid, $groups[0]);
        $this->assertTrue($m->isApproved($gid));
    }

    public function testHamNoGroup() {
        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'nogroup', 'from@test.com', 'to@test.com', NULL, NULL, ["freegleplayground@yahoogroups.com" => "nogroup@yahoogroups.com"]);
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testSpamNoGroup() {
        list($r, $id, $failok, $rc) = $this->createTestMessage('spam', 'nogroup', 'from@test.com', 'to@test.com', NULL, NULL, ["FreeglePlayground <freegleplayground@yahoogroups.com>" => "Nowhere <nogroup@yahoogroups.com>"]);
        $this->assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testSpamSubject() {
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $subj = "Test spam subject " . microtime();
        $groups = [];

        for ($i = 0; $i < Spam::SUBJECT_THRESHOLD + 2; $i++) {
            list($g, $gid) = $this->createTestGroup("testgroup$i", Group::GROUP_REUSE);
            $groups[] = $g;

            $this->user->addMembership($gid);
            $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
            User::clearCache();

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
            $msg = str_replace('Basic test', $subj, $msg);
            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;

           list ($msgid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
            $rc = $r->route();

            if ($i < Spam::SUBJECT_THRESHOLD - 1) {
                $this->assertEquals(MailRouter::APPROVED, $rc);
            } else {
                $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }

        # Now mark the last subject as not spam.  Once we've done that, we should be able to route it ok.
        error_log("Do last");
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $m->notSpam();
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        foreach ($groups as $group) {
            $group->delete();
        }
    }

    public function testSpam() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Message::EMAIL, $m->getSource());

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $spam = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('sender@example.net', $spam->getFromaddr());
        $this->assertNull($spam->getFromIP());
        $this->assertNull($spam->getFromhost());
        $this->assertEquals(1, count($spam->getGroups()));
        $this->assertEquals($id, $spam->getID());
        $this->assertEquals(0, strpos($spam->getMessageID(), 'GTUBE1.1010101@example.net'));
        $this->assertEquals(str_replace("\r\n", "\n", $msg), str_replace("\r\n", "\n", $spam->getMessage()));
        $this->assertEquals(Message::EMAIL, $spam->getSource());
        $this->assertEquals('from1@test.com', $spam->getEnvelopefrom());
        $this->assertEquals('to@test.com', $spam->getEnvelopeto());
        $this->assertNotNull($spam->getTextbody());
        $this->assertNull($spam->getHtmlbody());
        $this->assertEquals($spam->getSubject(), $spam->getHeader('subject'));
        $this->assertEquals('freegleplayground@yahoogroups.com', $spam->getTo()[0]['address']);
        $this->assertEquals('Sender', $spam->getFromname());
        $this->assertTrue(strpos($spam->getSpamreason(), 'SpamAssassin flagged this as possible spam') !== FALSE);
        $spam->delete();

        }

    public function testGreetingSpam() {
        # Suppress emails - can't use utility methods because we need the mocked MailRouter
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(FALSE);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/greetingsspam');
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/greetingsspam2');
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
    }

    public function testReferToSpammer() {
        # Suppress emails
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(FALSE);

        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($u, $uid, $emailid) = $this->createTestUser("Test", "User", "Test User", $email, 'testpw');

        $this->dbhm->preExec("INSERT INTO spam_users (userid, collection, reason) VALUES (?, ?, ?);", [
            $uid,
            Spam::TYPE_SPAMMER,
            'UT Test'
        ]);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = str_replace('Hey', "Please reply to $email", $msg);

       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testSpamOverride() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');
        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Message::EMAIL, $m->getSource());

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route(NULL, TRUE);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        }

    public function testWhitelist() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_replace('Precedence: junk', 'X-Freegle-IP: 1.2.3.4', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        }

    public function testGroupsSpam() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamgroups');
        $msg = str_replace('chippenham-freegle', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
    }

    public function testPending() {
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        
        $pend = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('test@test.com', $pend->getFromaddr());
        $this->assertEquals('1.2.3.4', $pend->getFromIP());
        $this->assertNull($pend->getFromhost());
        $this->assertNotNull($pend->getGroups()[0]);
        $this->assertEquals($id, $pend->getID());
        $this->assertEquals(Message::EMAIL, $pend->getSource());
        $this->assertEquals('from@test.com', $pend->getEnvelopefrom());
        $this->assertEquals('to@test.com', $pend->getEnvelopeto());
        $this->assertNotNull($pend->getTextbody());
        $this->assertEquals($pend->getSubject(), $pend->getHeader('subject'));
        $this->assertEquals('testgroup@yahoogroups.com', $pend->getTo()[0]['address']);
        $this->assertEquals('Test User', $pend->getFromname());
        $this->log("Delete $id from " . var_export($pend->getGroups(), TRUE));
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
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $this->assertNotNull(new Message($this->dbhr, $this->dbhm, $id));
        $this->log("Pending id $id");

        # Approve
        list($u, $uid, $emailid) = $this->createTestUserWithMembershipAndLogin($this->gid, User::ROLE_OWNER, NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($this->gid, NULL, NULL, NULL);

        # The approvedby should be preserved
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $groups = $m->getPublic()['groups'];
        $this->log("Groups " . var_export($groups, TRUE));
        $this->assertEquals($uid, $groups[0]['approvedby']['id']);

        # Now the same, but with a TN post which has no messageid.
        $this->log("Now TN post");
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn');
        $msg = str_replace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertEquals('20065945', $m->getTnpostid());
        $this->assertEquals('TN-email', $m->getSourceheader());
        list ($id, $failok) = $m->save();
        $this->log("Saved $id");

        # Check lastlocation set
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());
        $l = new Location($this->dbhr, $this->dbhm, $u->getPrivate('lastlocation'));
        $this->assertEquals('EH3 6SS', $l->getPrivate('name'));

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $this->assertNotNull(new Message($this->dbhr, $this->dbhm, $id));
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
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(TRUE);
        $mock->result['SCORE'] = 100;
        $r->setSpamc($mock);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
        $this->assertNotNull(new Spam($this->dbhr, $this->dbhm, $id));
        $this->log("Spam id $id");
    }

    public function testSpamIP() {
        # Sorry, Cameroon folk.
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/cameroon');
        $msg = str_replace('freegleplayground@yahoogroups.com', 'b.com', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # This should have stored the IP in the message.
        $m = new Message($this->dbhm, $this->dbhm, $id);
        $this->assertEquals('41.205.16.153', $m->getFromIP());

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
        $r->method('markAsSpam')->willReturn(FALSE);

        $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::FAILURE, $rc);

        # Make the spamc check itself fail - should still go through.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(FALSE);
        $r->setSpamc($mock);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Make the geo lookup throw an exception, which it does for unknown IPs
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('X-Originating-IP: 1.2.3.4', 'X-Originating-IP: 238.162.112.228', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);

        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
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
        $r->method('markApproved')->willReturn(FALSE);
        $r->method('markPending')->willReturn(FALSE);

        $this->log("Expect markApproved fail");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::FAILURE, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::FAILURE, $rc);

        # Make the spamc check itself fail - should still go through.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('spamc')
            ->disableOriginalConstructor()
            ->setMethods(array('filter'))
            ->getMock();
        $mock->method('filter')->willReturn(FALSE);
        $r->setSpamc($mock);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
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
                $this->assertEquals(MailRouter::APPROVED, $rc);
            } else {
                $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
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

            $this->assertEquals(MailRouter::APPROVED, $rc);
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
                $this->assertEquals(MailRouter::APPROVED, $rc);
            } else {
                $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }

        $this->dbhm->preExec("DELETE FROM messages_history WHERE fromip LIKE ?;", ['4.3.2.%']);

        }

    public function testMultipleGroups() {
        # Remove a membership but will still count for spam checking.
        $this->user->removeMembership($this->gid);

        for ($i = 0; $i < Spam::GROUP_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            list($g, $gid) = $this->createTestGroup("testgroup$i", Group::GROUP_OTHER);

            $this->waitBackground();

            $this->user->addMembership($gid);
            $this->user->processMemberships();
            $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
            User::clearCache();

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

            $msg = "X-Apparently-To: testgroup$i@yahoogroups.com\r\n" . $msg;
            $msg = str_replace('1.2.3.4', '4.3.2.1', $msg);

            $r = new MailRouter($this->dbhr, $this->dbhm);
           list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
            $this->log("Msg $id");
            $rc = $r->route();

            # The user will get marked as suspect.
            if ($i < Spam::SEEN_THRESHOLD - 1) {
                $work = $g->getWorkCounts([
                    $gid => [
                        'active' => TRUE
                    ]
                ], [ $gid ]);

                $this->assertEquals(0, $work[$gid]['spammembers']);
                $this->assertEquals(0, $work[$gid]['spammembersother']);
            } else {
                $work = $g->getWorkCounts([
                    $gid => [
                        'active' => TRUE
                    ]
                ], [ $gid ]);

                $this->assertEquals(1, $work[$gid]['spammembers']);
                $this->assertEquals(0, $work[$gid]['spammembersother']);

                $work = $g->getWorkCounts([
                    $gid => [
                        'active' => FALSE
                    ]
                ], [ $gid ]);

                $this->assertEquals(0, $work[$gid]['spammembers']);
                $this->assertEquals(1, $work[$gid]['spammembersother']);
            }

            # The message can get marked as spam.
            if ($i < Spam::GROUP_THRESHOLD - 1) {
                $this->assertEquals(MailRouter::APPROVED, $rc);
            } else {
                $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }

            # Should also show in work.
        }

    }

    public function testBulkOwnerMail() {
        for ($i = 0; $i < Spam::GROUP_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            list($g, $gid) = $this->createTestGroup("testgroup$i", Group::GROUP_OTHER);

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

            $r = new MailRouter($this->dbhr, $this->dbhm);
           list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', "testgroup$i-volunteers@" . GROUP_DOMAIN, $msg);
            $this->log("Msg $id");
            $rc = $r->route();

            # The message can get marked as spam.
            if ($i < Spam::GROUP_THRESHOLD - 1) {
                $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);
            } else {
                $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }
    }

    public function testBulkOwnerSubject() {
        $g = Group::get($this->dbhr, $this->dbhm);

        for ($i = 0; $i < Spam::GROUP_THRESHOLD + 2; $i++) {
            $this->log("Group $i");
            $gid = $g->create("testgroup$i", Group::GROUP_OTHER);

            $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

            $r = new MailRouter($this->dbhr, $this->dbhm);
            $email = 'ut-' . rand() . '@' . USER_DOMAIN;
           list ($id, $failok) = $r->received(Message::EMAIL, $email, "testgroup$i-volunteers@" . GROUP_DOMAIN, $msg);
            $this->log("Msg $id");
            $rc = $r->route();

            # The message can get marked as spam.
            if ($i < Spam::GROUP_THRESHOLD - 1) {
                $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);
            } else {
                $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
            }
        }
    }

    function testRouteAll() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($m->getGroupId());
        list ($id, $failok) = $m->save();

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
        $mock->method('rollBack')->willReturn(TRUE);
        $mock->method('beginTransaction')->willReturn(TRUE);
        $r->setDbhm($mock);
        $r->routeAll();

        }

    public function testLargeAttachment() {
        # Large attachments should get scaled down during the save.
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment_large');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();
        $this->assertNotNull($id);
        $m->saveAttachments($id);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $atts = $m->getAttachments();
        $this->assertEquals(1, count($atts));
        $this->assertLessThan(300000, strlen($atts[0]->getData()));
    }

    public function testYahooNotify() {
        # Suppress emails
        $r = $this->getMockBuilder('Freegle\Iznik\MailRouter')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('mail'))
            ->getMock();
        $r->method('mail')->willReturn(FALSE);

        # A request to confirm an application
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext');
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testNullFromUser() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nullfromuser');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->log("Fromuser " . $m->getFromuser());
        $this->assertNotNull($m->getFromuser());

        }

    public function testMail() {
        # For coverage.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->mail("test@blackhole.io", "test2@blackhole.io", "Test", "test", Mail::MODMAIL, 0);
        $this->assertTrue(TRUE);
    }

    public function testPound() {
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_UNMODERATED);
        User::clearCache();

        list($r, $id, $failok, $rc) = $this->createTestMessage('poundsign', 'testgroup', 'from@test.com', 'to@test.com', $this->gid, $this->uid);
        $this->assertNotNull($id);
        $this->assertEquals(MailRouter::PENDING, $rc);
    }
    
    public function testReply() {
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        User::clearCache();

        # Create a user for a reply.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        # Send a message.
        list($r, $origid, $failok, $rc) = $this->createTestMessage('basic', 'testgroup', 'from@test.com', 'to@test.com', $this->gid, $this->uid, ['Subject: Basic test' => 'Subject: [Group-tag] Offer: thing (place)']);
        $this->assertNotNull($origid);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Mark the message as promised - this should suppress the email notification.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->promise($uid2);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $uid2 = $u->findByEmail('test2@test.com');

        # Now get the chat room that this should have been placed into.
        $this->assertNotNull($uid2);
        $this->assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid2);
        $this->assertNotNull($rid);

        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        $this->assertEquals("I'd like to have these, then I can return them to Greece where they rightfully belong.", $msgs[0]['message']);
        $this->assertEquals($origid, $msgs[0]['refmsg']['id']);

        $this->log("Chat users " . var_export($users, TRUE));
        $this->assertEquals(1, count($users));
        foreach ($users as $user) {
            $this->assertEquals('Some replying person', $user['displayname']);
        }

        # Check that the reply is not flagged as having been seen by email.
        $roster = $c->getRoster();
        $this->log("Roster " . var_export($roster, TRUE));
        foreach ($roster as $rost) {
            if ($rost['user']['id'] == $this->uid) {
                self::assertNull($rost['lastmsgemailed']);
            }
        }

        # The reply should be visible in the message, but only when logged in as the recipient.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $atts = $m->getPublic(FALSE, FALSE);
        $this->assertEquals(0, count($atts['replies']));
        $this->assertTrue($this->user->login('testpw'));
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $atts = $m->getPublic(FALSE, FALSE);
        $this->assertEquals(1, count($atts['replies']));

        # Now send another reply, but in HTML with no text body.
        $this->log("Now HTML");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replyhtml'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test3@test.com', 'test@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $uid2 = $u->findByEmail('test3@test.com');

        # Now get the chat room that this should have been placed into.
        $this->assertNotNull($uid2);
        $this->assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhm, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid2);
        $this->assertNotNull($rid);
        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        $lines = explode("\n", $msgs[0]['message']);
        $this->log(var_export($lines, TRUE));
        $this->assertEquals('This is a rich text reply.', trim($lines[0]));
        $this->assertEquals('', trim($lines[1]));
        $this->assertEquals('Hopefully you\'ll receive it and it\'ll get parsed ok.', $lines[2]);
        $this->assertEquals($origid, $msgs[0]['refmsg']['id']);

        $this->log("Chat users " . var_export($users, TRUE));
        $this->assertEquals(1, count($users));
        foreach ($users as $user) {
            $this->assertEquals('Some other replying person', $user['displayname']);
        }

        # Now mark the message as complete - should put a message in the chatroom.
        $this->log("Mark $origid as TAKEN");
        $m = new Message($this->dbhm, $this->dbhm, $origid);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", User::HAPPY, NULL);
        $this->waitBackground();
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
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

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
        $this->assertEquals(1, count($this->msgsSent));
        $this->assertEquals("This community is currently closed", $this->msgsSent[0]['subject']);
    }

    public function testReplyToMissing() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create(NULL, NULL, 'Test User');

        # Send a purported reply to that user.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply2'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', "user-$uid1@" . USER_DOMAIN, $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Check marked as spam
        $uid2 = $u->findByEmail('from2@test.com');
        $this->assertNotNull($uid2);
        $msgs = $this->dbhr->preQuery("SELECT * FROM chat_messages WHERE userid = ?;", [
            $uid2
        ]);
        $this->assertEquals(1, count($msgs));
        error_log(var_export($msgs, TRUE));
        $this->assertEquals(1, $msgs[0]['reviewrejected']);
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
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytnheader'));
        $msg = str_replace('zzzz', $origid, $msg);
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);
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
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $this->log("Send reply with two texts");
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/twotexts'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        $uid2 = $u->findByEmail('testreplier@test.com');

        # Now get the chat room that this should have been placed into.
        $this->assertNotNull($uid2);
        $this->assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid2);
        $this->assertNotNull($rid);

        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        $this->assertEquals("Not sure how to send to a phone so hope this is OK instead. Two have been taken, currently have 6 others.Bev", str_replace("\n", "", str_replace("\r", "", $msgs[0]['message'])));
        $this->assertEquals($origid, $msgs[0]['refmsg']['id']);

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
       list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Mark the message as promised - this should suppress the email notification.
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->promise($uid3);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', "replyto-$origid-$uid2@" . USER_DOMAIN, $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Now get the chat room that this should have been placed into.
        $this->assertNotEquals($this->uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($this->uid, $uid2);
        $this->assertNotNull($rid);

        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        $this->assertEquals($origid, $msgs[0]['refmsg']['id']);
        $this->assertEquals($uid2, $msgs[0]['userid']);

        # Check that the reply is not flagged as having been seen by email.
        $roster = $c->getRoster();
        $this->log("Roster " . var_export($roster, TRUE));
        foreach ($roster as $rost) {
            if ($rost['user']['id'] == $this->uid) {
                self::assertNull($rost['lastmsgemailed']);
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
        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'testgroup', 'from@test.com', "digestoff-$uid-$gid@" . USER_DOMAIN, NULL, NULL);
        $this->assertNotNull($id);
        $this->assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testEventsOff() {
        # Create the sending user
        list($u, $uid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->log("Created user $uid");

        list($g, $gid) = $this->createTestGroup("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn events off by email
        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'testgroup', 'from@test.com', "eventsoff-$uid-$gid@" . USER_DOMAIN, NULL, NULL);
        $this->assertNotNull($id);
        $this->assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testNotificationOff() {
        # Create the sending user
        list($u, $uid, $emailid) = $this->createTestUserAndLogin(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->log("Created user $uid");
        $atts = $u->getPublic();
        $this->assertTrue($atts['settings']['notificationmails']);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn off by email
        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'testgroup', 'from@test.com', "notificationmailsoff-$uid@" . USER_DOMAIN, NULL, NULL);
        $this->assertNotNull($id);
        $this->assertEquals($rc, MailRouter::TO_SYSTEM);

        $u = User::get($this->dbhm, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $this->assertFalse($atts['settings']['notificationmails']);

        }

    public function testRelevantOff() {
        # Create the sending user
        list($u, $uid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->log("Created user $uid");

        list($g, $gid) = $this->createTestGroup("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn events off by email
        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'testgroup', 'from@test.com', "relevantoff-$uid@" . USER_DOMAIN, NULL, NULL);
        $this->assertNotNull($id);
        $this->assertEquals($rc, MailRouter::TO_SYSTEM);
    }

    public function testNewslettersOff() {
        # Create the sending user
        list($u, $uid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->log("Created user $uid");

        list($g, $gid) = $this->createTestGroup("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid);

        # Turn events off by email
        list($r, $id, $failok, $rc) = $this->createTestMessage('basic', 'testgroup', 'from@test.com', "newslettersoff-$uid@" . USER_DOMAIN, NULL, NULL);
        $this->assertNotNull($id);
        $this->assertEquals($rc, MailRouter::TO_SYSTEM);

        }

    public function testVols() {
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_REUSE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-volunteers@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);

        # And again now we know them, using the auto this time.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-auto@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);

        # And with spam
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spamreply')  . "\r\nviagra\r\n");
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $this->log("Reply with spam $msg");
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-auto@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::INCOMING_SPAM, $rc);
    }

    public function testVolsHtmlOnly()
    {
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_REUSE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/htmlonly'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-volunteers@' . GROUP_DOMAIN, $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);
        $this->assertEquals("Hey.", $m->getTextbody());
    }

    public function testSubMailUnsub() {
        # Subscribe
        # Post by email when not a member.
        $this->user->removeMembership($this->gid);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id when not a member");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::DROPPED, $rc);

        # Now subscribe.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-subscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $membs = $u->getMemberships();
        $this->assertEquals(1, count($membs));

        $this->waitBackground();
        $_SESSION['id'] = $uid;
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_JOINED, $logs[$u->getId()]['logs']);
        $this->assertEquals($this->gid, $log['group']['id']);

        # Mail - first to pending for new member, moderated by default, then to approved for group settings.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::PENDING, $rc);
        $this->assertTrue($m->isPending($this->gid));

        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        self::assertEquals(MessageCollection::APPROVED, $u->postToCollection($this->gid));
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $this->assertTrue($m->isApproved($this->gid));

        # Test moderated
        $this->group->setSettings([ 'moderated' => TRUE ]);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::PENDING, $rc);
        $this->assertTrue($m->isPending($this->gid));

        # Unsubscribe

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-unsubscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $membs = $u->getMemberships();
        $this->assertEquals(0, count($membs));

        $this->waitBackground();
        $_SESSION['id'] = $uid;
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_GROUP, Log::SUBTYPE_LEFT, $logs[$u->getId()]['logs']);
        $this->assertEquals($this->gid, $log['group']['id']);

        }

    public function testReplyAll() {
        # Some people reply all to both our user and the Yahoo group.

        list($g, $gid) = $this->createTestGroup("testgroup1", Group::GROUP_REUSE);
        list($u, $uid) = $this->createTestUserWithMembership($gid, User::ROLE_MEMBER, 'Test User', 'test2@test.com', 'testpw');

        # Create the sending user
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        list($u, $uid) = $this->createTestUserWithMembership($gid, User::ROLE_MEMBER, 'Test User', $email, 'testpw');
        $this->log("Created user $uid");
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $msg = str_ireplace("FreeglePlayground", "testgroup1", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($origid, $failok) = $r->received(Message::EMAIL, $email, 'testgroup1@yahoogroups.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Send a purported reply.  This should result in the replying user being created.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_replace("To: test@test.com", "To: $email, testgroup1@yahoogroups.com", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', $email, $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Check it didn't go to a group
        $groups = $m->getGroups();
        self::assertEquals(0, count($groups));

        $uid2 = $u->findByEmail('test2@test.com');

        # Now get the chat room that this should have been placed into.
        $this->assertNotNull($uid2);
        $this->assertNotEquals($uid, $uid2);
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($uid, $uid2);
        $this->assertNotNull($rid);
        list($msgs, $users) = $c->getMessages();

        $this->log("Chat messages " . var_export($msgs, TRUE));
        $this->assertEquals(1, count($msgs));
        $this->assertEquals("I'd like to have these, then I can return them to Greece where they rightfully belong.", $msgs[0]['message']);
        $this->assertEquals($origid, $msgs[0]['refmsg']['id']);

        }

    public function testApproved() {
        list($u, $uid, $emailid) = $this->createTestUser("Test", "User", "Test User", 'test@test.com', 'testpw');
        $u->setPrivate('yahooid', 'testid');
        list($g, $gid) = $this->createTestGroup("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approved'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($mid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $groups = $m->getPublic()['groups'];
        $this->log("Groups " . var_export($groups, TRUE));
        self::assertEquals($uid, $groups[0]['approvedby']);

        }

    public function testOldYahoo() {
        list($u, $uid, $emailid) = $this->createTestUser("Test", "User", "Test User", 'test@test.com', 'testpw');
        $u->setPrivate('yahooid', 'testid');
        list($g, $gid) = $this->createTestGroup("testgroup1", Group::GROUP_REUSE);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $msg = str_replace('test@test.com', 'from@yahoogroups.com', $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approved')));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($mid, $failok) = $r->received(Message::EMAIL, 'from@yahoogroups.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

        }

    public function testModChat() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail(MODERATOR_EMAIL);

        if (!$uid) {
            list($u, $uid, $emailid) = $this->createTestUser("Test", "User", "Test User", MODERATOR_EMAIL, 'testpw');
        } else {
            $u = new User($this->dbhr, $this->dbhm, $uid);
        }
        $u->addMembership($this->gid, User::ROLE_MEMBER);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('To: "freegleplayground@yahoogroups.com" <freegleplayground@yahoogroups.com>', 'To: ' . MODERATOR_EMAIL, $msg);
        $msg = str_replace('X-Apparently-To: freegleplayground@yahoogroups.com', 'X-Apparently-To: ' . MODERATOR_EMAIL, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'testgroup-volunteers@groups.ilovefreegle.org', MODERATOR_EMAIL, $msg);
        $this->assertNotNull($id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

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
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $this->assertNotNull($m->getPublic()['worry']);
    }

    public function testBigSwitch() {
        $this->group->setPrivate('overridemoderation', Group::OVERRIDE_MODERATION_ALL);

        # Now subscribe.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-subscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $membs = $u->getMemberships();
        $this->assertEquals(1, count($membs));

        # Mail - should be pending because of big switch.
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::PENDING, $rc);
        $this->assertTrue($m->isPending($this->gid));
    }

    public function testBanned() {
        $this->user->addMembership($this->gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);

        # Post a message first.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($msgid, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'freegleplayground@' . GROUP_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $this->assertNull($m->hasOutcome());

        # Now ban u1
        $this->user->removeMembership($this->gid, TRUE);

        # u1 shouldn't be able to post by email.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msgid2 = $r->received(Message::EMAIL, 'test2@test.com', 'freegleplayground@' . GROUP_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

        # First message should have been marked as withdrawn.
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $this->assertEquals(Message::OUTCOME_WITHDRAWN, $m->hasOutcome());

        # Including spam/worry words.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('a test item', 'viagra', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($msgid, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'freegleplayground@' . GROUP_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

        # Set up worry words.
        $settings = json_decode($this->group->getPrivate('settings'), TRUE);
        $settings['spammers'] = [ 'worrywords' => 'wibble,wobble' ];
        $this->group->setSettings($settings);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Hey', 'wobble', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testCantPost() {
        # Subscribe

        # Now subscribe.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tovols'));
        $msg = str_replace("@groups.yahoo.com", GROUP_DOMAIN, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-subscribe@' . GROUP_DOMAIN, $msg);
        $this->log("Created $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        # Set ourselves to can't post.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_PROHIBITED);

        # Mail - should be dropped.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::DROPPED, $rc);
    }

    public function testSwallowTaken() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $msg = str_replace('Test native', 'OFFER: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $msg = str_replace('Test native', 'TAKEN: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);
    }

    public function testTNWithdraw() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/nativebymail'));
        $msg = str_replace('Test native', 'OFFER: thing (place)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/TNtaken'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->log("Mail message $id");
        $m2 = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m2);
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        $this->assertEquals($m->hasOutcome(), Message::OUTCOME_WITHDRAWN);
    }

    public function testReplyWithGravatar() {
        # This reply contains a gravatar which should be tripped.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/reply_with_gravatar'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, count($m->getAttachments()));
    }

    public function testWorryWords() {
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_MODERATED);
        User::clearCache();

        # Set up worry words.
        $settings = json_decode($this->group->getPrivate('settings'), TRUE);
        $settings['spammers'] = [ 'worrywords' => 'wibble,wobble' ];
        $this->group->setSettings($settings);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Hey', 'wobble', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(MessageCollection::PENDING, $m->getPublic()['groups'][0]['collection']);
        $this->assertEquals(Spam::REASON_WORRY_WORD, $m->getPrivate('spamtype'));
    }

    public function testConfused() {
        # Set up two users, each with an OFFER.
        list($u1, $uid1, $emailid1) = $this->createTestUser('Test', 'User', 'Test User', 'test1@test.com', 'testpw1');
        $u1->addMembership($this->gid);
        $u1->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        error_log("ADded {$this->gid}, {$u1->getId()}");
        list($u2, $uid2, $emailid2) = $this->createTestUser('Test', 'User', 'Test User', 'test2@test.com', 'testpw2');
        $u2->addMembership($this->gid);
        $u2->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: Offer: thing1 (place)', $msg);
        $msg = str_replace('test@test.com', 'test1@test.com', $msg);
        $msg = str_replace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id1, $failok) = $r->received(Message::EMAIL, 'test1@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->assertNotNull($id1);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $this->assertEquals($uid1, $m1->getFromuser());

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: Offer: thing2 (place)', $msg);
        $msg = str_replace('test@test.com', 'test2@test.com', $msg);
        $msg = str_replace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id2, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $this->assertNotNull($id1);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $this->assertEquals($uid2, $m2->getFromuser());

        # First user replies interested in the second user's post.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing2 (place)', $msg);
        $msg = str_replace('test@test.com', 'test2@test.com', $msg);
        $msg = str_replace('test2@test.com', 'test1@test.com', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test1@test.com', 'test2@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Chat message should exist, referencing it.
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $c->createConversation($uid1, $uid2);
        $this->assertNotNull($rid);
        error_log("Got chatid $rid for $uid1 to $uid2");

        $c = new ChatRoom($this->dbhr, $this->dbhm, $rid);
        list($msgs, $users) = $c->getMessages();
        $this->assertEquals(1, count($msgs));
        $this->assertEquals($id2, $msgs[0]['refmsg']['id']);

        # Now second user replies back.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing2 (place)', $msg);
        $msg = str_replace('test@test.com', 'test1@test.com', $msg);
        $msg = str_replace('test2@test.com', 'test2@test.com', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'test2@test.com', 'test1@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertNotNull($m->getFromuser());
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_USER, $rc);

        # Chat message should exist, but not referencing anything (specifically not referencing user1's OFFER).
        list($msgs, $users) = $c->getMessages();
        $this->assertEquals(2, count($msgs));
        error_log ("Got messages " . var_export($msgs, TRUE));
        $this->assertFalse(array_key_exists('refmsg', $msgs[1]));
    }


    public function testReplyToDigest() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytodigest'));

        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'FreeglePlayground-volunteers@' . GROUP_DOMAIN, $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);

        $chatmessages = $this->dbhr->preQuery("SELECT * FROM chat_messages_byemail WHERE msgid = ?;", [
            $id
        ]);

        $this->assertEquals(1, count($chatmessages));

        foreach ($chatmessages as $chatmessage) {
            $cm = new ChatMessage($this->dbhr, $this->dbhm, $chatmessage['chatmsgid']);
            $this->assertGreaterThan(0, strpos($cm->getPrivate('message'), '(Replied to digest)'));
        }
    }

    public function testReplyToAuto() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytoauto'));

        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'test@test.com', 'testgroup-auto@' . GROUP_DOMAIN, $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $rc = $r->route($m);
        $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);

        $chatmessages = $this->dbhr->preQuery("SELECT * FROM chat_messages_byemail WHERE msgid = ?;", [
            $id
        ]);

        $this->assertEquals(1, count($chatmessages));

        foreach ($chatmessages as $chatmessage) {
            $cm = new ChatMessage($this->dbhr, $this->dbhm, $chatmessage['chatmsgid']);
            $this->assertGreaterThan(0, strpos($cm->getPrivate('message'), '(Replied to digest)'));
        }
    }

    public function testOneClickUnsubscribe() {
        $key = $this->user->getUserKey($this->user->getId());

        $r = new MailRouter($this->dbhr, $this->dbhm);

        # Mail an invalid key.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        list ($id, $failok) = $r->received(Message::EMAIL, $this->user->getEmailPreferred(), "unsubscribe-{$this->uid}-1-a@" . USER_DOMAIN, $msg);
        $this->assertNotNull($id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::DROPPED, $rc);

        # Mail a valid key.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        list ($id, $failok) = $r->received(Message::EMAIL, $this->user->getEmailPreferred(), "unsubscribe-{$this->uid}-$key-a@" . USER_DOMAIN, $msg);
        $this->assertNotNull($id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        $u = new User($this->dbhr, $this->dbhm, $this->uid);
        $this->assertNotNull($u->getPrivate('deleted'));
    }

    public function testToOld() {
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
        list ($origid, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($origid);
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Mark the message as ancient.
        $this->dbhm->preExec("UPDATE messages_history SET arrival = ? WHERE msgid = ?;", [
            '2000-01-01 00:00:00',
            $origid
        ]);

        # Send a reply.  This should be dropped as too old.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Subject: Re: Basic test', 'Subject: Re: [Group-tag] Offer: thing (place)', $msg);
        $r->received(Message::EMAIL, 'test2@test.com', 'replyto-' . $origid . '-' . $uid2 . '@' . USER_DOMAIN, $msg);
        $this->assertEquals(MailRouter::DROPPED, $r->route());
    }

    public function testFBL() {
        // Test that an FBL report turns off email.
        $u = new User($this->dbhr, $this->dbhm, $this->uid);
        $this->assertEquals($u->getSetting('simplemail', NULL), NULL);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/fbl'));
        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', FBL_ADDR, $msg);
        $this->assertNotNull($id);
        $rc = $r->route();
        $this->assertEquals($rc, MailRouter::TO_SYSTEM);
        $u = new User($this->dbhr, $this->dbhm, $this->uid);
        $this->assertEquals($u->getSetting('simplemail', NULL), User::SIMPLE_MAIL_NONE);
    }

    public function expandProvider() {
        return [
            [ 'basic', [ 'www.microsoft.com' ] ],
            [ 'link_expansion', [ 'www.adobe.com', 'personal.nedbank.co.za' ] ]
        ];
    }

    /**
     * @dataProvider expandProvider
     */
    public function testExpandUrls($file, $urls) {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');
        $this->user->addMembership($gid);
        $this->user->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $u = User::get($this->dbhr, $this->dbhm);

        # Create a different user which will cause a merge.
        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u2 = User::get($this->dbhr, $this->dbhm, $uid2);
        $this->assertGreaterThan(0, $u->addEmail('test2@test.com'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/' . $file));
        $msg = str_replace("X-Yahoo-Group-Post: member; u=420816297", "X-Yahoo-Group-Post: member; u=-1", $msg);
        $msg = str_replace('Hey', 'Text body with http://microsoft.com which should expand', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $this->assertNotNull($id);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        foreach ($urls as $url) {
            $this->assertStringContainsString($url, $m->getPublic()['textbody']);
        }
    }

    //    public function testSpecial() {
//        //
//        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/special'));
//        $r = new MailRouter($this->dbhr, $this->dbhm);
//        $m = new Message($this->dbhr, $this->dbhm, 25206247);
//        $r->route($m);
//       list ($id, $failok) = $r->received(Message::EMAIL, 'xxxxxxx@ntlworld.com', "hertfordfreegle-volunteers@groups.ilovefreegle.org", $msg);
//        $this->assertNotNull($id);
//        $rc = $r->route();
//        $this->assertEquals(MailRouter::TO_VOLUNTEERS, $rc);
//
//        //    }
}


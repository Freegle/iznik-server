<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/mail/Digest.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class digestTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $msgsSent = [];

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->msgsSent = [];

        $this->tidy();
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    public function testImmediate() {
        # Mock the actual send
        $mock = $this->getMockBuilder('Digest')
            ->setConstructorArgs([$this->dbhm, $this->dbhm])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a group with a message on it.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@blackhole.io');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        assertEquals(1, $mock->send($gid, Digest::IMMEDIATE));
        assertEquals(1, count($this->msgsSent));
        $this->log("Immediate message " . $this->msgsSent[0]);

        }

    public function testSend() {
        # Actual send for coverage.
        $d = new Digest($this->dbhm, $this->dbhm, NULL, TRUE);

        # Create a group with a message on it.
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@blackhole.io');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        # And another who only has a membership on Yahoo and therefore shouldn't get one.
        $u2 = User::get($this->dbhm, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        $u2->addEmail('test2@blackhole.io');
        $this->log("Created user $uid2");
        $u2->addMembership($gid, User::ROLE_MEMBER);
        $u2->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        assertEquals(1, $d->send($gid, Digest::IMMEDIATE));

        # Again - nothing to send.
        assertEquals(0, $d->send($gid, Digest::IMMEDIATE));

        # Now add one of our emails to the second user.  Because we've not sync'd this group, we will decide to send
        # an email.
        $this->log("Now with our email");
        $eid2 = $u2->addEmail('test2@' . USER_DOMAIN);
        $this->log("Added eid $eid2");
        assertGreaterThan(0, $eid2);
        $this->dbhm->preExec("DELETE FROM groups_digests WHERE groupid = ?;", [ $gid ]);

        # Force pick up of new email.
        User::$cache = [];
        assertEquals(2, $d->send($gid, Digest::IMMEDIATE));

        }

    public function testTN() {
        # Actual send for coverage.
        $d = new Digest($this->dbhm, $this->dbhm);

        # Create a group with a message on it.
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 0);
        $g->setPrivate('onhere', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery, but who is a TN user and therefore
        # shouldn't get one.
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $eid = $u->addEmail('test@user.trashnothing.com');
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        assertEquals(0, $d->send($gid, Digest::IMMEDIATE));

        }

    public function testError() {
        # Create a group with a message on it.
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@blackhole.io');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        # And another who only has a membership on Yahoo and therefore shouldn't get one.
        $u2 = User::get($this->dbhm, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        $u2->addEmail('test2@blackhole.io');
        $this->log("Created user $uid2");
        $u2->addMembership($gid, User::ROLE_MEMBER);
        $u2->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);

        # Mock for coverage.
        $mock = $this->getMockBuilder('Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->willThrowException(new Exception());
        $mock->send($gid, Digest::IMMEDIATE);

        }

    public function testMultipleMails() {
        # Mock the actual send
        $mock = $this->getMockBuilder('Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a group with two messages on it, one taken.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', TRUE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test thing (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'TAKEN: Test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@blackhole.io');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($gid, 'emailfrequency', Digest::HOUR1);

        # Now test.
        assertEquals(1, $mock->send($gid, Digest::HOUR1));
        assertEquals(1, count($this->msgsSent));
        
        # Again - nothing to send.
        assertEquals(0, $mock->send($gid, Digest::HOUR1));

        }
//
//    public function testNewDigestSingle() {
//        //
//        # Actual send for coverage.
//        $d = new Digest($this->dbhm, $this->dbhm);
//
//        # Create a group with a message on it.
//        $g = Group::get($this->dbhm, $this->dbhm);
//        $gid = $g->create("testgroup", Group::GROUP_REUSE);
//        $g->setPrivate('onyahoo', 0);
//        $g->setPrivate('onhere', 1);
//        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
//        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
//        $msg = str_replace('Test att', 'OFFER: Test item (location)', $msg);
//
//        $r = new MailRouter($this->dbhm, $this->dbhm);
//        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
//        assertNotNull($id);
//        $this->log("Created message $id");
//        $rc = $r->route();
//        assertEquals(MailRouter::APPROVED, $rc);
//
//        $tosends = explode(',',
////            'freegle@litmustest.com'
//            "barracuda@barracuda.emailtests.com, previews_98@gmx.de, litmuscheck03@gmail.com, litmuscheck03@yahoo.com, litmuscheck01@mail.com, litmuscheck01@outlook.com, litmuscheck04@emailtests.onmicrosoft.com, previews_99@web.de, litmuscheck02@mail.ru, litmuscheck10@gmail.com, litmuscheck05@gapps.emailtests.com, litmuscheck01@ms.emailtests.com, litmustestprod05@gd-testing.com, litmustestprod01@yandex.com, litmuscheck001@aol.com, f89afdffe9@s.litmustest.com, f89afdffe9@sg3.emailtests.com, f89afdffe9@ml.emailtests.com"
//        );
//
//        foreach ($tosends as $tosend) {
//            $tosend = trim($tosend);
//            $u = User::get($this->dbhm, $this->dbhm);
//            $uid = $u->findByEmail($tosend);
//
//            if (!$uid) {
//                $this->log("Add user for $tosend");
//                $uid = $u->create("Test", "User", "Test User");
//                $u->addEmail($tosend);
//            }
//
//            $u = User::get($this->dbhm, $this->dbhm, $uid);
//            $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email LIKE ?;", [
//                $u->getEmailPreferred()
//            ]);
//
//            foreach ($emails as $email) {
//                $eid = $email['id'];
//                $this->log("Found eid $eid");
//                $u->addMembership($gid, User::ROLE_MEMBER, $eid);
//                $u->setMembershipAtt($gid, 'emailfrequency', Digest::IMMEDIATE);
//            }
//        }
//
//        # Now test.
//        assertGreaterThan(0, $d->send($gid, Digest::IMMEDIATE));
//
//        //    }
//
//    public function testNewDigestMultiple() {
//        //
//        # Create a group with two messages on it.
//        $g = Group::get($this->dbhm, $this->dbhm);
//        $gid = $g->create("testgroup", Group::GROUP_REUSE);
//        $g->setPrivate('onyahoo', 0);
//        $g->setPrivate('onhere', 1);
//        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
//        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
//        $msg = str_replace('Test att', 'OFFER: Test 1 (location)', $msg);
//
//        $r = new MailRouter($this->dbhm, $this->dbhm);
//        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
//        assertNotNull($id);
//        $this->log("Created message $id");
//        $rc = $r->route();
//        assertEquals(MailRouter::APPROVED, $rc);
//
//        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
//        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
//        $msg = str_replace('Test att', 'OFFER: Test 2 (location)', $msg);
//
//        $r = new MailRouter($this->dbhm, $this->dbhm);
//        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
//        assertNotNull($id);
//        $this->log("Created message $id");
//        $rc = $r->route();
//        assertEquals(MailRouter::APPROVED, $rc);
//
//        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
//        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
//        $msg = str_replace('Test att', 'OFFER: Test 3 (location)', $msg);
//
//        $r = new MailRouter($this->dbhm, $this->dbhm);
//        $id = $r->received(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg, $gid);
//        assertNotNull($id);
//        $this->log("Created message $id");
//        $rc = $r->route();
//        assertEquals(MailRouter::APPROVED, $rc);
//        $m = new Message($this->dbhr, $this->dbhm, $id);
//        $m->mark(Message::OUTCOME_TAKEN, 'Ta', User::HAPPY, NULL);
//
//        $sendtos = explode(',',
////            "barracuda@barracuda.emailtests.com, previews_98@gmx.de, litmuscheck03@gmail.com, litmuscheck05@yahoo.com, litmuscheck03@mail.com, litmuscheck05@outlook.com, litmuscheck03@emailtests.onmicrosoft.com, previews_96@web.de, litmuscheck01@mail.ru, litmuscheck06@gmail.com, litmuscheck03@gapps.emailtests.com, litmuscheck05@ms.emailtests.com, litmustestprod02@gd-testing.com, litmustestprod02@yandex.com, litmuscheck003@aol.com, 405199fca5@s.litmustest.com, 405199fca5@sg3.emailtests.com, 405199fca5@ml.emailtests.com"
////        'edward@ehibbert.org.uk'
//        'freegle@litmustest.com'
////        'edwardhibbert59@gmail.com'
//        );
//
//        $d = new Digest($this->dbhm, $this->dbhm, NULL, TRUE);
//
//        foreach ($sendtos as $sendto) {
//            $sendto = trim($sendto);
//            $this->log("Send to $sendto");
//            $u = User::get($this->dbhm, $this->dbhm);
//            $uid = $u->findByEmail($sendto);
//
//            if (!$uid) {
//                $this->log("Add user for $sendto");
//                $uid = $u->create("Test", "User", "Test User");
//                $u->addEmail($sendto);
//            }
//
//            $u = User::get($this->dbhm, $this->dbhm, $uid);
//            $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email LIKE ?;", [
//                $u->getEmailPreferred()
//            ]);
//
//            foreach ($emails as $email) {
//                $eid = $email['id'];
//                $this->log("Found eid $eid for {$email['email']}");
//                $u->addMembership($gid, User::ROLE_MEMBER, $eid);
//                $u->setMembershipAtt($gid, 'emailfrequency', Digest::HOUR1);
//            }
//        }
//
//        # Now test.
//        $this->log("Now send");
//        assertGreaterThan(0, $d->send($gid, Digest::HOUR1));
//
//        //    }
}


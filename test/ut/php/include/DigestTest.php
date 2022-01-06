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

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->user = $u;
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    public function testImmediate() {
        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\Digest')
            ->setConstructorArgs([$this->dbhm, $this->dbhm])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a group with a message on it.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
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
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        assertEquals(1, $mock->send($this->gid, Digest::IMMEDIATE));
        assertEquals(1, count($this->msgsSent));
        $this->log("Immediate message " . $this->msgsSent[0]);

        }

    public function testSend() {
        # Actual send for coverage.
        $d = new Digest($this->dbhm, $this->dbhm, NULL, TRUE);

        # Create a group with a message on it.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
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
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # And another who only has a membership on Yahoo and therefore shouldn't get one.
        $u2 = User::get($this->dbhm, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        $u2->addEmail('test2@blackhole.io');
        $this->log("Created user $uid2");
        $u2->addMembership($this->gid, User::ROLE_MEMBER);
        $u2->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test - 1 for our user and one for the sender of the mail.
        assertEquals(2, $d->send($this->gid, Digest::IMMEDIATE));

        # Again - nothing to send.
        assertEquals(0, $d->send($this->gid, Digest::IMMEDIATE));

        # Now add one of our emails to the second user.  Because we've not sync'd this group, we will decide to send
        # an email.
        $this->log("Now with our email");
        $eid2 = $u2->addEmail('test2@' . USER_DOMAIN);
        $this->log("Added eid $eid2");
        assertGreaterThan(0, $eid2);
        $this->dbhm->preExec("DELETE FROM groups_digests WHERE groupid = ?;", [ $this->gid ]);

        # Force pick up of new email.
        User::$cache = [];
        assertEquals(2, $d->send($this->gid, Digest::IMMEDIATE));

        }

    public function testTN() {
        # Actual send for coverage.
        $d = new Digest($this->dbhm, $this->dbhm);

        # Create a group with a message on it.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
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
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        assertEquals(0, $d->send($this->gid, Digest::IMMEDIATE));

        }

    public function testError() {
        # Create a group with a message on it.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
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
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # And another who only has a membership on Yahoo and therefore shouldn't get one.
        $u2 = User::get($this->dbhm, $this->dbhm);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        $u2->addEmail('test2@blackhole.io');
        $this->log("Created user $uid2");
        $u2->addMembership($this->gid, User::ROLE_MEMBER);
        $u2->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Mock for coverage.
        $mock = $this->getMockBuilder('Freegle\Iznik\Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->willThrowException(new \Exception());
        $mock->send($this->gid, Digest::IMMEDIATE);

        }

    public function testMultipleMails() {
        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a group with two messages on it, one taken.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test thing (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'TAKEN: Test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::TO_SYSTEM, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@blackhole.io');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::HOUR1);

        # Now test.
        assertEquals(1, $mock->send($this->gid, Digest::HOUR1));
        assertEquals(1, count($this->msgsSent));
        
        # Again - nothing to send.
        assertEquals(0, $mock->send($this->gid, Digest::HOUR1));
    }

    /**
     * @param $withdraw
     * @dataProvider nearbyProvider
     */
    public function testNearby($withdraw) {
        # Two groups.
        $g = new Group($this->dbhr, $this->dbhm);
        $gid1 = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g->setSettings([
                            'nearbygroups' => 1
                        ]);
        $gid2 = $g->create('testgroup2', Group::GROUP_FREEGLE);
        $g->setSettings([
                            'nearbygroups' => 2
                        ]);

        # A message on each.
        $this->user->addMembership($gid1);
        $this->user->setMembershipAtt($gid1, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->user->addMembership($gid2);
        $this->user->setMembershipAtt($gid2, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup1", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item 1 (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id1 = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid1);
        assertNotNull($id1);
        $this->log("Created message $id1");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup2", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item 2 (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id2 = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid2);
        assertNotNull($id2);
        $this->log("Created message $id2");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a user on the first group.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@blackhole.io');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        assertGreaterThan(0, $eid);
        $u->addMembership($gid1, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($gid1, 'emailfrequency', Digest::HOUR1);

        # Put the messages a bit apart.
        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m1->setPrivate('lng', 179.19);
        $m1->setPrivate('lat', 8.51);

        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        $m2->setPrivate('lng', 179.195);
        $m2->setPrivate('lat', 8.515);

        $m1->updateSpatialIndex();

        # Put the user near the first one.
        $u->setSetting('mylocation', [
            'lng' => 179.20,
            'lat' => 8.51
        ]);

        if ($withdraw) {
            $m2->withdraw(NULL, User::FINE);
        }

        # Send digest.
        assertEquals(1, $mock->send($gid1, Digest::HOUR1, 'localhost', NULL, TRUE, TRUE));
        assertEquals(1, count($this->msgsSent));

        if ($withdraw) {
            // Completed messages shouldn't appear.
            assertFalse(strpos($this->msgsSent[0], 'Test item 2'));
        } else {
            // Nearby message should appear.
            assertNotFalse(strpos($this->msgsSent[0], 'Test item 2'));
        }
    }

    function nearbyProvider() {
        return [
            [ FALSE ],
            [ TRUE]
        ];
    }
}


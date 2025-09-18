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
class DigestTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $msgsSent = [];

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->msgsSent = [];
        
        $this->tidy();

        list($this->group, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $this->group->setPrivate('onhere', 1);

        list($this->user, $this->uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test@test.com', 'testpw');
        $this->user->addEmail('sender@example.net');
        $this->user->addMembership($this->gid);
        $this->user->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        
        # Also add to FreeglePlayground group which is used by some legacy tests
        $g = Group::get($this->dbhr, $this->dbhm);
        $fgid = $g->findByShortName('FreeglePlayground');
        if ($fgid) {
            $this->user->addMembership($fgid);
            $this->user->setMembershipAtt($fgid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        }
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
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (location)',
            'Hey' => 'Hey {{username}}',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        list($u, $uid, $eid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@blackhole.io', 'testpw');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        $this->assertEquals(1, $mock->send($this->gid, Digest::IMMEDIATE));
        $this->assertEquals(1, count($this->msgsSent));
        $this->log("Immediate message " . $this->msgsSent[0]);

        }

    public function testSend() {
        # Actual send for coverage.
        $d = new Digest($this->dbhm, $this->dbhm, NULL, TRUE);

        # Create a group with a message on it.
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (location)',
            'Hey' => 'Hey {{username}}',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        list($u, $uid, $eid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@blackhole.io', 'testpw');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # And another who only has a membership on Yahoo and therefore shouldn't get one.
        list($u2, $uid2, $eid2) = $this->createTestUser(NULL, NULL, 'Test User', 'test2@blackhole.io', 'testpw');
        $this->log("Created user $uid2");
        $u2->addMembership($this->gid, User::ROLE_MEMBER);
        $u2->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test - 1 for our user and one for the sender of the mail.
        $this->assertEquals(2, $d->send($this->gid, Digest::IMMEDIATE));

        # Again - nothing to send.
        $this->assertEquals(0, $d->send($this->gid, Digest::IMMEDIATE));

        # Now add one of our emails to the second user.  Because we've not sync'd this group, we will decide to send
        # an email.
        $this->log("Now with our email");
        $eid2 = $u2->addEmail('test2@' . USER_DOMAIN);
        $this->log("Added eid $eid2");
        $this->assertGreaterThan(0, $eid2);
        $this->dbhm->preExec("DELETE FROM groups_digests WHERE groupid = ?;", [ $this->gid ]);

        # Force pick up of new email.
        User::$cache = [];
        $this->assertEquals(2, $d->send($this->gid, Digest::IMMEDIATE));

        }

    public function testTN() {
        # Actual send for coverage.
        $d = new Digest($this->dbhm, $this->dbhm);

        # Create a group with a message on it.
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (location)',
            'Hey' => 'Hey {{username}}',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery, but who is a TN user and therefore
        # shouldn't get one.
        list($u, $uid, $eid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@user.trashnothing.com', 'testpw');
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # Now test.
        $this->assertEquals(0, $d->send($this->gid, Digest::IMMEDIATE));
    }

    public function testError() {
        # Create a group with a message on it.
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item (location)',
            'Hey' => 'Hey {{username}}',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        list($u, $uid, $eid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@blackhole.io', 'testpw');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::IMMEDIATE);

        # And another who only has a membership on Yahoo and therefore shouldn't get one.
        list($u2, $uid2, $eid2) = $this->createTestUser(NULL, NULL, 'Test User', 'test2@blackhole.io', 'testpw');
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
        $msg = str_ireplace("freegleplayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test thing (location)'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);
        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'TAKEN: Test item (location)'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $this->gid);
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::TO_SYSTEM, $rc);

        # Create a user on that group who wants immediate delivery.  They need two emails; one for our membership,
        # and a real one to get the digest.
        list($u, $uid, $eid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@blackhole.io', 'testpw');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);
        $u->setMembershipAtt($this->gid, 'emailfrequency', Digest::HOUR1);

        # Now test.
        $this->assertEquals(1, $mock->send($this->gid, Digest::HOUR1));
        $this->assertEquals(1, count($this->msgsSent));
        
        # Again - nothing to send.
        $this->assertEquals(0, $mock->send($this->gid, Digest::HOUR1));
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

        $substitutions = [
            'freegleplayground' => 'testgroup1',
            'Basic test' => 'OFFER: Test item 1 (location)',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);
        list($r, $id1, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id1);
        $this->log("Created message $id1");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $substitutions = [
            'freegleplayground' => 'testgroup2',
            'Basic test' => 'OFFER: Test item 2 (location)',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);
        list($r, $id2, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id2);
        $this->log("Created message $id2");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a user on the first group.
        list($u, $uid, $eid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@blackhole.io', 'testpw');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
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
        $this->assertEquals(1, $mock->send($gid1, Digest::HOUR1, 'localhost', NULL, TRUE, TRUE));
        $this->assertEquals(1, count($this->msgsSent));

        if ($withdraw) {
            // Completed messages shouldn't appear.
            $this->assertFalse(strpos($this->msgsSent[0], 'Test item 2'));
        } else {
            // Nearby message should appear.
            $this->assertNotFalse(strpos($this->msgsSent[0], 'Test item 2'));
        }
    }

    function nearbyProvider() {
        return [
            [ FALSE ],
            [ TRUE]
        ];
    }

    public function testLongItem() {
        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\Digest')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, NULL, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        # Create a group with two messages on it.
        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test item Test item Test item Test item Test item Test item (location)',
            'test@test.com' => 'sender@example.net'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);
        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $substitutions = [
            'freegleplayground' => 'testgroup',
            'Basic test' => 'OFFER: Test thing  thing  thing  thing  thing  thing  thing  thing  thing  thing (location)'
        ];
        $msg = $this->createMessageContent('basic', $substitutions);
        $msg = $this->unique($msg);
        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'sender@example.net', 'to@test.com');
        $this->assertNotNull($id);
        $this->log("Created message $id");
        $this->assertEquals(MailRouter::APPROVED, $rc);

        list($u, $uid, $eid1) = $this->createTestUser(NULL, NULL, 'Test User', 'test@blackhole.io', 'testpw');
        $eid = $u->addEmail('test@' . USER_DOMAIN);
        $this->log("Created user $uid email $eid");
        $this->assertGreaterThan(0, $eid);
        $u->addMembership($this->gid, User::ROLE_MEMBER, $eid);

        # Now test.
        $this->assertEquals(2, $mock->send($this->gid, Digest::DAILY));
        $this->assertEquals(2, count($this->msgsSent));

        # Should include the long subject
        $this->assertNotFalse(strpos($this->msgsSent[0], 'Subject: [testgroup] What\'s New (2 messages) - Test item Test item Test'));
        $this->assertNotFalse(strpos($this->msgsSent[0], ' Test item Test item Test item...'));
    }
}


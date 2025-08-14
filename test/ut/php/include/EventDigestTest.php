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
class eventDigestTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $eventsSent = [];

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->msgsSent = [];

        $this->tidy();
    }

    public function sendMock($mailer, $message) {
        $this->eventsSent[] = $message->toString();
    }

    public function testEvents() {
        # Create a group with two events on it.
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_REUSE);

        # And two users, one who wants events and one who doesn't.
        list($user1, $uid1, $eid1) = $this->createTestUser(NULL, NULL, "Test User", 'test1@test.com', 'testpw');
        $user1->addEmail('test1@' . USER_DOMAIN);
        $user1->addMembership($gid, User::ROLE_MEMBER, $eid1);
        $user1->setMembershipAtt($gid, 'eventsallowed', 0);
        list($user2, $uid2, $eid2) = $this->createTestUser(NULL, NULL, "Test User", 'test2@test.com', 'testpw');
        $user2->addEmail('test2@' . USER_DOMAIN);
        $user2->addMembership($gid, User::ROLE_MEMBER, $eid2);

        $e = new CommunityEvent($this->dbhr, $this->dbhm);
        $e->create($uid1, 'Test Event 1', 'Test Location', 'Test Contact Name', '000 000 000', 'test@test.com', 'http://ilovefreegle.org', 'A test event');
        $e->addGroup($gid);
        $e->addDate(Utils::ISODate('@' . strtotime('next monday 10am')), Utils::ISODate('@' . strtotime('next monday 11am')));
        $e->addDate(Utils::ISODate('@' . strtotime('next tuesday 10am')), Utils::ISODate('@' . strtotime('next tuesday 11am')));

        $e->create($uid1, 'Test Event 2', 'Test Location', 'Test Contact Name', '000 000 000', 'test@test.com', 'http://ilovefreegle.org', 'A test event');
        $e->addGroup($gid);
        $e->addDate(Utils::ISODate('@' . strtotime('next wednesday 2pm')), Utils::ISODate('@' . strtotime('next wednesday 3pm')));

        # Fake approve.
        $e->setPrivate('pending', 0);

        # Now test.

        # Send fails
        $mock = $this->getMockBuilder('Freegle\Iznik\EventDigest')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->willThrowException(new \Exception());
        $this->assertEquals(0, $mock->send($gid));

        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\EventDigest')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));
        $this->assertEquals(1, $mock->send($gid));
        $this->assertEquals(1, count($this->eventsSent));

        $this->log("Mail sent" . var_export($this->eventsSent, TRUE));

        # Actual send for coverage.
        $d = new EventDigest($this->dbhr, $this->dbhm);
        $this->assertEquals(1, $d->send($gid));

        # Turn off
        $mock->off($uid2, $gid);

        $this->assertEquals(0, $mock->send($gid));

        # Invalid email
        $u = User::get($this->dbhr, $this->dbhm);
        $uid3 = $u->create(NULL, NULL, "Test User");
        $u->addEmail('test.com');
        $u->addMembership($gid);
        $this->assertEquals(0, $mock->send($gid));

        $this->log("For coverage");
        $e = new EventDigest($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('SwiftMailer')
            ->setMethods(array('send'))
            ->getMock();
        $mock->method('send')->willThrowException(new \Exception());
        try {
            $e->sendOne($mock, NULL);
            $this->assertTrue(FALSE);
        } catch (\Exception $e){}
    }
}


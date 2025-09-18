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
class VolunteeringDigestTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $volunteeringSent = [];

    protected function tearDown() : void {
        parent::tearDown ();
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test vacancy';");
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title LIKE 'Test volunteering%';");
        $this->dbhm->preExec("DELETE FROM volunteering WHERE title LIKE 'Test Op %';");
    }

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
        $this->volunteeringSent[] = $message->toString();
    }

    public function testEvents() {
        # Create a group with two opportunities on it.
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_REUSE);

        # And two users, one who wants them and one who doesn't.
        list($u1, $uid1, $eid1) = $this->createTestUser(NULL, NULL, "Test User", 'test1@test.com', 'testpw1');
        $u1->addEmail('test1@' . USER_DOMAIN);
        $u1->addMembership($gid, User::ROLE_MEMBER, $eid1);
        $u1->setMembershipAtt($gid, 'volunteeringallowed', 0);
        list($u2, $uid2, $eid2) = $this->createTestUser(NULL, NULL, "Test User", 'test2@test.com', 'testpw2');
        $u2->addEmail('test2@' . USER_DOMAIN);
        $u2->addMembership($gid, User::ROLE_MEMBER, $eid2);

        list($e1, $eid1) = $this->createTestVolunteeringWithGroup($uid1, 'Test Volunteering 1', $gid);
        list($e2, $eid2) = $this->createTestVolunteeringWithGroup($uid1, 'Test Volunteering 2', $gid);

        # Now test.

        # Send fails
        $mock = $this->getMockBuilder('Freegle\Iznik\VolunteeringDigest')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->willThrowException(new \Exception());
        $this->assertEquals(0, $mock->send($gid));

        # Mock the actual send
        $mock = $this->getMockBuilder('Freegle\Iznik\VolunteeringDigest')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, TRUE])
            ->setMethods(array('sendOne'))
            ->getMock();
        $mock->method('sendOne')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));
        $this->assertEquals(1, $mock->send($gid));
        $this->assertEquals(1, count($this->volunteeringSent));

        $this->log("Mail sent" . var_export($this->volunteeringSent, TRUE));

        # Actual send for coverage.
        $d = new VolunteeringDigest($this->dbhr, $this->dbhm);
        $this->assertEquals(1, $d->send($gid));

        # Turn off
        $mock->off($uid2, $gid);

        $this->assertEquals(0, $mock->send($gid));

        # Invalid email
        list($u3, $uid3, $eid3) = $this->createTestUser(NULL, NULL, "Test User", NULL, 'testpw3');
        $u3->addEmail('test.com'); # Invalid email (no @ symbol)
        $u3->addMembership($gid);
        $this->assertEquals(0, $mock->send($gid));

        $this->log("For coverage");
        $e = new VolunteeringDigest($this->dbhr, $this->dbhm);
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


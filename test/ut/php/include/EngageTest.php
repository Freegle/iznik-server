<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/Engage.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class engageTest extends IznikTestCase {
    private $dbhr, $dbhm, $msgsSent;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->msgsSent = [];

        $this->tidy();

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $g->create("testgroup", Group::GROUP_REUSE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
    }

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    public function testDonorsMissing() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        $u->setPrivate('lastaccess', date("Y-m-d", strtotime("3 months ago")));
        $this->dbhm->preExec("INSERT INTO users_donations (userid, timestamp, GrossAmount) VALUES (?, ?, 0);", [
            $uid,
            date("Y-m-d", strtotime('9 months ago'))
        ]);

        $e = $this->getMockBuilder('Engage')
            ->setConstructorArgs([$this->dbhm, $this->dbhm])
            ->setMethods(array('sendOne'))
            ->getMock();
        $e->method('sendOne')->will($this->returnCallback(function ($mailer, $message) {
            return ($this->sendMock($mailer, $message));
        }));

        $uids = $e->findUsers($uid, Engage::FILTER_DONORS);
        assertEquals([ $uid ], $uids);

        assertEquals(1, $e->sendUsers(Engage::ATTEMPT_MISSING, $uids, "We miss you!", "We don't think you've freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org", 'missing.html'));
        assertEquals(1, count($this->msgsSent));

        $uids = $e->findUsers($uid, Engage::FILTER_DONORS);
        assertEquals(0, count($uids));

        assertEquals(0, $e->checkSuccess($uid));
        $u->setPrivate('lastaccess', date("Y-m-d H:i:s", time()));
        assertEquals(1, $e->checkSuccess($uid));

    }
}


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
    private $dbhr, $dbhm;

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

    public function testDonorsMissing() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $u->setPrivate('lastaccess', date("Y-m-d", strtotime("3 months ago")));
        $this->dbhm->preExec("INSERT INTO users_donations (userid, timestamp, GrossAmount) VALUES (?, ?, 0);", [
            $uid,
            date("Y-m-d", strtotime('9 months ago'))
        ]);

        $e = new Engage($this->dbhr, $this->dbhm);
        $uids = $e->findUsers($uid, Engage::FILTER_DONORS);
        assertEquals([ $uid ], $uids);
        $e->recordEngage($uid, Engage::ATTEMPT_MISSING);
        $uids = $e->findUsers($uid, Engage::FILTER_DONORS);
        assertEquals(0, count($uids));

        assertEquals(0, $e->checkSuccess($uid));
        $u->setPrivate('lastaccess', date("Y-m-d H:i:s", time()));
        assertEquals(1, $e->checkSuccess($uid));
    }
}


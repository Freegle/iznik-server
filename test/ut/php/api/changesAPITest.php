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
class changesAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        list($g, $group1) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', $email, 'testpw');
        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->addLoginAndLogin($u, 'testpw');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        list($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup', $email, 'to@test.com', $group1, $uid);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Post a message to show up.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'happiness' => User::FINE,
            'userid' => $uid
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Rate someone to show up.
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u->rate($uid, $uid2, User::RATING_UP);
        $this->waitBackground();

        # Fake the rating visible.
        $this->dbhm->preExec("UPDATE ratings SET visible = 1 WHERE ratee = ?;", [
            $uid2
        ]);
        # Fake a partner session to get the rating sreturned.
        $_SESSION['partner'] = TRUE;

        $ret = $this->call('changes', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['changes']['messages'][0]['id']);
        $this->assertEquals(1, count($ret['changes']['ratings']));
    }
}

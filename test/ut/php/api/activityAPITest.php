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
 * Test change to trigger sync
 */
class activityAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

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
        # Ensure there is a message.
        $email = 'test-' . rand() . '@blackhole.io';

        list($g, $group1) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        list($u, $uid, $emailid) = $this->createTestUserWithMembershipAndLogin($group1, User::ROLE_MEMBER, NULL, NULL, 'Test User', $email, 'testpw');
        $u->addEmail('test@test.com');
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        list($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup', $email, 'to@test.com', $group1, $uid, []);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $ret = $this->call('activity', 'GET', [ 'grouptype' => Group::GROUP_REUSE ]);
        $this->log("Activity " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);

        $found = FALSE;

        foreach ($ret['recentmessages'] as $msg) {
            if ($msg['message']['id'] == $id) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        }
}

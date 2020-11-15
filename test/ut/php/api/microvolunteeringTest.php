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
class microvolunteeringAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp()
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
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->setPrivate('lat', 8.5);
        $m->setPrivate('lng', 179.3);
        $m->addToSpatialIndex();

        # Ask for a volunteering task from this group - not logged in.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(1, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Ask again - logged in.
        $ret = $this->call('microvolunteering', 'GET', [
            'groupid' => $gid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(MicroVolunteering::CHALLENGE_CHECK_MESSAGE, $ret['microvolunteering']['type']);
        assertEquals($id, $ret['microvolunteering']['msgid']);

        # Ask again - no specific group and no memberships
        $ret = $this->call('microvolunteering', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('microvolunteering', $ret));

        # Response
        $ret = $this->call('microvolunteering', 'POST', [
            'msgid' => $id,
            'response' => MicroVolunteering::RESULT_APPROVE
        ]);

        assertEquals(0, $ret['ret']);
    }
}

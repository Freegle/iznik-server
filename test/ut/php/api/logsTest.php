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
class logsAPITest extends IznikAPITestCase
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
        # Create a group, put a message on it.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        assertNotNull($gid);

        # Put a message on the group.
        $u = User::get($this->dbhr, $this->dbhr);
        $uid1 = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $u->addMembership($gid);
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/approved'));
        $msg = str_ireplace("FreeglePlayground", "testgroup", $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'test@test.com', 'testgroup@' . GROUP_DOMAIN, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        # Logged out shouldn't be able to see.
        $ret = $this->call('logs', 'GET', [
            'logtype' => Log::TYPE_GROUP,
            'logsubtype'=> Log::SUBTYPE_JOINED,
            'groupid' => $gid
        ]);
        assertEquals(2, $ret['ret']);

        # User shouldn't be able to see the logs.
        $u = User::get($this->dbhr, $this->dbhr);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid2);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        assertTrue($u->login('testpw'));

        $ret = $this->call('logs', 'GET', [
            'logtype' => 'memberships',
            'logsubtype'=> Log::SUBTYPE_JOINED,
            'groupid' => $gid
        ]);
        assertEquals(2, $ret['ret']);

        $u->addMembership($gid, User::ROLE_MODERATOR, NULL, MembershipCollection::APPROVED);
        assertTrue($u->login('testpw'));

        $ret = $this->call('logs', 'GET', [
            'logtype' => 'memberships',
            'logsubtype'=> Log::SUBTYPE_JOINED,
            'groupid' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($uid2, $ret['logs'][0]['user']['id']);

        $ret = $this->call('logs', 'GET', [
            'logtype' => 'messages',
            'logsubtype'=> Log::SUBTYPE_RECEIVED,
            'groupid' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        error_log(var_export($ret, TRUE));
        assertEquals($mid, $ret['logs'][0]['message']['id']);

        $ret = $this->call('logs', 'GET', [
            'logtype' => 'memberships',
            'logsubtype'=> Log::SUBTYPE_JOINED,
            'groupid' => $gid,
            'search' => 'test@test.com'
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($uid1, $ret['logs'][0]['user']['id']);

        # Logged out.
        $_SESSION['id'] = NULL;
        $ret = $this->call('logs', 'GET', [
            'logtype' => 'user',
            'userid' => $uid1
        ]);

        assertEquals(2, $ret['ret']);

        # User themselves.
        $_SESSION['id'] = $uid1;
        $ret = $this->call('logs', 'GET', [
            'logtype' => 'user',
            'userid' => $uid1
        ]);

        assertEquals(2, $ret['ret']);

        # Mod - can access.
        $u = User::get($this->dbhr, $this->dbhr, $uid1);
        $u->setPrivate('systemrole', User::ROLE_MODERATOR);
        $ret = $this->call('logs', 'GET', [
            'logtype' => 'user',
            'userid' => $uid1
        ]);

        assertEquals(2, count($ret['logs']));
        assertEquals(Log::TYPE_MESSAGE, $ret['logs'][0]['type']);
        assertEquals(Log::SUBTYPE_RECEIVED, $ret['logs'][0]['subtype']);
        assertEquals(Log::TYPE_GROUP, $ret['logs'][1]['type']);
        assertEquals(Log::SUBTYPE_JOINED, $ret['logs'][1]['subtype']);
    }
}

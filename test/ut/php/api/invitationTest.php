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
class invitationAPITest extends IznikAPITestCase
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

        $dbhm->preExec("DELETE FROM users_invitations WHERE email LIKE '%@test.com';");
    }

    public function testAccept()
    {
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Invite logged out - should fail
        $ret = $this->call('invitation', 'PUT', [
            'email' => 'test@test.com'
        ]);
        assertEquals(1, $ret['ret']);

        # Invite logged in
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('invitation', 'PUT', [
            'email' => 'test@test.com',
            'dup' => 1
        ]);
        assertEquals(0, $ret['ret']);

        $invites = $this->dbhr->preQuery("SELECT id FROM users_invitations WHERE email = 'test@test.com';");
        self::assertEquals(1, count($invites));
        $id = $invites[0]['id'];

        # Accept
        $ret = $this->call('invitation', 'PATCH', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);

        $invites = $this->dbhr->preQuery("SELECT * FROM users_invitations WHERE email = 'test@test.com';");
        self::assertEquals(User::INVITE_ACCEPTED, $invites[0]['outcome']);

        $ret = $this->call('invitation', 'GET', []);
        assertEquals(0, $ret['ret']);
        self::assertEquals('Accepted', $ret['invitations'][0]['outcome']);

        }
}

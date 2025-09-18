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
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'user@test.com', 'testpw');
        $this->addLoginAndLogin($this->user, 'testpw');

        # Invite logged out - should fail
        unset($_SESSION['id']);
        $ret = $this->call('invitation', 'PUT', [
            'email' => 'test@test.com'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Invite logged in
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('invitation', 'PUT', [
            'email' => 'test@test.com',
            'dup' => 1
        ]);
        $this->assertEquals(0, $ret['ret']);

        $invites = $this->dbhr->preQuery("SELECT id FROM users_invitations WHERE email = 'test@test.com';");
        self::assertEquals(1, count($invites));
        $id = $invites[0]['id'];

        # Accept
        $ret = $this->call('invitation', 'PATCH', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $invites = $this->dbhr->preQuery("SELECT * FROM users_invitations WHERE email = 'test@test.com';");
        self::assertEquals(User::INVITE_ACCEPTED, $invites[0]['outcome']);

        $ret = $this->call('invitation', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals('Accepted', $ret['invitations'][0]['outcome']);

        }
}

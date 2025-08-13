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
class teamAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $dbhm->preExec("DELETE FROM teams WHERE name = 'UTTest';");
    }

    public function testBasic() {
        # Can't create logged out.
        $ret = $this->call('team', 'POST', [
            'name' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Can't create when logged in without permissions.
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('team', 'POST', [
            'name' => 'UTTest',
            'email' => 'test@test.com',
            'dup' => 1
        ]);
        $this->assertEquals(1, $ret['ret']);

        $this->user->setPrivate('permissions', User::PERM_TEAMS);

        # Can now create.
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('team', 'POST', [
            'name' => 'UTTest',
            'email' => 'test@test.com',
            'dup' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        self::assertNotNull($id);

        $t = new Team($this->dbhr, $this->dbhm);
        $this->assertEquals($id, $t->findByName('UTTest'));

        # Check this appears in the list.
        $ret = $this->call('team', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['teams'] as $team) {
            if ($team['id'] == $id) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);

        $ret = $this->call('team', 'PATCH', [
            'id' => $id,
            'action' => 'Add',
            'userid' => $this->user->getId()
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('team', 'GET', [
            'id' => $id
        ]);
        $this->log("Get " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['team']['members']));
        $this->assertEquals($this->user->getId(), $ret['team']['members'][0]['id']);

        $ret = $this->call('team', 'PATCH', [
            'id' => $id,
            'action' => 'Remove',
            'userid' => $this->user->getId()
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('team', 'GET', [
            'id' => $id
        ]);
        $this->log("Get " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['team']['members']));

        $ret = $this->call('team', 'DELETE', [
            'id' => $id
        ]);
    }
}


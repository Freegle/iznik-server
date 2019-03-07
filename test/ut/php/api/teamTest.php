<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/group/CommunityEvent.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class teamAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $dbhm->preExec("DELETE FROM teams WHERE name = 'UTTest';");
    }

    public function testBasic() {
        # Can't create logged out.
        $ret = $this->call('team', 'POST', [
            'name' => 'UTTest'
        ]);
        assertEquals(1, $ret['ret']);

        # Can't create when logged in without permissions.
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('team', 'POST', [
            'name' => 'UTTest',
            'email' => 'test@test.com',
            'dup' => 1
        ]);
        assertEquals(1, $ret['ret']);

        $this->user->setPrivate('permissions', User::PERM_TEAMS);

        # Can now create.
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('team', 'POST', [
            'name' => 'UTTest',
            'email' => 'test@test.com',
            'dup' => 2
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        self::assertNotNull($id);

        # Check this appears in the list.
        $ret = $this->call('team', 'GET', []);
        assertEquals(0, $ret['ret']);
        $found = FALSE;

        foreach ($ret['teams'] as $team) {
            if ($team['id'] == $id) {
                $found = TRUE;
            }
        }

        assertTrue($found);

        $ret = $this->call('team', 'PATCH', [
            'id' => $id,
            'action' => 'Add',
            'userid' => $this->user->getId()
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('team', 'GET', [
            'id' => $id
        ]);
        $this->log("Get " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['team']['members']));
        assertEquals($this->user->getId(), $ret['team']['members'][0]['id']);

        $ret = $this->call('team', 'PATCH', [
            'id' => $id,
            'action' => 'Remove',
            'userid' => $this->user->getId()
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('team', 'GET', [
            'id' => $id
        ]);
        $this->log("Get " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['team']['members']));

        $ret = $this->call('team', 'DELETE', [
            'id' => $id
        ]);

        }
}


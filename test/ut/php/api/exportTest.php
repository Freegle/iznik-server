<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class exportAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM users WHERE yahooUserId = '1';");

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        assertEquals(1, $this->user->addMembership($this->groupid));
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
    }

    public function testExport() {
        # Add some settings for coverage.
        $this->user->setPrivate('settings', json_encode([
            'email' => TRUE,
            'emailmine' => FALSE,
            'push' => TRUE,
            'facebook' => TRUE,
            'app' => TRUE
        ]));

        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Try logged out - should fail
        $_SESSION['id'] = NULL;

        $ret = $this->call('export', 'POST', []);
        assertEquals(1, $ret['ret']);

        # Now log in
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('export', 'POST', [
            'dup' => 1
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        assertNotNull($ret['tag']);

        $id = $ret['id'];
        $tag = $ret['tag'];

        # Wait for export to complete in the background.
        $count = 0;

        do {
            $ret = $this->call('export', 'GET', [
                'id' => $id,
                'tag' => $tag
            ]);

            $count++;
            $this->log("...waiting for export $count");
            sleep(1);
        } while ((!pres('export', $ret) || !pres('data', $ret['export'])) && $count < 600);

        self::assertLessThan(600, $count);

        assertEquals($this->user->getId(), $ret['export']['data']['Our_internal_ID_for_you']);

        # Now do it again, but sync so that we can get coverage for the export code.
        $ret = $this->call('export', 'POST', [
            'dup' => 1,
            'sync' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        assertNotNull($ret['tag']);
        assertEquals($this->user->getId(), $ret['export']['data']['Our_internal_ID_for_you']);

        }
}


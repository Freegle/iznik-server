<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/misc/Shortlink.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class shortlinkAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM shortlinks WHERE name LIKE 'test%';");
        $this->dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
    }

    public function testBasic() {
        error_log(__METHOD__);

        $g = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        # Get logged out - should fail
        $ret = $this->call('shortlink', 'GET', []);
        assertEquals(2, $ret['ret']);

        # Get logged in as member - should fail
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('shortlink', 'GET', []);
        assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $ret = $this->call('shortlink', 'GET', []);
        assertEquals(0, $ret['ret']);

        $found = FALSE;

        error_log("Found " . count($ret['shortlinks']));

        foreach ($ret['shortlinks'] as $l) {
            if (pres('groupid', $l) == $this->groupid) {
                $found = TRUE;
            }
        }

        assertTrue($found);

        error_log(__METHOD__ . " end");
    }

    public function testCreate() {
        error_log(__METHOD__);

        $g = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        $ret = $this->call('shortlink', 'POST', []);
        assertEquals(2, $ret['ret']);

        $ret = $this->call('shortlink', 'POST', [
            'name' => 'testalink',
            'groupid' => $this->groupid
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        # Already exists.
        $ret = $this->call('shortlink', 'POST', [
            'name' => 'testalink',
            'groupid' => $this->groupid,
            'dup' => TRUE
        ]);
        assertEquals(3, $ret['ret']);

        $ret = $this->call('shortlink', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['shortlink']['id']);

        error_log(__METHOD__ . " end");
    }
}


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
class stdMessageAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM mod_configs WHERE name LIKE 'UTTest%';");

        # Create a moderator and log in as them
        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create an empty config
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        assertTrue($this->user->login('testpw'));
        @session_start();
        $ret = $this->call('modconfig', 'POST', [
            'name' => 'UTTest',
            'dup' => time() . rand()
        ]);
        assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        assertNotNull($this->cid);
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        unset($_SESSION['id']);
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('stdmsg', 'GET', [
            'id' => -1
        ]);
        assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('stdmsg', 'POST', [
            'title' => 'UTTest'
        ]);
        assertEquals(1, $ret['ret']);

        # Create without title
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('stdmsg', 'POST', [
        ]);
        assertEquals(3, $ret['ret']);

        # Create without configid
        $ret = $this->call('stdmsg', 'POST', [
            'title' => "UTTest2"
        ]);
        assertEquals(3, $ret['ret']);

        # Create as member
        $ret = $this->call('stdmsg', 'POST', [
            'title' => 'UTTest',
            'configid' => $this->cid
        ]);
        assertEquals(4, $ret['ret']);

        # Create as moderator
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('stdmsg', 'POST', [
            'title' => 'UTTest2',
            'configid' => $this->cid,
            'action' => 'Reject'
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        $ret = $this->call('stdmsg', 'GET', [
            'id' => $id
        ]);
        $this->log("Returned " . var_export($ret, true));
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['stdmsg']['id']);
        assertEquals('Reject', $ret['stdmsg']['action']);

        # Should show up in config now.
        $ret = $this->call('modconfig', 'GET', [
            'id' => $this->cid
        ]);

        assertEquals(1, count($ret['config']['stdmsgs']));
        assertEquals($id, $ret['config']['stdmsgs'][0]['id']);

        # And if we get it via session.
        $ret = $this->call('session', 'GET', [
            'components' => [ 'allconfigs' ]
        ]);

        error_log("Returned " . count($ret['configs']));
        $found = FALSE;
        foreach ($ret['configs'] as $config) {
            if ($config['id'] == $this->cid) {
                assertEquals($id, $config['stdmsgs'][0]['id']);
                $found = TRUE;
            }
        }

        assertTrue($found);
    }

    public function testPatch() {
        assertTrue($this->user->login('testpw'));
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $this->log("Create stdmsg for {$this->cid}");
        $ret = $this->call('stdmsg', 'POST', [
            'configid' => $this->cid,
            'title' => 'UTTest'
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created $id");

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('stdmsg', 'PATCH', [
            'id' => $id
        ]);
        assertEquals(1, $ret['ret']);

        # Log back in
        assertTrue($this->user->login('testpw'));

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('stdmsg', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $ret = $this->call('stdmsg', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('stdmsg', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals('UTTest2', $ret['stdmsg']['title']);

        # Try as a mod, but the wrong one.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup2', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $user = User::get($this->dbhr, $this->dbhm, $uid);
        $user->addEmail('test2@test.com');
        $user->addMembership($gid, User::ROLE_OWNER);
        assertGreaterThan(0, $user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($user->login('testpw'));

        $ret = $this->call('stdmsg', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest3'
        ]);
        assertEquals(4, $ret['ret']);

        }

    public function testDelete() {
        assertTrue($this->user->login('testpw'));
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('stdmsg', 'POST', [
            'configid' => $this->cid,
            'title' => 'UTTest',
            'dup' => time() . $this->count++
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Log out
        unset($_SESSION['id']);

        # When not logged in
        $ret = $this->call('stdmsg', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(1, $ret['ret']);

        # Log back in
        assertTrue($this->user->login('testpw'));

        # As a non-mod
        $this->log("Demote");
        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $ret = $this->call('stdmsg', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(4, $ret['ret']);

        # Try as a mod, but the wrong one.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup2', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $user = User::get($this->dbhr, $this->dbhm, $uid);
        $user->addEmail('test2@test.com');
        $user->addMembership($gid, User::ROLE_OWNER);
        assertGreaterThan(0, $user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($user->login('testpw'));

        $ret = $this->call('stdmsg', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(4, $ret['ret']);

        # Promote back
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('stdmsg', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('stdmsg', 'GET', [
            'id' => $id
        ]);
        assertEquals(2, $ret['ret']);

        }
}


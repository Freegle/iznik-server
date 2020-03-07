<?php
if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once(IZNIK_BASE . '/include/dashboard/Dashboard.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class dashboardTest extends IznikAPITestCase {
    public function testAdmin() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u = User::get($this->dbhr, $this->dbhm, $id);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $this->log("After login {$_SESSION['id']}");

        # Shouldn't get anything as a user
        $ret = $this->call('dashboard', 'GET', []);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        assertFalse(array_key_exists('messagehistory', $dash));

        # But should as an admin
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $ret = $this->call('dashboard', 'GET', [
            'systemwide' => TRUE,
            'force' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        #$this->log("Got dashboard " . var_export($ret, TRUE));
        assertGreaterThan(0, $dash['ApprovedMessageCount']);
    }

    public function testGroups() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create('Test', 'User', NULL);
        $id2 = $u->create('Test', 'User', NULL);
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_OTHER);
        $group2 = $g->create('testgroup2', Group::GROUP_OTHER);
        $u1->addMembership($group1);
        $u1->addMembership($group2, User::ROLE_MODERATOR);
        $u2->addMembership($group2, User::ROLE_MODERATOR);

        # Shouldn't get anything as a user
        $ret = $this->call('dashboard', 'GET', [
            'group' => $group1
        ]);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        assertFalse(array_key_exists('messagehistory', $dash));

        # But should as a mod
        $ret = $this->call('dashboard', 'GET', [
            'group' => $group2
        ]);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        assertGreaterThan(0, $dash['ApprovedMessageCount']);

        # And also if we ask for our groups
        $ret = $this->call('dashboard', 'GET', [
            'allgroups' => TRUE,
            'grouptype' => 'Other'
        ]);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        assertGreaterThan(0, $dash['ApprovedMessageCount']);

        # And again for cache code.
        $ret = $this->call('dashboard', 'GET', [
            'allgroups' => TRUE,
            'grouptype' => 'Other'
        ]);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        assertGreaterThan(0, $dash['ApprovedMessageCount']);

        # ...but not if we ask for the wrong type
        $ret = $this->call('dashboard', 'GET', [
            'allgroups' => TRUE,
            'grouptype' => 'Freegle'
        ]);
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->log(var_export($dash, TRUE));
        assertEquals(0, count($dash['ApprovedMessageCount']));
    }

    public function testRegion() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_OTHER);
        $g->setPrivate('region', 'Scotland');

        $ret = $this->call('dashboard', 'GET', [
            'region' => 'Scotland'
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        assertTrue(in_array($group1, $ret['dashboard']['groupids']));
    }

    public function testComponents() {
        # Create a group with a message on it.
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onhere', 1);
        $g->setPrivate('onyahoo', 0);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid);
        assertTrue($u->addMembership($gid, User::ROLE_OWNER));
        $u->addEmail('test@test.com');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'testgroup@groups.ilovefreegle.org', $msg, $gid);
        assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_RECENT_COUNTS
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['components']['RecentCounts']['newmembers']);
        assertEquals(1, $ret['components']['RecentCounts']['newmessages']);
    }

//
//    public function testEH() {
//        //
//        $u = new User($this->dbhr, $this->dbhm);
//
//        $uid = $u->findByEmail('sheilasmail.cp@gmail.com');
//        $_SESSION['id'] = $uid;
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//
//        $ret = $this->call('dashboard', 'GET', [
//            'allgroups' => TRUE
//        ]);
//        assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//
//        //    }
}


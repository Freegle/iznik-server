<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class dashboardTest extends IznikAPITestCase {
    public function testLoggedOut() {
        $ret = $this->call('session', 'GET', []);
        assertEquals(1, $ret['ret']);

        }

    public function testAdmin() {
        # Now log in
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


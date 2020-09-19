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

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid);
        assertTrue($u->addMembership($gid, User::ROLE_OWNER));
        $u->addEmail('test@test.com');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        $mid = $r->received(Message::EMAIL, 'from@test.com', 'testgroup@groups.ilovefreegle.org', $msg, $gid);
        assertNotNull($mid);
        $this->log("Created message $mid");
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_RECENT_COUNTS
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['components']['RecentCounts']['newmembers']);
        assertEquals(1, $ret['components']['RecentCounts']['newmessages']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_POPULAR_POSTS
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['components']['PopularPosts']));

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        assertEquals(Message::TYPE_OFFER, $m->getType());
        $m->like($uid, Message::LIKE_VIEW);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_POPULAR_POSTS
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);

        # Message is still pending.
        assertEquals(0, count($ret['components']['PopularPosts']));

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->approve($gid);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_POPULAR_POSTS
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components']['PopularPosts']));
        assertEquals(1, $ret['components']['PopularPosts'][0]['views']);
        assertEquals(0, $ret['components']['PopularPosts'][0]['replies']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_USERS_POSTING
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENT_USERS_POSTING]));
        assertEquals($uid, $ret['components'][Dashboard::COMPONENT_USERS_POSTING][0]['id']);
        assertEquals(1, $ret['components'][Dashboard::COMPONENT_USERS_POSTING][0]['posts']);

        # Reply
        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid2);
        assertTrue($u->addMembership($gid, User::ROLE_MEMBER));

        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $cr->createConversation($uid, $uid2);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cmid, $banned) = $cm->create($rid, $uid2, "Test", ChatMessage::TYPE_INTERESTED, $mid);
        assertNotNull($cmid);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_USERS_REPLYING
            ],
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENT_USERS_REPLYING]));
        assertEquals($uid2, $ret['components'][Dashboard::COMPONENT_USERS_REPLYING][0]['id']);
        assertEquals(1, $ret['components'][Dashboard::COMPONENT_USERS_REPLYING][0]['replies']);

        $this->waitBackground();

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_MODERATORS_ACTIVE
            ],
            'group' => $gid
        ]);

        sleep(1);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENT_MODERATORS_ACTIVE]));
        assertEquals($uid, $ret['components'][Dashboard::COMPONENT_MODERATORS_ACTIVE][0]['id']);

        # Move message to approved for stats.
        $m->approve($gid);

        # Trigger a notification check which also records us as active.
        $this->call('notification', 'GET', [
            'count' => TRUE
        ]);

        # Generate stats so they exist to query.
        $this->waitBackground();
        $s = new Stats($this->dbhr, $this->dbhm, $gid);
        $s->generate(date('Y-m-d'));

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT,
                Dashboard::COMPONENTS_ACTIVE_USERS
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT]));
        assertEquals(1, $ret['components'][Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT][0]['count']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_ACTIVE_USERS]));
        assertEquals(1, $ret['components'][Dashboard::COMPONENTS_ACTIVE_USERS][0]['count']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_REPLIES
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_REPLIES]));
        assertEquals(1, $ret['components'][Dashboard::COMPONENTS_REPLIES][0]['count']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_ACTIVITY
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_ACTIVITY]));
        assertEquals(2, $ret['components'][Dashboard::COMPONENTS_ACTIVITY][0]['count']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_MESSAGE_BREAKDOWN
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['components'][Dashboard::COMPONENTS_MESSAGE_BREAKDOWN][Message::TYPE_OFFER]);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_WEIGHT
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        error_log("returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_WEIGHT]));

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_OUTCOMES
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        error_log("returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_OUTCOMES]));


        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_ACTIVE_USERS
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow'))
        ]);

        error_log("returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists(Dashboard::COMPONENTS_ACTIVE_USERS, $ret['components']));
    }

//
//    public function testEH() {
//        //
//        $u = new User($this->dbhr, $this->dbhm);
//
//        $uid = $u->findByEmail('');
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


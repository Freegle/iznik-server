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
        # Use a full pathname.  This is a test of our autoloader for coverage.
        list($u, $id, $emailid) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        $this->log("After login {$_SESSION['id']}");

        # Shouldn't get anything as a user
        $ret = $this->call('dashboard', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->assertFalse(array_key_exists('messagehistory', $dash));

        # But should as an admin
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $ret = $this->call('dashboard', 'GET', [
            'systemwide' => TRUE,
            'force' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        #$this->log("Got dashboard " . var_export($ret, TRUE));
        $this->assertGreaterThan(0, $dash['ApprovedMessageCount']);
    }

    public function testGroups() {
        list($u, $id1, $emailid1) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test@test.com', 'testpw');
        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', NULL, 'test2@test.com', 'testpw2');
        $u1 = $u;

        list($g1, $group1) = $this->createTestGroup('testgroup1', Group::GROUP_OTHER);
        list($g2, $group2) = $this->createTestGroup('testgroup2', Group::GROUP_OTHER);
        $u1->addMembership($group1);
        $u1->addMembership($group2, User::ROLE_MODERATOR);
        $u2->addMembership($group2, User::ROLE_MODERATOR);

        # Shouldn't get anything as a user
        $ret = $this->call('dashboard', 'GET', [
            'group' => $group1
        ]);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->assertFalse(array_key_exists('messagehistory', $dash));

        # But should as a mod
        $ret = $this->call('dashboard', 'GET', [
            'group' => $group2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->assertGreaterThan(0, $dash['ApprovedMessageCount']);

        # And also if we ask for our groups
        $ret = $this->call('dashboard', 'GET', [
            'allgroups' => TRUE,
            'grouptype' => 'Other'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->assertGreaterThan(0, $dash['ApprovedMessageCount']);

        # And again for cache code.
        $ret = $this->call('dashboard', 'GET', [
            'allgroups' => TRUE,
            'grouptype' => 'Other'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->assertGreaterThan(0, $dash['ApprovedMessageCount']);

        # ...but not if we ask for the wrong type
        $ret = $this->call('dashboard', 'GET', [
            'allgroups' => TRUE,
            'grouptype' => 'Freegle'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->log(var_export($dash, TRUE));
        $this->assertEquals(0, count($dash['ApprovedMessageCount']));
    }

    public function testRegion() {
        list($g, $group1) = $this->createTestGroup('testgroup1', Group::GROUP_OTHER);
        $g->setPrivate('region', 'Scotland');

        $ret = $this->call('dashboard', 'GET', [
            'region' => 'Scotland'
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $dash = $ret['dashboard'];
        $this->assertTrue(in_array($group1, $ret['dashboard']['groupids']));
    }

    public function testComponents() {
        # Create a group with a message on it.
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create("testgroup", Group::GROUP_REUSE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_OWNER, NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);
        list($r, $mid, $failok, $rc) = $this->createTestMessage($msg, 'testgroup', 'from@test.com', 'testgroup@groups.ilovefreegle.org', $gid, $uid);
        $this->assertNotNull($mid);
        $this->log("Created message $mid");
        $this->assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_RECENT_COUNTS
            ],
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['components']['RecentCounts']['newmembers']);
        $this->assertEquals(1, $ret['components']['RecentCounts']['newmessages']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_POPULAR_POSTS
            ],
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['components']['PopularPosts']));

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $this->assertEquals(Message::TYPE_OFFER, $m->getType());
        $m->like($uid, Message::LIKE_VIEW);
        $this->waitBackground();

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_POPULAR_POSTS
            ],
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Message is still pending.
        $this->assertEquals(0, count($ret['components']['PopularPosts']));

        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->approve($gid);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_POPULAR_POSTS
            ],
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components']['PopularPosts']));
        $this->assertEquals(1, $ret['components']['PopularPosts'][0]['views']);
        $this->assertEquals(0, $ret['components']['PopularPosts'][0]['replies']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_USERS_POSTING
            ],
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENT_USERS_POSTING]));
        $this->assertEquals($uid, $ret['components'][Dashboard::COMPONENT_USERS_POSTING][0]['id']);
        $this->assertEquals(1, $ret['components'][Dashboard::COMPONENT_USERS_POSTING][0]['posts']);

        # Reply
        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $this->assertNotNull($uid2);
        $this->assertTrue($u->addMembership($gid, User::ROLE_MEMBER));

        $cr = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $cr->createConversation($uid, $uid2);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cmid, $banned) = $cm->create($rid, $uid2, "Test", ChatMessage::TYPE_INTERESTED, $mid);
        $this->assertNotNull($cmid);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_USERS_REPLYING
            ],
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENT_USERS_REPLYING]));
        $this->assertEquals($uid2, $ret['components'][Dashboard::COMPONENT_USERS_REPLYING][0]['id']);
        $this->assertEquals(1, $ret['components'][Dashboard::COMPONENT_USERS_REPLYING][0]['replies']);

        $this->waitBackground();

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENT_MODERATORS_ACTIVE
            ],
            'group' => $gid
        ]);

        sleep(1);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENT_MODERATORS_ACTIVE]));
        $this->assertEquals($uid, $ret['components'][Dashboard::COMPONENT_MODERATORS_ACTIVE][0]['id']);

        # Move message to approved for stats.
        $m->approve($gid);

        # Trigger a notification check which also records us as active.
        $this->call('notification', 'GET', [
            'count' => TRUE,
            'modtools' => FALSE
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

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT]));
        $this->assertEquals(1, $ret['components'][Dashboard::COMPONENTS_APPROVED_MESSAGE_COUNT][0]['count']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_ACTIVE_USERS]));
        $this->assertEquals(1, $ret['components'][Dashboard::COMPONENTS_ACTIVE_USERS][0]['count']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_REPLIES
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_REPLIES]));
        $this->assertEquals(1, $ret['components'][Dashboard::COMPONENTS_REPLIES][0]['count']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_ACTIVITY
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['components'][Dashboard::COMPONENTS_ACTIVITY]));
        $this->assertEquals(2, $ret['components'][Dashboard::COMPONENTS_ACTIVITY][0]['count']);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_MESSAGE_BREAKDOWN
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['components'][Dashboard::COMPONENTS_MESSAGE_BREAKDOWN][Message::TYPE_OFFER]);

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_WEIGHT
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);

        # Zero weight so no value returned.
        $this->assertEquals(0, count($ret['components'][Dashboard::COMPONENTS_WEIGHT]));

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_OUTCOMES
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow')),
            'group' => $gid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['components'][Dashboard::COMPONENTS_OUTCOMES]));

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_ACTIVE_USERS
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow'))
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists(Dashboard::COMPONENTS_ACTIVE_USERS, $ret['components']));

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_DONATIONS
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow'))
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists(Dashboard::COMPONENTS_DONATIONS, $ret['components']));

        $ret = $this->call('dashboard', 'GET', [
            'components' => [
                Dashboard::COMPONENTS_DISCOURSE_TOPICS
            ],
            'start' => date('Y-m-d'),
            'end' => date('Y-m-d', strtotime('tomorrow'))
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists(Dashboard::COMPONENTS_DISCOURSE_TOPICS, $ret['components']));
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
//        $this->assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//
//        //    }
}


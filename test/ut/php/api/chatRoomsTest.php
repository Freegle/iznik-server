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
class chatRoomsAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($this->uid);
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertEquals($this->uid, $this->user->getId());
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        assertNotNull($this->groupid);

        $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid);
        $this->user->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $_SESSION['id'] = NULL;
    }

    public function testUser2User()
    {
        # Logged out - no rooms
        $ret = $this->call('chatrooms', 'GET', []);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatrooms', $ret));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($this->uid, $uid);

        # Just because it exists, doesn't mean we should be able to see it.
        $ret = $this->call('chatrooms', 'GET', []);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatrooms', $ret));

        assertTrue($this->user->login('testpw'));

        # Now we're talking.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        $this->log("Got chat room " . var_export($ret, TRUE));
        assertEquals($rid, $ret['chatrooms'][0]['id']);
        assertEquals('Test User', $ret['chatrooms'][0]['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ],
            'summary' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($rid, $ret['chatrooms'][0]['id']);
        assertEquals('Test User', $ret['chatrooms'][0]['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($rid, $ret['chatroom']['id']);

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => 2,
        ]);

        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($rid, $ret['chatroom']['id']);

        }

    public function testMod2Mod()
    {
        # Logged out - no rooms
        $ret = $this->call('chatrooms', 'GET', []);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatrooms', $ret));

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createGroupChat('test', $this->groupid);

        # Just because it exists, doesn't mean we should be able to see it.
        $ret = $this->call('chatrooms', 'GET', []);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatrooms', $ret));

        assertTrue($this->user->login('testpw'));

        # Still not, even logged in.
        $ret = $this->call('chatrooms', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertFalse(Utils::pres('chatrooms', $ret));

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        # Now we're talking.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ]
        ]);
        assertEquals(0, $ret['ret']);

        # Two rooms - one we've creted, and the automatic mod chat.
        assertEquals(2, count($ret['chatrooms']));
        assertTrue($rid == $ret['chatrooms'][0]['id'] || $rid == $ret['chatrooms'][1]['id']);
        $ratts = $rid == $ret['chatrooms'][0]['id'] ? $ret['chatrooms'][0] : $ret['chatrooms'][1];
        assertEquals('testgroup Mods', $ratts['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ],
            'summary' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(2, count($ret['chatrooms']));
        assertTrue($rid == $ret['chatrooms'][0]['id'] || $rid == $ret['chatrooms'][1]['id']);
        $ratts = $rid == $ret['chatrooms'][0]['id'] ? $ret['chatrooms'][0] : $ret['chatrooms'][1];
        assertEquals('testgroup Mods', $ratts['name']);

        $ret = $this->call('chatrooms', 'GET', [
            'id' => $rid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($rid, $ret['chatroom']['id']);

        # Roster
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => 1
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals($this->uid, $ret['roster'][0]['userid']);
        assertEquals('Test User', $ret['roster'][0]['user']['fullname']);
        assertEquals('Online', $ret['roster'][0]['status']);

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'lastmsgseen' => 1
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals($this->uid, $ret['roster'][0]['userid']);
        assertEquals('Test User', $ret['roster'][0]['user']['fullname']);

        }

    public function testUser2Mod()
    {
        assertTrue($this->user->login('testpw'));

        # Create a support room from this user to the group mods
        $this->user->addMembership($this->groupid);

        $ret = $this->call('chatrooms', 'PUT', [
            'groupid' => $this->groupid,
            'chattype' => ChatRoom::TYPE_USER2MOD
        ]);
        assertEquals(0, $ret['ret']);
        $rid = $ret['id'];
        $this->log("Created User2Mod $rid");
        assertNotNull($rid);
        assertFalse(Utils::pres('chatrooms', $ret));

        # Now we're talking.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER, ChatRoom::TYPE_MOD2MOD ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['chatrooms']));

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($rid, $ret['chatrooms'][0]['id']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ],
            'summary' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($rid, $ret['chatrooms'][0]['id']);

        # Now create a group mod
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->groupid);
        assertTrue($u->login('testpw'));

        # Shouldn't see it before we promote.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['chatrooms']));

        # Now promote.
        $u->setRole(USer::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($rid, $ret['chatrooms'][0]['id']);

        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ],
            'summary' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($rid, $ret['chatrooms'][0]['id']);

        }

    public function testAllSeen()
    {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid);
        assertNotNull($this->uid);

        # Create an unseen message
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $c->createConversation($this->uid, $uid);
        $this->log("Created room $rid");
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($mid, $banned) = $m->create($rid, $uid, 'Test');
        $this->log("Created message $mid");

        assertTrue($this->user->login('testpw'));

        # Check it's unseen
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2USER ],
            'summary' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($rid, $ret['chatrooms'][0]['id']);
        assertEquals(1, $ret['chatrooms'][0]['unseen']);

        $ret = $this->call('chatrooms', 'GET', [
            'count' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['count']);

        # Mark all seen - twice for coverage;
        for ($i = 0; $i < 2; $i++) {
            $ret = $this->call('chatrooms', 'POST', [
                'action' => 'AllSeen'
            ]);

            $ret = $this->call('chatrooms', 'GET', [
                'chattypes' => [ ChatRoom::TYPE_USER2USER ]
            ]);
            $this->log("Should be no unseen " . var_export($ret, TRUE));
            assertEquals(0, $ret['ret']);
            assertEquals($rid, $ret['chatrooms'][0]['id']);
            assertEquals(0, $ret['chatrooms'][0]['unseen']);
        }

        $ret = $this->call('chatrooms', 'GET', [
            'count' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, $ret['count']);
    }

    public function testNudge() {
        $u = User::get($this->dbhr, $this->dbhr);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid);
        assertNotNull($this->uid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create an unseen message
        $c = new ChatRoom($this->dbhr, $this->dbhr);
        $rid = $c->createConversation($this->uid, $uid);
        $this->log("Created room $rid between {$this->uid} and $uid");

        assertTrue($this->user->login('testpw'));

        $ret = $this->call('chatrooms', 'POST', [
            'id' => $rid,
            'action' => 'Nudge'
        ]);
        assertEquals(0, $ret['ret']);
        $nudges = $c->nudges($uid);
        assertEquals($uid, $nudges[0]['touser']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['user']['info']['nudges']['sent']);
        assertEquals(0, $ret['user']['info']['nudges']['responded']);

        # Now reply - should mark the nudge as handled
        assertTrue($u->login('testpw'));

        $ret = $this->call('chatmessages', 'POST', [ 'roomid' => $rid, 'message' => 'Test' ]);
        assertEquals(0, $ret['ret']);

        $this->waitBackground();

        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'info' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['user']['info']['nudges']['sent']);
        assertEquals(1, $ret['user']['info']['nudges']['responded']);

    }

    public function testInvalidId() {
        $u = User::get($this->dbhr, $this->dbhr);
        $uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($uid);
        assertNotNull($this->uid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Create an unseen message
        $c = new ChatRoom($this->dbhr, $this->dbhr);
        $rid = $c->createConversation($this->uid, $uid);
        $this->log("Created room $rid between {$this->uid} and $uid");

        assertTrue($this->user->login('testpw'));

        $ret = $this->call('chatrooms', 'GET', [
            'id' => -$rid
        ]);
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('chatroom', $ret));
    }

    public function testBanned() {
        $u1 = User::get($this->dbhr, $this->dbhr);
        $uid1 = $u1->create(NULL, NULL, 'Test User');
        assertNotNull($uid1);
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhr);
        $uid2 = $u2->create(NULL, NULL, 'Test User');
        assertNotNull($uid2);
        assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Ban u1
        $gid = $this->groupid;
        $u1->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);
        $u1->removeMembership($gid, TRUE);

        # u2 a member on group
        $u2->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED);

        # Put a message on the group.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $msgid = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # u1 should not be able to open a chat to u2 as they are banned on all groups in common.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $rid = $r->createConversation($uid1, $uid2);
        assertNull($rid);
    }
//
//    public function testEH() {
//        $_SESSION['id'] = 35822275;
//        $ret = $this->call('chatrooms', 'GET', [
//            'chattypes' => [
//                ChatRoom::TYPE_USER2MOD,
//                ChatRoom::TYPE_MOD2MOD,
//            ],
//            'summary' => TRUE,
//            'modtools'=> TRUE
//        ]);
//        assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//    }
}

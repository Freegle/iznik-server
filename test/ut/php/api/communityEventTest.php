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
class communityEventAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addMembership($this->groupid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid3 = $u->create(NULL, NULL, 'Test User');
        $this->user3 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        $this->user3->addMembership($this->groupid);
        assertGreaterThan(0, $this->user3->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $dbhm->preExec("DELETE FROM communityevents WHERE title = 'Test event' OR title = 'UTTest';");
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('communityevent', 'GET', [
            'id' => -1
        ]);
        assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('communityevent', 'POST', [
            'title' => 'UTTest'
        ]);
        assertEquals(1, $ret['ret']);

        # Create without mandatories
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('communityevent', 'POST', [
        ]);
        assertEquals(2, $ret['ret']);

        # Create as logged in user.
        $ret = $this->call('communityevent', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest'
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);
        $this->log("Created event $id");

        # Add group
        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'AddGroup'
        ]);
        assertEquals(0, $ret['ret']);

        # Add date
        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        assertEquals(0, $ret['ret']);

        # Shouldn't show for us as pending.
        $ret = $this->call('communityevent', 'GET', [
            'pending' => true
        ]);
        $this->log("Result of get all " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['communityevents']));

        $ret = $this->call('communityevent', 'GET', [
            'pending' => TRUE,
            'groupid' => $this->groupid
        ]);
        $this->log("Result of get for group " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['communityevents']));

        # Log in as the mod
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        assertTrue($this->user2->login('testpw'));

        # Edit it
        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);
        assertEquals('UTTest2', $ret['communityevent']['title']);

        # Edit it
        $ret = $this->call('communityevent', 'PUT', [
            'id' => $id,
            'title' => 'UTTest3'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);
        assertEquals('UTTest3', $ret['communityevent']['title']);

        $dateid = $ret['communityevent']['dates'][0]['id'];

        # Shouldn't be editable for someone else.
        $this->user3->addMembership($this->groupid, User::ROLE_MEMBER);
        assertTrue($this->user3->login('testpw'));
        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);
        assertFalse($ret['communityevent']['canmodify']);

        # And back as the user
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'RemoveGroup'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'dateid' => $dateid,
            'action' => 'RemoveDate'
        ]);
        assertEquals(0, $ret['ret']);

        # Add a photo
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_COMMUNITY_EVENT);
        $photoid = $a->create(NULL, $data);

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'photoid' => $photoid,
            'action' => 'SetPhoto'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        assertEquals($photoid, $ret['communityevent']['photo']['id']);

        $ret = $this->call('communityevent', 'DELETE', [
            'id' => $id
        ]);

    }
    
    public function testHold() {
        assertTrue($this->user->login('testpw'));
        $this->user->setPrivate('systemrole', User::ROLE_MODERATOR);

        $ret = $this->call('communityevent', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
            'groupid' => $this->groupid
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);
        $this->log("Created event $id");

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        assertFalse(array_key_exists('heldby', $ret['communityevent']));

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Hold'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        assertEquals($this->user->getId(), $ret['communityevent']['heldby']['id']);

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Release'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        assertFalse(array_key_exists('heldby', $ret['communityevent']));
    }
}


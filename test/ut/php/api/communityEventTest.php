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

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        list($g, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test1@test.com', 'testpw');
        $this->user->addMembership($this->groupid);

        list($this->user2, $this->uid2) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MODERATOR, 'Test User', 'test2@test.com', 'testpw');

        list($this->user3, $this->uid3, $emailid3) = $this->createTestUser(NULL, NULL, 'Test User', 'test3@test.com', 'testpw');
        $this->user3->addMembership($this->groupid);

        $dbhm->preExec("DELETE FROM communityevents WHERE title = 'Test event' OR title = 'UTTest';");
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('communityevent', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('communityevent', 'POST', [
            'title' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create without mandatories
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('communityevent', 'POST', [
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create as logged in user.
        $ret = $this->call('communityevent', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Add group
        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'AddGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Add date
        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Shouldn't show for us as pending.
        $ret = $this->call('communityevent', 'GET', [
            'pending' => TRUE
        ]);
        $this->log("Result of get all " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['communityevents']));

        $ret = $this->call('communityevent', 'GET', [
            'pending' => TRUE,
            'groupid' => $this->groupid
        ]);
        $this->log("Result of get for group " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['communityevents']));

        # Log in as the mod
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        # Edit it
        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('UTTest2', $ret['communityevent']['title']);

        # Edit it
        $ret = $this->call('communityevent', 'PUT', [
            'id' => $id,
            'title' => 'UTTest3'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('UTTest3', $ret['communityevent']['title']);

        $dateid = $ret['communityevent']['dates'][0]['id'];

        # Shouldn't be editable for someone else.
        $this->user3->addMembership($this->groupid, User::ROLE_MEMBER);
        $this->assertTrue($this->user3->login('testpw'));
        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);
        $this->assertFalse($ret['communityevent']['canmodify']);

        # And back as the user
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'RemoveGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'dateid' => $dateid,
            'action' => 'RemoveDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Add a photo
        list ($a, $photoid, $uid) = $this->createTestImageAttachment('/test/ut/php/images/chair.jpg', Attachment::TYPE_COMMUNITY_EVENT);

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'photoid' => $photoid,
            'action' => 'SetPhoto'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals($photoid, $ret['communityevent']['photo']['id']);

        $ret = $this->call('communityevent', 'DELETE', [
            'id' => $id
        ]);

    }
    
    public function testHold() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setPrivate('systemrole', User::ROLE_MODERATOR);

        $ret = $this->call('communityevent', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        $this->assertFalse(array_key_exists('heldby', $ret['communityevent']));

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Hold'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals($this->user->getId(), $ret['communityevent']['heldby']['id']);

        $ret = $this->call('communityevent', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Release'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('communityevent', 'GET', [
            'id' => $id
        ]);

        $this->assertFalse(array_key_exists('heldby', $ret['communityevent']));
    }
}


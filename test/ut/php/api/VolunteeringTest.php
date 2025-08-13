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
class volunteeringAPITest extends IznikAPITestCase {
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

        $dbhm->preExec("DELETE FROM volunteering WHERE title = 'Test vacancy' OR title = 'UTTest';");
    }

    public function testCreate() {
        # Get invalid id
        $ret = $this->call('volunteering', 'GET', [
            'id' => -1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create when not logged in
        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Create without mandatories
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('volunteering', 'POST', [
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Create as logged in user.
        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Remove and Add group
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'RemoveGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'AddGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Add date
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Shouldn't show for us as pending.
        $ret = $this->call('volunteering', 'GET', [
            'pending' => TRUE
        ]);
        $this->log("Result of get all " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['volunteerings']));

        $ret = $this->call('volunteering', 'GET', [
            'pending' => TRUE,
            'groupid' => $this->groupid
        ]);
        $this->log("Result of get for group " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['volunteerings']));

        # Log in as the mod
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['pendingvolunteering']);

        # Edit it
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'title' => 'UTTest2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('UTTest2', $ret['volunteering']['title']);

        # Edit it
        $ret = $this->call('volunteering', 'PUT', [
            'id' => $id,
            'title' => 'UTTest3'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals('UTTest3', $ret['volunteering']['title']);
        self::assertFalse(Utils::pres('renewed', $ret['volunteering']));

        $dateid = $ret['volunteering']['dates'][0]['id'];

        # Shouldn't be editable for someone else.
        $this->user3->addMembership($this->groupid, User::ROLE_MEMBER);
        $this->assertTrue($this->user3->login('testpw'));
        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertFalse($ret['volunteering']['canmodify']);

        # And back as the user
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'RemoveGroup'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'dateid' => $dateid,
            'action' => 'RemoveDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Test renew
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'action' => 'Renew'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        self::assertNotNull($ret['volunteering']['renewed']);

        # Test expire
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'action' => 'Expire'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(1, $ret['volunteering']['expired']);

        # Add a photo
        list ($a, $photoid, $uid) = $this->createTestImageAttachment('/test/ut/php/images/chair.jpg', Attachment::TYPE_VOLUNTEERING);

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'photoid' => $photoid,
            'action' => 'SetPhoto'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals($photoid, $ret['volunteering']['photo']['id']);

        $ret = $this->call('volunteering', 'DELETE', [
            'id' => $id
        ]);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->log("Get after delete " . var_export($ret, TRUE));
        self::assertEquals(3, $ret['ret']);

    }

    public function testHold() {
        $this->assertTrue($this->user->login('testpw'));
        $this->user->setPrivate('systemrole', User::ROLE_MODERATOR);

        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
            'groupid' => $this->groupid
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertFalse(array_key_exists('heldby', $ret['volunteering']));

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Hold'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals($this->user->getId(), $ret['volunteering']['heldby']['id']);

        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Release'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('volunteering', 'GET', [
            'id' => $id
        ]);

        $this->assertFalse(array_key_exists('heldby', $ret['volunteering']));
    }

    public function testNational() {
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Add date
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Log in as the mod
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        # Shouldn't show as we don't have national permission.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['pendingvolunteering']);
    }

    public function testNational2() {
        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('volunteering', 'POST', [
            'title' => 'UTTest',
            'location' => 'UTTest',
            'description' => 'UTTest',
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
        $this->log("Created event $id");

        # Add date
        $ret = $this->call('volunteering', 'PATCH', [
            'id' => $id,
            'start' => Utils::ISODate('@' . strtotime('next wednesday 2pm')),
            'end' => Utils::ISODate('@' . strtotime('next wednesday 4pm')),
            'action' => 'AddDate'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Log in as the mod
        $this->user2->setPrivate('permissions', User::PERM_NATIONAL_VOLUNTEERS . "," . User::PERM_GIFTAID);
        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->assertTrue($this->user2->login('testpw'));

        $ret = $this->call('session', 'GET', [
            'components' => [ 'work' ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['pendingvolunteering']);
        $this->assertEquals(0, $ret['work']['giftaid']);
    }
}


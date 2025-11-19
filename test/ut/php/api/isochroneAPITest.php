<?php
namespace Freegle\Iznik;

use JsonSchema\Exception\InvalidSourceUriException;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class isochroneAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;


    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic()
    {
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $settings = [
            'mylocation' => [
                'lat' => 55.957571,
                'lng' => -3.205333,
                'name' => 'EH3 6SS'
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));
        $this->addLoginAndLogin($u, 'testpw');

        $ret = $this->call('isochrone', 'GET', []);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['isochrones']));
        $this->assertNotNull($ret['isochrones'][0]['polygon']);

        // No transport returned by default.
        $this->assertFalse(array_key_exists('transport', $ret['isochrones'][0]));
        $this->assertEquals(Isochrone::DEFAULT_TIME, $ret['isochrones'][0]['minutes']);
        $id = $ret['isochrones'][0]['id'];

        // Edit it - should update the same one rather than create a new one.
        $ret = $this->call('isochrone', 'PATCH', [
            'id' => $id,
            'minutes' => 20,
            'transport' => Isochrone::WALK
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('isochrone', 'GET', []);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['isochrones']));
        $this->assertEquals(Isochrone::WALK, $ret['isochrones'][0]['transport']);
        $this->assertEquals(20, $ret['isochrones'][0]['minutes']);

        // Get the updated ID after PATCH (edit() creates a new isochrones_users row)
        $id = $ret['isochrones'][0]['id'];

        $ret = $this->call('isochrone', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        $ret = $this->call('isochrone', 'PUT', [
            'minutes' => 20,
            'transport' => Isochrone::WALK,
            'nickname' => 'UT',
            'locationid' => $lid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);

        # No messages to find.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(0, count($msgs));

        # Add a message near this location.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);

        $email = 'test-' . rand() . '@blackhole.io';
        $u->addEmail($email);
        $this->addLoginAndLogin($u, 'testpw');

        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, $email, 'to@test.com', MailRouter::APPROVED);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Message::TYPE_OFFER, $m->getType());

        # Get it into the spatial index.
        $m->setPrivate('lat', 55.957572);
        $m->setPrivate('lng', -3.205334);
        $m->addToSpatialIndex();

        # Should now appear if we search by isochrone.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(1, count($msgs));
        $this->assertEquals($id, $msgs[0]['id']);

        # Should now appear if we search by isochrone and groupid
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones',
            'groupid' => $group1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(1, count($msgs));
        $this->assertEquals($id, $msgs[0]['id']);

        # ...but not if the wrong group.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones',
            'groupid' => $group1 + 1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(0, count($msgs));
    }

    public function testPostVisibility() {
        list($u, $uid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $settings = [
            'mylocation' => [
                'lat' => 55.957571,
                'lng' => -3.205333,
                'name' => 'EH3 6SS'
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));
        $this->addLoginAndLogin($u, 'testpw');

        $ret = $this->call('isochrone', 'GET', []);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['isochrones']));
        $this->assertNotNull($ret['isochrones'][0]['polygon']);

        // No transport returned by default.
        $this->assertFalse(array_key_exists('transport', $ret['isochrones'][0]));
        $this->assertEquals(Isochrone::DEFAULT_TIME, $ret['isochrones'][0]['minutes']);
        $id = $ret['isochrones'][0]['id'];

        // Edit it - should update the same one rather than create a new one.
        $ret = $this->call('isochrone', 'PATCH', [
            'id' => $id,
            'minutes' => 20,
            'transport' => Isochrone::WALK
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('isochrone', 'GET', []);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['isochrones']));
        $this->assertEquals(Isochrone::WALK, $ret['isochrones'][0]['transport']);
        $this->assertEquals(20, $ret['isochrones'][0]['minutes']);

        // Get the updated ID after PATCH (edit() creates a new isochrones_users row)
        $id = $ret['isochrones'][0]['id'];

        $ret = $this->call('isochrone', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        $ret = $this->call('isochrone', 'PUT', [
            'minutes' => 20,
            'transport' => Isochrone::WALK,
            'nickname' => 'UT',
            'locationid' => $lid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);

        # No messages to find.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(0, count($msgs));

        # Add a message near this location.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);

        $email = 'test-' . rand() . '@blackhole.io';
        $u->addEmail($email);
        $this->addLoginAndLogin($u, 'testpw');

        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, $email, 'to@test.com', MailRouter::APPROVED);
        $this->assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Message::TYPE_OFFER, $m->getType());

        # Get it into the spatial index.
        $m->setPrivate('lat', 55.957572);
        $m->setPrivate('lng', -3.205334);
        $m->addToSpatialIndex();

        # Should now appear if we search by isochrone.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(1, count($msgs));
        $this->assertEquals($id, $msgs[0]['id']);

        # Add a postvisibility which excludes this user.
        $email = 'test-' . rand() . '@blackhole.io';
        list($u2, $uid2) = $this->createTestUser(NULL, NULL, 'Test User', $email, 'testpw');
        $u2->addMembership($group1, User::ROLE_MODERATOR);
        $this->assertTrue($u2->login('testpw'));

        $poly = 'POLYGON((-3.18 55.99,-3.1 55.99,-3.1 56.1,-3.18 56.1,-3.18 55.99))';

        $ret = $this->call('group', 'PATCH', [
            'id' => $group1,
            'postvisibility' => $poly
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $group1,
            'polygon' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($poly, $ret['group']['postvisibility']);

        # Log back in as the user - shouldn't be visible.
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->addLoginAndLogin($u, 'testpw');

        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(0, count($msgs));

        # Now set it to include the user.
        $u2 = new User($this->dbhr, $this->dbhm, $uid2);
        $this->assertTrue($u2->login('testpw'));

        $ret = $this->call('group', 'PATCH', [
            'id' => $group1,
            'postvisibility' => 'POLYGON((-3.3 55, -3.3 56, -3.1 56, -3.1 55, -3.3 55))'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Log back in as the user - shouldn't be visible.
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->addLoginAndLogin($u, 'testpw');

        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        $this->assertEquals(1, count($msgs));
    }
}

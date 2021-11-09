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


    protected function setUp()
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
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $settings = [
            'mylocation' => [
                'lat' => 52.5733189,
                'lng' => -2.0355619
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('isochrone', 'GET', []);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['isochrones']));
        assertNotNull($ret['isochrones'][0]['polygon']);

        // No transport returned by default.
        assertFalse(array_key_exists('transport', $ret['isochrones'][0]));
        assertEquals(Isochrone::DEFAULT_TIME, $ret['isochrones'][0]['minutes']);
        $id = $ret['isochrones'][0]['id'];

        // Edit it - should update the same one rather than create a new one.
        $ret = $this->call('isochrone', 'PATCH', [
            'id' => $id,
            'minutes' => 20,
            'transport' => Isochrone::WALK
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('isochrone', 'GET', []);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['isochrones']));
        assertEquals(Isochrone::WALK, $ret['isochrones'][0]['transport']);
        assertEquals(20, $ret['isochrones'][0]['minutes']);

        $ret = $this->call('isochrone', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        assertNotNull($lid);

        $ret = $this->call('isochrone', 'PUT', [
            'minutes' => 20,
            'transport' => Isochrone::WALK,
            'nickname' => 'UT',
            'locationid' => $lid
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);

        # No messages to find.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Add a message near this location.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_REUSE);

        $email = 'test-' . rand() . '@blackhole.io';
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::TYPE_OFFER, $m->getType());

        # Get it into the spatial index.
        $m->setPrivate('lat', 52.6);
        $m->setPrivate('lng', -2.1);
        $m->addToSpatialIndex();

        # Should now appear if we search by isochrone.
        $ret = $this->call('messages', 'GET', [
            'subaction' => 'isochrones'
        ]);

        assertEquals(0, $ret['ret']);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);
    }
}

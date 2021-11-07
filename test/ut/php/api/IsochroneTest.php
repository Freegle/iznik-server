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
    }
}

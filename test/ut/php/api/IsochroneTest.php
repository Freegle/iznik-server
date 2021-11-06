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

        $ret = $this->call('isochrone', 'GET', [
            'minutes' => 10,
            'transport' => Isochrone::CYCLE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['isochrone']['polygon']);
    }
}

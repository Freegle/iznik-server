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
class jobsAPITest extends IznikAPITestCase
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
        $host= gethostname();
        $ip = gethostbyname($host);
        $_SERVER['REMOTE_ADDR'] = $ip;

        $ret = $this->call('jobs', 'GET', [
            'lat' => 52.5733189,
            'lng' => -2.0355619
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertGreaterThanOrEqual(1, count($ret['jobs']));

        $id = $ret['jobs'][0]['id'];

        $ret = $this->call('jobs', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['jobs']));
        $this->assertEquals($id, $ret['jobs'][0]['id']);

        $ret = $this->call('jobs', 'POST', [
            'link' => $ret['jobs'][0]['url']
        ]);

        $this->assertEquals(0, $ret['ret']);
    }
}

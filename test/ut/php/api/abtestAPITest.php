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
class abtestAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preQuery("DELETE FROM abtest WHERE uid = 'UT';");
    }

    public function testBasic()
    {
        $ret = $this->call('abtest', 'POST', [
            'uid' => 'UT',
            'variant' => 'a',
            'shown' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('abtest', 'POST', [
            'uid' => 'UT',
            'variant' => 'b',
            'shown' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('abtest', 'POST', [
            'uid' => 'UT',
            'variant' => 'a',
            'action' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->waitBackground();

        # Now get until we've seen both.
        $seena = FALSE;
        $seenb = FALSE;
        $try = 1000;

        do {
            $this->log("Try get");
            $ret = $this->call('abtest', 'GET', [
                'uid' => 'UT'
            ]);
            $this->assertEquals(0, $ret['ret']);

            $this->log("Returned " . var_export($ret, TRUE));

            if ($ret['variant']['variant'] == 'a') {
                $seena = TRUE;
            }

            if ($ret['variant']['variant'] == 'b') {
                $seenb = TRUE;
            }

            $try--;
        } while ($try > 0 && (!$seena || !$seenb));

        $this->assertGreaterThan(0, $try);
    }
}

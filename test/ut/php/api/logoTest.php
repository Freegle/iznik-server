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
class logoTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM logos WHERE path LIKE '%UTTest%';");
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM logos WHERE path LIKE '%UTTest%';");
        parent::tearDown ();
    }

    public function testBasic() {
        $today = date("m-d", time());

        # There might be a logo in the DB for today; if not, add one.
        $logos = $this->dbhr->preQuery("SELECT * FROM logos WHERE date LIKE ?;", [
            $today
        ]);

        if (count($logos) == 0) {
            $this->dbhm->preExec("INSERT INTO logos (path, date) VALUES (?, ?);", [
                'uttest',
                $today
            ]);
        }

        # No logo for today.
        $ret = $this->call('logo', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('logo', $ret));

        }
}


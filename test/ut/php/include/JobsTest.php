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
class jobsTest extends IznikTestCase
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
        $j = new Jobs($this->dbhr, $this->dbhm);

        assertEquals(['temp support', 'support worker'], Jobs::getKeywords('temp support - worker'));

        $this->dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1;", [
            'temp support'
        ]);
        $this->dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1;", [
            'support worker'
        ]);

        $jobs = $this->dbhr->preQuery("SELECT * FROM jobs LIMIT 1;");

        foreach ($jobs as $job) {
            assertGreaterThanOrEqual(0, $j->clickability($job['id']));
        }
    }

    public function testGeoCode() {
        list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode('Dunsop Bridge', FALSE, TRUE);
        assertEquals(53.9585066, $swlat);
        assertEquals(-2.5658671, $swlng);
        assertEquals(53.9567612, $nelat);
        assertEquals(-2.5633336, $nelng);
        list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode('Dunsop Bridge', TRUE, TRUE);
        assertEquals(53.9445729, $swlat);
        assertEquals(-2.5185855, $swlng);
        assertEquals(53.9455729, $nelat);
        assertEquals(-2.5175855, $nelng);
    }

    public function testScanTOCSV() {
        $this->dbhm->preExec("DELETE FROM jobs WHERE job_reference = ?;", [
            '12726_9694085'
        ]);

        $j = new Jobs($this->dbhr, $this->dbhm);
        $csvFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jobs.csv';
        $j->scanToCSV(IZNIK_BASE . '/test/ut/php/misc/jobs.xml', $csvFile, PHP_INT_MAX, TRUE, 0);
        $j->loadCSV($csvFile);
        $found = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM jobs_new WHERE job_reference = ?;", [
            '634_518020'
        ]);
        assertEquals(1, $found[0]['count']);

        $j->deleteSpammyJobs();
        $j->swapTables();

        $found = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM jobs WHERE job_reference = ?;", [
            '634_518020'
        ]);
        assertEquals(1, $found[0]['count']);
    }
}

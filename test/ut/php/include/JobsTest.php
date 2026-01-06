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
class JobsTest extends IznikTestCase
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

        $this->assertEquals(['temp support', 'support worker'], Jobs::getKeywords('temp support - worker'));

        $this->dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1;", [
            'temp support'
        ]);
        $this->dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1;", [
            'support worker'
        ]);

        $jobs = $this->dbhr->preQuery("SELECT * FROM jobs LIMIT 1;");

        foreach ($jobs as $job) {
            $this->assertGreaterThanOrEqual(0, $j->clickability($job['id']));
        }
    }

    public function testGeoCode() {
        # Test geocoding returns reasonable coordinates for Dunsop Bridge
        # Using 1 decimal place (~10km precision) to allow for geocoding service variations
        list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode('Dunsop Bridge', FALSE, TRUE);
        $this->assertEquals(54.0, round($swlat, 1));
        $this->assertEquals(-2.5, round($swlng, 1));
        $this->assertEquals(53.9, round($nelat, 1));
        $this->assertEquals(-2.5, round($nelng, 1));

        list ($swlat, $swlng, $nelat, $nelng, $geom, $area) = Jobs::geocode('Dunsop Bridge', TRUE, TRUE);
        $this->assertEquals(53.9, round($swlat, 1));
        $this->assertEquals(-2.5, round($swlng, 1));
        $this->assertEquals(53.9, round($nelat, 1));
        $this->assertEquals(-2.5, round($nelng, 1));
    }

    public function testScanTOCSV() {
        $this->dbhm->preExec("DELETE FROM jobs WHERE job_reference = ?;", [
            '12726_9694085'
        ]);

        $j = new Jobs($this->dbhr, $this->dbhm);
        $csvFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jobs.csv';
        $csvFile2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jobs2.csv';
        $j->scanToCSV(IZNIK_BASE . '/test/ut/php/misc/jobs.xml', $csvFile, PHP_INT_MAX, TRUE, 0, 1000, 1);
        $j->scanToCSV(IZNIK_BASE . '/test/ut/php/misc/jobs2.xml', $csvFile2, PHP_INT_MAX, TRUE, 0, 1000, 2);
        $j->prepareForLoadCSV();
        $j->loadCSV($csvFile);
        $j->loadCSV($csvFile2);
        $found = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM jobs_new WHERE job_reference = ?;", [
            '634_518020'
        ]);
        $this->assertEquals(1, $found[0]['count']);
        $found = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM jobs_new WHERE job_reference = ?;", [
            '18794_1836_2834'
        ]);
        $this->assertEquals(1, $found[0]['count']);

        $j->deleteSpammyJobs("jobs_new");
        $j->swapTables();

        $found = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM jobs WHERE job_reference = ?;", [
            '634_518020'
        ]);
        $this->assertEquals(1, $found[0]['count']);
        $found = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM jobs WHERE job_reference = ?;", [
            '18794_1836_2834'
        ]);
        $this->assertEquals(1, $found[0]['count']);
    }

    public function testGetKeywordsEmpty() {
        // Empty string should return empty array.
        $result = Jobs::getKeywords('');
        $this->assertEquals([], $result);
    }

    public function testGetKeywordsSingleWord() {
        // Single word should return empty array (no bigrams).
        $result = Jobs::getKeywords('developer');
        $this->assertEquals([], $result);
    }

    public function testGetKeywordsTwoWords() {
        // Two words should return one bigram.
        $result = Jobs::getKeywords('software developer');
        $this->assertEquals(['software developer'], $result);
    }

    public function testGetKeywordsMultipleWords() {
        // Multiple words should return all bigrams.
        $result = Jobs::getKeywords('senior software developer position');
        $this->assertEquals(['senior software', 'software developer', 'developer position'], $result);
    }

    public function testGetKeywordsLowercase() {
        // Keywords should be lowercase.
        $result = Jobs::getKeywords('Senior Developer');
        $this->assertEquals(['senior developer'], $result);
    }

    public function testGetKeywordsSpecialCharacters() {
        // Special characters should be removed.
        $result = Jobs::getKeywords('full-time developer (remote)');
        $this->assertEquals(['fulltime developer', 'developer remote'], $result);
    }

    public function testGetKeywordsShortWords() {
        // Words with 2 or fewer characters should be filtered out.
        $result = Jobs::getKeywords('a to be developer');
        $this->assertEquals(['developer'], $result);
    }

    public function testGetKeywordsNumbers() {
        // Numbers should be filtered out.
        $result = Jobs::getKeywords('Level 3 support engineer');
        $this->assertEquals(['support engineer'], $result);
    }

    public function testGetKeywordsExtraSpaces() {
        // Extra spaces should be handled.
        $result = Jobs::getKeywords('software   developer');
        $this->assertEquals(['software developer'], $result);
    }

    public function testGetKeywordsMixedCase() {
        // Mixed case should be normalized to lowercase.
        $result = Jobs::getKeywords('PHP Developer WANTED');
        $this->assertEquals(['php developer', 'developer wanted'], $result);
    }
}

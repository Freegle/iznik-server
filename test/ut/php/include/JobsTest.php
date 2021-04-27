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
}

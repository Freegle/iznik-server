<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/misc/PAF.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class PAFTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $this->dbhm->preExec("DELETE FROM locations WHERE name LIKE 'TV1%';");
        $this->dbhm->preExec("DELETE FROM locations WHERE name LIKE 'ZZZZ ZZZ';");
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    public function __construct() {
    }

    public function testLoad() {
        error_log(__METHOD__);

        $l = new Location($this->dbhm, $this->dbhm);
        $pcid = $l->create(NULL, 'TV10 1AA', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        error_log("TV10 1AA => $pcid");
        $pcid = $l->create(NULL, 'TV10 1AB', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        error_log("TV10 1AB => $pcid");
        $pcid = $l->create(NULL, 'TV10 1AF', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        error_log("TV10 1AF => $pcid");
        $pcid = $l->create(NULL, 'ZZZZ ZZZ', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        error_log("ZZZZ ZZZ => $pcid");

        if (file_exists('/tmp/ut_paf0000000000.csv')) {
            unlink('/tmp/ut_paf0000000000.csv');
        }

        global $dbconfig;
        $mock = $this->getMockBuilder('LoggedPDO')
            ->setConstructorArgs([
                "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8",
                $dbconfig['user'], $dbconfig['pass'], array(), TRUE
            ])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willReturn(TRUE);

        $p = new PAF($this->dbhm, $mock);
        error_log("First load - just generates csv.");
        $p->load(UT_DIR . '/php/misc/pc.csv', '/tmp/ut_paf');

        $csv = file_get_contents('/tmp/ut_paf0000000000.csv');
        error_log("CSV is $csv");

        # We
        error_log("Update - postcodes 4 diffs (2 in same UDPRN)");
        self::assertEquals(4, $p->update(UT_DIR . '/php/misc/pc.csv'));

        # Load a version where fields have changed and there's a new one.
        $t = file_get_contents(UT_DIR . '/php/misc/pc2.csv');
        $max = $this->dbhm->preQuery("SELECT MAX(udprn) AS max FROM paf_addresses;");
        $udprn = intval($max[0]['max']) + 1;
        $t = str_replace('zzz', $udprn, $t);
        file_put_contents('/tmp/ut.csv', $t);
        error_log("Update with changes");
        self::assertEquals(5, $p->update('/tmp/ut.csv'));

        $ids = $p->listForPostcode('SA65 9ET');
        assertGreaterThan(0, count($ids));
        $line = $p->getSingleLine($ids[0]);
        error_log($line);
        self::assertEquals("FISHGUARD SA65 9ET", $line);

        error_log(__METHOD__ . " end");
    }
}


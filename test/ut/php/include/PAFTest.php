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

    public function testLoad() {
        assertTrue(TRUE);

        $l = new Location($this->dbhm, $this->dbhm);
        $pcid = $l->create(NULL, 'TV10 1AA', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->log("TV10 1AA => $pcid");
        $pcid = $l->create(NULL, 'TV10 1AB', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->log("TV10 1AB => $pcid");
        $pcid = $l->create(NULL, 'TV10 1AF', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->log("TV10 1AF => $pcid");
        $pcid = $l->create(NULL, 'ZZZZ ZZZ', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->log("ZZZZ ZZZ => $pcid");

        if (!$l->findByName('AB10')) {
            $pcid = $l->create(NULL, 'AB10', 'Postcode', 'POINT(2.126500 57.131300)');
        }

        if (!$l->findByName('AB10 1AA')) {
            $pcid = $l->create(NULL, 'AB10 1AA', 'Postcode', 'POINT(-2.096647896 57.14823188)');
        }

        if (!$l->findByName('AB10 1AF')) {
            $pcid = $l->create(NULL, 'AB10 1AF', 'Postcode', 'POINT(-2.097806027 57.14870708)');
        }

        if (!$l->findByName('AB10 1AB')) {
            $pcid = $l->create(NULL, 'AB10 1AB', 'Postcode', 'POINT(-2.097262456 57.1493033629)');
        }

        if (file_exists('/tmp/ut_paf0000000000.csv')) {
            unlink('/tmp/ut_paf0000000000.csv');
        }

        global $dbconfig;
        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willReturn(TRUE);

        $p = new PAF($this->dbhm, $mock);
        $this->log("First load - just generates csv.");
        $p->load(UT_DIR . '/php/misc/pc.csv', '/tmp/ut_paf');

        $csv = file_get_contents('/tmp/ut_paf0000000000.csv');
        $this->log("CSV is $csv");

        $this->log("Update - postcodes 3 diffs");
        assertGreaterThanOrEqual(3, $p->update(UT_DIR . '/php/misc/pc.csv'));

        # Load a version where fields have changed and there's a new one.
        $t = file_get_contents(UT_DIR . '/php/misc/pc2.csv');
        $max = $this->dbhm->preQuery("SELECT MAX(udprn) AS max FROM paf_addresses;");
        $udprn = intval($max[0]['max']) + 1;
        $t = str_replace('zzz', $udprn, $t);
        file_put_contents('/tmp/ut.csv', $t);
        $this->log("Update with changes");
        self::assertEquals(5, $p->update('/tmp/ut.csv'));

        $pcids = $this->dbhr->preQuery("SELECT paf_addresses.postcodeid, name FROM paf_addresses INNER JOIN locations ON locations.id = paf_addresses.postcodeid WHERE paf_addresses.postcodeid IS NOT NULL LIMIT 1;");
        $name = $pcids[0]['name'];
        $ids = $p->listForPostcode($name);
        assertGreaterThan(0, count($ids));
        $line = $p->getSingleLine($ids[0]);
        $this->log($line);
        assertGreaterThanOrEqual(0, strpos($line, $name));
    }
}


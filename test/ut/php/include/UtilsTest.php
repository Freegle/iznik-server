<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class utilsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testLockScript() {
        $lockh = lockScript('ut');
        assertNotNull($lockh);
        unlockScript($lockh);
    }

    public function testSafeDate() {
        assertEquals('2020-07-20 12:33:00', safeDate('2020-07-20 12:33:00'));
    }

    public function testMedian() {
        assertEquals(2, calculate_median([1, 2, 3]));
        assertEquals(2, calculate_median([1, 2, 2, 3]));
    }
}


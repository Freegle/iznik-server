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
class UtilsClassTest extends IznikTestCase {
    private $dbhr, $dbhm;

    public function testCheckFiles() {
        $dir = Utils::tmpdir();
        touch("$dir/1");
        touch("$dir/2");
        touch("$dir/3");

        assertEquals(3, Utils::checkFiles($dir, 2, 1, 1, 1));
    }

    public function testSafeDate() {
        assertEquals('2020-07-20 12:33:00', Utils::safeDate('2020-07-20 12:33:00'));
    }

    public function testMedian() {
        assertEquals(2, Utils::calculate_median([1, 2, 3]));
        assertEquals(2, Utils::calculate_median([1, 2, 2, 3]));
    }
}


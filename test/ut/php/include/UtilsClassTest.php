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
class UtilsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    public function testCheckFiles() {
        $dir = Utils::tmpdir();
        touch("$dir/1");
        touch("$dir/2");
        touch("$dir/3");

        assertEquals(3, Utils::checkFiles($dir, 2, 1, 1, 1));
    }
}


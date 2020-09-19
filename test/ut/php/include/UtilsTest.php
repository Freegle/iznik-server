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
class utilsTest extends IznikTestCase {
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

    public function testlockScript() {
        $lockh = Utils::lockScript('ut');
        assertNotNull($lockh);
        Utils::unlockScript($lockh);
    }

    /**
     * @dataProvider UTF8provider
     */
    public function testFilterResult($type, $val) {
        #error_log("Args " . var_export($args, TRUE));

        $ret = [
            $type => $val
        ];

        error_log("$type => $val");
        Utils::filterResult($ret, NULL);
        $enc = json_encode($ret, JSON_PARTIAL_OUTPUT_ON_ERROR);
        assertNotNull($enc);
        error_log("Enc $enc");
        $dec = json_decode($enc, TRUE );
        assertNotNull($dec);
        assertTrue(array_key_exists($type, $dec));
    }

    public function UTF8provider() : \Generator {
        foreach ([
            [ 'Valid ASCII' , "a" ],
            [ 'Valid 2 Octet Sequence' , "\xc3\xb1" ],
            [ 'Invalid 2 Octet Sequence' , "\xc3\x28" ],
            [ 'Invalid Sequence Identifier' , "\xa0\xa1" ],
            [ 'Valid 3 Octet Sequence' , "\xe2\x82\xa1" ],
            [ 'Invalid 3 Octet Sequence (in 2nd Octet)' , "\xe2\x28\xa1" ],
            [ 'Invalid 3 Octet Sequence (in 3rd Octet)' , "\xe2\x82\x28" ],
            [ 'Valid 4 Octet Sequence' , "\xf0\x90\x8c\xbc" ],
            [ 'Invalid 4 Octet Sequence (in 2nd Octet)' , "\xf0\x28\x8c\xbc" ],
            [ 'Invalid 4 Octet Sequence (in 3rd Octet)' , "\xf0\x90\x28\xbc" ],
            [ 'Invalid 4 Octet Sequence (in 4th Octet)' , "\xf0\x28\x8c\x28" ],
            [ 'Valid 5 Octet Sequence (but not Unicode!)' , "\xf8\xa1\xa1\xa1\xa1" ],
            [ 'Valid 6 Octet Sequence (but not Unicode!)' , "\xfc\xa1\xa1\xa1\xa1\xa1" ],
        ] as $res) {
            yield $res;
        };
    }
}


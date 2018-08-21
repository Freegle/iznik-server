<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class supportersTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        error_log(__METHOD__);

        $ret = $this->call('supporters', 'GET', [
        ]);
        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists('supporters', $ret));
        assertTrue(array_key_exists('Wowzer', $ret['supporters']));
        assertTrue(array_key_exists('Front Page', $ret['supporters']));
        assertTrue(array_key_exists('Supporter', $ret['supporters']));

        error_log(__METHOD__ . " end");
    }
}


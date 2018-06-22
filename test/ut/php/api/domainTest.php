<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class domainAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("REPLACE INTO domains_common (domain, count) VALUES ('test.com', 1);");
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    public function __construct() {
    }

    public function testBasic() {
        error_log(__METHOD__);

        $ret = $this->call('domains', 'GET', [
            'domain' => 'test.com'
        ]);
        error_log("Should be no suggestions " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertFalse(array_key_exists('suggestions', $ret));

        $ret = $this->call('domains', 'GET', [
            'domain' => 'tset.com'
        ]);
        error_log("Should be suggestions " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists('suggestions', $ret));

        error_log(__METHOD__ . " end");
    }
}


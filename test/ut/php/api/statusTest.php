<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class statusTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    public function __construct() {
    }

    public function testBasic() {
        error_log(__METHOD__);

        if (!file_exists('/tmp/iznik.status')) {
            file_put_contents('/tmp/iznik.status', json_encode([ 'ret' => 0 ]));
        }

        $ret = $this->call('status', 'GET', []);
        assertEquals(0, $ret['ret']);

        error_log(__METHOD__ . " end");
    }
}


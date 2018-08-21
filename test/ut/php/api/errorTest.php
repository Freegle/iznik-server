<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class errorAPITest extends IznikAPITestCase
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
        error_log(__METHOD__);

        $ret = $this->call('error', 'PUT', [
            'errortype' => 'Exception',
            'errortext' => 'Test Exception'
        ]);
        assertEquals(0, $ret['ret']);

        error_log(__METHOD__ . " end");
    }
}

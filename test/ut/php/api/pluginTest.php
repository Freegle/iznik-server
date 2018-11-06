<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/misc/Polls.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class pluginAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;
    }

    public function testSheila() {
        error_log(__METHOD__);

        $_SESSION['id'] = 25880780;
        $this->dbhr->errorLog = TRUE;
        $this->dbhm->errorLog = TRUE;
        $ret = $this->call('plugin', 'GET', []);
        error_log("Duration {$ret['duration']} DB {$ret['dbwaittime']}");
        $this->dbhr->errorLog = FALSE;
        $this->dbhm->errorLog = FALSE;

        error_log(__METHOD__ . " end");
    }
}


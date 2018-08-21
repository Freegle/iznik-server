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
class profileTest extends IznikAPITestCase {
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

    public function testBasic() {
        error_log(__METHOD__);

        $u = new User($this->dbhr, $this->dbhm);

        $uid = $u->findByEmail('edwardhibbert59@gmail.com');

        if (!$uid) {
            $u->create('Test', 'User', 'Test User');
            $u->addEmail('edwardhibbert59@gmail.com');
        }

        $ret = $this->call('profile', 'GET', [
            'id' => $uid,
            'ut' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists('url', $ret));

        error_log(__METHOD__ . " end");
    }
}


<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/noticeboard/Noticeboard.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class noticeboardAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhm;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
    }

    protected function tearDown() {
        $this->dbhm->preExec("DELETE FROM noticeboards WHERE name LIKE 'UTTest%';");
        parent::tearDown ();
    }

    public function testBasic() {
        # Create logged out - error
        $ret = $this->call('noticeboard', 'POST', []);
        assertEquals(1, $ret['ret']);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Invalid parameters
        $ret = $this->call('noticeboard', 'POST', [ 'dup' => 1]);
        assertEquals(2, $ret['ret']);

        $ret = $this->call('noticeboard', 'GET', [
            'id' => -1
        ]);
        assertEquals(2, $ret['ret']);

        # Valid create
        $ret = $this->call('noticeboard', 'POST', [
            'name' => 'UTTest',
            'lat' => 8.53333,
            'lng' => 179.2167,
            'description' => 'Test description'
        ]);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        $ret = $this->call('noticeboard', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['noticeboard']['id']);
        assertEquals('UTTest', $ret['noticeboard']['name']);
        assertEquals('Test description', $ret['noticeboard']['description']);
        assertEquals(8.5333, $ret['noticeboard']['lat']);
        assertEquals(179.2167, $ret['noticeboard']['lng']);
    }
}


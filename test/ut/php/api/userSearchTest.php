<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');
require_once(UT_DIR . '/IznikAPITestCase.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class userSearchAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testSpecial() {
        list($this->user, $this->uid, $emailid) = $this->createTestUserAndLogin(NULL, NULL, 'Test User');
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $ret = $this->call('user', 'GET', [
            'search' => 'hellsauntie@uwclub.net'
        ]);

        $this->log("Got " . var_export($ret, TRUE));
    }

    public function testCreateDelete() {
        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

        $s = new UserSearch($this->dbhr, $this->dbhm);
        $id = $s->create($this->uid, NULL, 'testsearch');
        
        $ret = $this->call('usersearch', 'GET', []);
        $this->assertEquals(1, $ret['ret']);

        $this->assertTrue($this->user->login('testpw'));

        $ret = $this->call('usersearch', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['usersearches']));
        $this->assertEquals($id, $ret['usersearches'][0]['id']);

        $ret = $this->call('usersearch', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['usersearch']['id']);

        $ret = $this->call('usersearch', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('usersearch', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['usersearches']));

        $s->delete();
    }
}


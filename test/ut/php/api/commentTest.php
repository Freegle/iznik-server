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
class commentAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function tearDown(): void
    {
    }

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM mod_configs WHERE name LIKE 'UTTest%';");

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid, User::ROLE_OWNER);
        $this->assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($this->user->login('testpw'));

        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
    }

    public function testBasic() {
        $ret = $this->call('comment', 'POST', [
            'userid' => $this->uid2,
            'groupid' => $this->groupid,
            'user1' => 'Test comment'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        $ret = $this->call('comment', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->uid, $ret['comment']['byuserid']);
        $this->assertEquals('Test comment', $ret['comment']['user1']);

        $ret = $this->call('comment', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['comments']));
        $this->assertEquals($this->uid, $ret['comments'][0]['byuser']['id']);
        $this->assertEquals('Test comment', $ret['comments'][0]['user1']);

        $ret = $this->call('comment', 'PUT', [
            'id' => $id,
            'user1' => 'Test comment2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('comment', 'GET', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->uid, $ret['comment']['byuserid']);
        $this->assertEquals('Test comment2', $ret['comment']['user1']);

        $ret = $this->call('comment', 'GET', [
            'context' => [
                'reviewed' => '2040-09-11'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['comments']));

        $ret = $this->call('comment', 'GET', [
            'context' => [
                'reviewed' => '1970-09-11'
            ]
        ]);
        error_log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, count($ret['comments']));

        $ret = $this->call('comment', 'DELETE', [
            'id' => $id
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testSupport() {
        // Support can add comments which aren't on groups.
        $ret = $this->call('comment', 'POST', [
            'userid' => $this->uid2,
            'user1' => 'Test comment'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $ret = $this->call('comment', 'POST', [
            'userid' => $this->uid2,
            'user1' => 'Test comment',
            'dup' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);
    }
}


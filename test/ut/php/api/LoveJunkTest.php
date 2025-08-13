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
class LoveJunkAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    public function testRemoveUser() {
        $this->dbhm->preExec("DELETE FROM users WHERE ljuserid = 456;");
        list($u1, $uid1, $emailid1) = $this->createTestUser(null, null, 'Test User', 'test@test.com', 'testpw');
        $u1->setPrivate('ljuserid', 456);

        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec(
            "INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);",
            [$key, 'lovejunk.com']
        );
        $this->assertNotNull($id);

        $ret = $this->call('session', 'POST', [
            'action' => 'Forget',
            'id' => -1
        ]);

        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('session', 'POST', [
            'action' => 'Forget',
            'id' => $uid1,
            'partner' => $key . "1"
        ]);

        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('session', 'POST', [
            'action' => 'Forget',
            'id' => $uid1,
            'partner' => $key
        ]);

        $this->assertEquals(0, $ret['ret']);

        $u1 = new User($this->dbhr, $this->dbhm, $uid1);
        $this->assertNotNull($u1->getPrivate('deleted'));
    }
}
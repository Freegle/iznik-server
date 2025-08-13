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
class pushNotificationsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id2 = $u->create('Test', 'User', NULL);
        $id = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $this->log("Send app User.");
        $n->add($id, PushNotifications::PUSH_FCM_ANDROID, 'test');
        $this->assertEquals(1, count($n->get($id)));

        # Nothing to notify on MT.
        $this->assertEquals(0, $mock->notify($id, TRUE));

        # Fake an FD notification for the user.
        $sql = "INSERT INTO users_notifications (`fromuser`, `touser`, `type`, `title`) VALUES (?, ?, ?, 'Test');";
        $this->dbhm->preExec($sql, [ $id, $id, Notifications::TYPE_EXHORT ]);

        $this->assertEquals(1, $mock->notify($id));
        $this->assertEquals(1, $mock->notify($id));

        $n->add($id, PushNotifications::PUSH_FIREFOX, 'test2');
        $this->assertEquals(2, count($n->get($id)));
        $this->assertEquals(1, $n->notify($id));

        # Test notifying mods.
        $this->log("Notify group mods");
        $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE subscription LIKE 'Test%';");
        $n->add($id, PushNotifications::PUSH_GOOGLE, 'test3', TRUE);

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Create a chat message from the user to the mods.
        $r = new ChatRoom($this->dbhm, $this->dbhm);
        $rid = $r->createUser2Mod($id2, $this->groupid);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        list ($cm, $banned) = $m->create($rid, $id2, "Testing", ChatMessage::TYPE_DEFAULT, NULL, TRUE, NULL, NULL, NULL, NULL);
        $this->assertNotNull($cm);

        $this->assertEquals(1, $mock->notifyGroupMods($this->groupid));

        $n->remove($id);
        $this->assertEquals([], $n->get($id));
    }

    public function testExecuteOld() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willReturn(TRUE);
        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception('UT'));

        $rc = $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);
        $this->assertNotNull($rc['exception']);

        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', [
            'count' => 1,
            'title' => 'UT'
        ]);
        $this->assertNotNull($rc['exception']);
    }

    public function testExecuteFCM() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willReturn(TRUE);
        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception('UT'));

        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_ANDROID, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'UT',
            'chatids' => [ 1 ]
        ]);
        $this->assertNotNull($rc['exception']);

        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_IOS, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => 'UT',
            'chatids' => [ 1 ]
        ]);
        $this->assertNotNull($rc['exception']);

        $rc = $mock->executeSend(0, PushNotifications::PUSH_FCM_IOS, [], 'test', [
            'count' => 1,
            'title' => 'UT',
            'message' => '',
            'chatids' => [ 1 ]
        ]);
        $this->assertNotNull($rc['exception']);
    }

    public function testPoke() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->enableProxyingToOriginalMethods()
            ->getMock();
        $this->assertEquals(TRUE, $mock->poke($id, [ 'ut' => 1 ]));

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->enableProxyingToOriginalMethods()
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());
        $this->assertEquals(FALSE, $mock->poke($id, [ 'ut' => 1 ]));

        }

    public function testErrors() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fsockopen'))
            ->getMock();
        $mock->method('fsockopen')->willThrowException(new \Exception());
        $mock->executePoke($id, [ 'ut' => 1 ]);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fputs'))
            ->getMock();
        $mock->method('fputs')->willThrowException(new \Exception());
        $mock->executePoke($id, [ 'ut' => 1 ]);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fsockopen'))
            ->getMock();
        $mock->method('fsockopen')->willReturn(NULL);
        $mock->executePoke($id, [ 'ut' => 1 ]);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('puts'))
            ->getMock();
        $mock->method('puts')->willReturn(NULL);
        $mock->executePoke($id, [ 'ut' => 1 ]);

        $this->assertTrue(TRUE);
    }
}


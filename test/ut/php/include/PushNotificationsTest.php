<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/message/Message.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class pushNotificationsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new Exception());

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $this->log("Send Google");
        $n->add($id, PushNotifications::PUSH_GOOGLE, 'test');
        assertEquals(1, count($n->get($id)));
        assertEquals(1, $mock->notify($id));
        $this->log("Send Firefox");
        $n->add($id, PushNotifications::PUSH_FIREFOX, 'test2');
        assertEquals(2, count($n->get($id)));
        assertEquals(2, $n->notify($id));
        $this->log("Send Android");

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        $this->log("Notify group mods");
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);
        assertEquals(2, $mock->notifyGroupMods($this->groupid));

        $n->remove($id);
        assertEquals([], $n->get($id));

        }

    public function testExecute() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willReturn(TRUE);
        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new Exception());
        $mock->executeSend(0, PushNotifications::PUSH_GOOGLE, [], 'test', NULL);

        assertTrue(TRUE);

        }

    public function testPoke() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->enableProxyingToOriginalMethods()
            ->getMock();
        assertEquals(TRUE, $mock->poke($id, [ 'ut' => 1 ], FALSE));

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->enableProxyingToOriginalMethods()
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new Exception());
        assertEquals(FALSE, $mock->poke($id, [ 'ut' => 1 ], FALSE));

        }

    public function testErrors() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fsockopen'))
            ->getMock();
        $mock->method('fsockopen')->willThrowException(new Exception());
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fputs'))
            ->getMock();
        $mock->method('fputs')->willThrowException(new Exception());
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('fsockopen'))
            ->getMock();
        $mock->method('fsockopen')->willReturn(NULL);
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('puts'))
            ->getMock();
        $mock->method('puts')->willReturn(NULL);
        $mock->executePoke($id, [ 'ut' => 1 ], FALSE);

        assertTrue(TRUE);

        }
}


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
        error_log(__METHOD__);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        error_log("Created $id");

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new Exception());

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        error_log("Send Google");
        $n->add($id, PushNotifications::PUSH_GOOGLE, 'test');
        assertEquals(1, count($n->get($id)));
        assertEquals(1, $mock->notify($id));
        error_log("Send Firefox");
        $n->add($id, PushNotifications::PUSH_FIREFOX, 'test2');
        assertEquals(2, count($n->get($id)));
        assertEquals(2, $n->notify($id));
        error_log("Send Android");

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);
        error_log("Notify group mods");
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);
        assertEquals(2, $mock->notifyGroupMods($this->groupid));

        $n->remove($id);
        assertEquals([], $n->get($id));

        error_log(__METHOD__ . " end");
    }

    public function testExecute() {
        error_log(__METHOD__);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        error_log("Created $id");

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

        error_log(__METHOD__ . " end");
    }

    public function testPoke() {
        error_log(__METHOD__);

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

        error_log(__METHOD__ . " end");
    }

    public function testErrors() {
        error_log(__METHOD__);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        error_log("Created $id");

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

        error_log(__METHOD__ . " end");
    }
}


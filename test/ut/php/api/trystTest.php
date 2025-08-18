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
class trystTest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        $email1 = 'test1@test.com';
        $email2 = 'test2@test.com';
        
        list($u1, $u1id, $emailid1) = $this->createTestUserAndLogin(NULL, NULL, 'Test User', $email1, 'testpw');
        
        list($u2, $u2id, $emailid2) = $this->createTestUserAndLogin(NULL, NULL, 'Test User', $email2, 'testpw');
        
        list($u3, $u3id, $emailid3) = $this->createTestUserAndLogin(NULL, NULL, 'Test User', 'test3@test.com', 'testpw');

        # Create logged out - fail.
        $_SESSION['id'] = NULL;
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06') // Just in time.
        ]);

        $this->assertEquals(1, $ret['ret']);

        # Create logged in - should work.
        $this->assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        # Read it back.
        $ret = $this->call('tryst', 'GET', [
          'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['tryst']['id']);
        $this->assertEquals($u1id, $ret['tryst']['user1']);
        $this->assertEquals($u2id, $ret['tryst']['user2']);
        $this->assertEquals(Utils::ISODate('2038-01-19 03:14:06'), $ret['tryst']['arrangedfor']);
        $arrangedat = $ret['tryst']['arrangedat'];
        $this->assertNotNull($arrangedat);

        # List
        $ret = $this->call('tryst', 'GET', []);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['trysts']));
        $this->assertEquals($id, $ret['trysts'][0]['id']);

        # As the other user
        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['tryst']['id']);

        # Either user can edit.
        $ret = $this->call('tryst', 'PATCH', [
          'id' => $id,
          'arrangedfor' => Utils::ISODate('2038-01-19 03:14:07'),
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(Utils::ISODate('2038-01-19 03:14:07'), $ret['tryst']['arrangedfor']);

        # Another user shouldn't be able to see it.
        $this->assertTrue($u3->login('testpw'));
        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        $this->assertEquals(2, $ret['ret']);

        # Send the invites, mocking out the mail.
        $t = $this->getMockBuilder('Freegle\Iznik\Tryst')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('sendIt'))
            ->getMock();
        $t->method('sendIt')->willReturn(FALSE);
        $this->assertEquals(1, $t->sendCalendarsDue($id));

        # Send an accept
        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        $this->assertNull($t->getPrivate('user1response'));
        $this->assertNull($t->getPrivate('user2response'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/trystaccept'));
        $msg = str_replace('<email1>', $email1, $msg);
        $msg = str_replace('<email2>', $u2->getOurEmail(), $msg);
        $msg = str_replace('<handoverid>', $id, $msg);
        $msg = str_replace('<uid1>', $u1id, $msg);
        $msg = str_replace('<uid2>', $u2id, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::EMAIL, $email1, "handover-$id-$u1id@" . USER_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TRYST, $rc);

        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Tryst::ACCEPTED, $t->getPrivate('user1response'));
        $this->assertNull($t->getPrivate('user2response'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/trystnosubj'));
        $msg = str_replace('<email2>', $email1, $msg);
        $msg = str_replace('<email1>', $u2->getOurEmail(), $msg);
        $msg = str_replace('<handoverid>', $id, $msg);
        $msg = str_replace('<uid2>', $u1id, $msg);
        $msg = str_replace('<uid1>', $u2id, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::EMAIL, $email2, "handover-$id-$u2id@" . USER_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TRYST, $rc);

        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Tryst::ACCEPTED, $t->getPrivate('user1response'));
        $this->assertEquals(Tryst::ACCEPTED, $t->getPrivate('user2response'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/trystdecline'));
        $msg = str_replace('<email1>', $email1, $msg);
        $msg = str_replace('<email2>', $u2->getOurEmail(), $msg);
        $msg = str_replace('<handoverid>', $id, $msg);
        $msg = str_replace('<uid1>', $u1id, $msg);
        $msg = str_replace('<uid2>', $u2id, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::EMAIL, $email1, "handover-$id-$u1id@" . USER_DOMAIN, $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::TRYST, $rc);

        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(Tryst::DECLINED, $t->getPrivate('user1response'));
        $this->assertEquals(Tryst::ACCEPTED, $t->getPrivate('user2response'));

        # Delete it.
        $this->assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'DELETE', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
    }

    public function testReminder() {
        $email1 = 'test-' . rand() . '@blackhole.io';
        list($u1, $u1id, $emailid1) = $this->createTestUser('Test','User', 'Test User', $email1, 'testpw');
        
        $email2 = 'test-' . rand() . '@blackhole.io';
        list($u2, $u2id, $emailid2) = $this->createTestUser('Test','User', 'Test User', $email2, 'testpw');

        $this->assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        # No reminders - too far in advance.
        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        $ret = $t->sendRemindersDue($id);
        $this->assertEquals(0, $ret[0]);
        $this->assertEquals(0, $ret[1]);

        # No reminder - was arranged today.
        $t->setPrivate('arrangedat', (new \DateTime())->format('Y-m-d H:i:s'));
        $t->setPrivate('arrangedfor', (new \DateTime())->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s'));
        $ret = $t->sendRemindersDue($id);
        $this->assertEquals(0, $ret[0]);
        $this->assertEquals(0, $ret[1]);

        # No phone, reminder in chat.
        $t->setPrivate('arrangedat', (new \DateTime())->sub(new \DateInterval('P1D'))->format('Y-m-d H:i:s'));
        $t->setPrivate('arrangedfor', (new \DateTime())->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s'));
        $ret = $t->sendRemindersDue($id);
        $this->assertEquals(0, $ret[0]);
        $this->assertEquals(1, $ret[1]);
        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        $t->setPrivate('remindersent', NULL);
        $t = new Tryst($this->dbhr, $this->dbhm, $id);

        # Reminder - phone and chat.
        $u1->addPhone('123');
        $ret = $t->sendRemindersDue($id);
        $this->assertEquals(1, $ret[0]);
        $this->assertEquals(1, $ret[1]);

        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'confirmed' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'declined' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        # No reminder - already sent.
        $this->assertEquals(0, $t->sendRemindersDue($id)[0]);
        $this->assertEquals(0, $t->sendRemindersDue($id)[1]);
    }

    public function testConfirm() {
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u1 = User::get($this->dbhr, $this->dbhm, $u1id);
        $email1 = 'test-' . rand() . '@blackhole.io';
        $u1->addEmail($email1);
        $u2id = $u->create('Test','User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm, $u2id);
        $email2 = 'test-' . rand() . '@blackhole.io';
        $u2->addEmail($email2);

        $this->assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'confirm' => TRUE,
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Other user.
        $this->assertTrue($u2->login('testpw'));
        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'confirm' => TRUE,
            'dup' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testDecline() {
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u1 = User::get($this->dbhr, $this->dbhm, $u1id);
        $email1 = 'test-' . rand() . '@blackhole.io';
        $u1->addEmail($email1);
        $u2id = $u->create('Test','User', 'Test User');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm, $u2id);
        $email2 = 'test-' . rand() . '@blackhole.io';
        $u2->addEmail($email2);

        $this->assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        $this->assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->assertNotNull($id);

        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'decline' => TRUE,
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Other user.
        $this->assertTrue($u2->login('testpw'));
        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'decline' => TRUE,
            'dup' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
    }
}


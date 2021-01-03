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

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u1 = User::get($this->dbhr, $this->dbhm, $u1id);
        $email1 = 'test-' . rand() . '@blackhole.io';
        $u1->addEmail($email1);
        $u2id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm, $u2id);
        $email2 = 'test-' . rand() . '@blackhole.io';
        $u2->addEmail($email2);
        $u3id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u3 = User::get($this->dbhr, $this->dbhm, $u3id);

        # Create logged out - fail.
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06') // Just in time.
        ]);

        assertEquals(1, $ret['ret']);

        # Create logged in - should work.
        assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        # Read it back.
        $ret = $this->call('tryst', 'GET', [
          'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['tryst']['id']);
        assertEquals($u1id, $ret['tryst']['user1']);
        assertEquals($u2id, $ret['tryst']['user2']);
        assertEquals(Utils::ISODate('2038-01-19 03:14:06'), $ret['tryst']['arrangedfor']);
        $arrangedat = $ret['tryst']['arrangedat'];
        assertNotNull($arrangedat);

        # List
        $ret = $this->call('tryst', 'GET', []);

        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['trysts']));
        assertEquals($id, $ret['trysts'][0]['id']);

        # As the other user
        assertTrue($u2->login('testpw'));
        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['tryst']['id']);

        # Either user can edit.
        $ret = $this->call('tryst', 'PATCH', [
          'id' => $id,
          'arrangedfor' => Utils::ISODate('2038-01-19 03:14:07'),
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(Utils::ISODate('2038-01-19 03:14:07'), $ret['tryst']['arrangedfor']);

        # Another user shouldn't be able to see it.
        assertTrue($u3->login('testpw'));
        $ret = $this->call('tryst', 'GET', [
            'id' => $id
        ]);

        assertEquals(2, $ret['ret']);

        # Send the invites, mocking out the mail.
        $t = $this->getMockBuilder('Freegle\Iznik\Tryst')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('sendIt'))
            ->getMock();
        $t->method('sendIt')->willReturn(false);
        assertEquals(1, $t->sendCalendarsDue($id));

        # Send an accept
        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        assertNull($t->getPrivate('user1response'));
        assertNull($t->getPrivate('user2response'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/trystaccept'));
        $msg = str_replace('<email1>', $email1, $msg);
        $msg = str_replace('<email2>', $u2->getOurEmail(), $msg);
        $msg = str_replace('<handoverid>', $id, $msg);
        $msg = str_replace('<uid1>', $u1id, $msg);
        $msg = str_replace('<uid2>', $u2id, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::EMAIL, $email1, "handover-$id-$u1id@" . USER_DOMAIN, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TRYST, $rc);

        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        assertEquals(Tryst::ACCEPTED, $t->getPrivate('user1response'));
        assertNull($t->getPrivate('user2response'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/trystnosubj'));
        $msg = str_replace('<email1>', $email1, $msg);
        $msg = str_replace('<email2>', $u2->getOurEmail(), $msg);
        $msg = str_replace('<handoverid>', $id, $msg);
        $msg = str_replace('<uid1>', $u1id, $msg);
        $msg = str_replace('<uid2>', $u2id, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::EMAIL, $email1, "handover-$id-$u1id@" . USER_DOMAIN, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TRYST, $rc);

        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        assertEquals(Tryst::ACCEPTED, $t->getPrivate('user1response'));
        assertNull($t->getPrivate('user2response'));

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/trystdecline'));
        $msg = str_replace('<email1>', $email1, $msg);
        $msg = str_replace('<email2>', $u2->getOurEmail(), $msg);
        $msg = str_replace('<handoverid>', $id, $msg);
        $msg = str_replace('<uid1>', $u1id, $msg);
        $msg = str_replace('<uid2>', $u2id, $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->received(Message::EMAIL, $email1, "handover-$id-$u1id@" . USER_DOMAIN, $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TRYST, $rc);

        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        assertEquals(Tryst::DECLINED, $t->getPrivate('user1response'));
        assertNull($t->getPrivate('user2response'));

        # Delete it.
        assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'DELETE', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);
    }

    public function testReminder() {
        $u = User::get($this->dbhr, $this->dbhm);

        $u1id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u1 = User::get($this->dbhr, $this->dbhm, $u1id);
        $email1 = 'test-' . rand() . '@blackhole.io';
        $u2id = $u->create('Test','User', 'Test User');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm, $u2id);
        $email2 = 'test-' . rand() . '@blackhole.io';

        assertTrue($u1->login('testpw'));
        $ret = $this->call('tryst', 'PUT', [
            'user1' => $u1id,
            'user2' => $u2id,
            'arrangedfor' => Utils::ISODate('2038-01-19 03:14:06'),
            'dup' => 1
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        # No reminders - too far in advance.
        $t = new Tryst($this->dbhr, $this->dbhm, $id);
        assertEquals(0, $t->sendRemindersDue($id));

        # No reminder - was arranged today.
        $t->setPrivate('arrangedat', (new \DateTime())->format('Y-m-d H:i:s'));
        $t->setPrivate('arrangedfor', (new \DateTime())->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s'));
        assertEquals(0, $t->sendRemindersDue($id));

        # No reminder - no phone or email.
        $t->setPrivate('arrangedat', (new \DateTime())->sub(new \DateInterval('P1D'))->format('Y-m-d H:i:s'));
        $t->setPrivate('arrangedfor', (new \DateTime())->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s'));
        assertEquals(0, $t->sendRemindersDue($id));

        # Reminder - phone.
        $u1->addPhone('123');
        assertEquals(1, $t->sendRemindersDue($id));

        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'confirmed' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('tryst', 'POST', [
            'id' => $id,
            'declined' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        # No reminder - already sent.
        assertEquals(0, $t->sendRemindersDue($id));
    }
}


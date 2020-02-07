<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/user/PushNotifications.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class sessionTest extends IznikAPITestCase
{
    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->msgsSent = [];

        $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE `type` = 'Test';");
    }

    public function sendMock($mailer, $message)
    {
        $this->msgsSent[] = $message->toString();
    }

    public function testLoggedOut()
    {
        $ret = $this->call('session', 'GET', []);
        assertEquals(1, $ret['ret']);

        }


    public function testLargeRequest()
    {
        $str = '';
        while (strlen($str) < 200000) {
            $str .= '1234123412';
        }

        $ret = $this->call('session', 'POST', [
            'junk' => $str
        ]);

        assertEquals(1, $ret['ret']);

        }

    public function testYahoo()
    {
        # Logged out should cause redirect
        $ret = $this->call('session', 'POST', [
            'yahoologin' => 1
        ]);
        assertEquals(1, $ret['ret']);
        assertTrue(array_key_exists('redirect', $ret));

        # Login.  Create Yahoo class then mock it.
        $y = Yahoo::getInstance($this->dbhr, $this->dbhm);
        $email = 'test' . microtime() . '@test.com';
        $mock = $this->getMockBuilder('LightOpenID')
            ->disableOriginalConstructor()
            ->setMethods(array('validate', 'getAttributes'))
            ->getMock();
        $mock->method('validate')->willReturn(true);
        $mock->method('getAttributes')->willReturn([
            'contact/email' => $email,
            'name' => 'Test User'
        ]);
        $y->setOpenid($mock);

        $ret = $this->call('session', 'POST', [
            'yahoologin' => 1
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);

        $mock->method('getAttributes')->willThrowException(new Exception());
        $ret = $this->call('session', 'POST', [
            'yahoologin' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Logout
        $ret = $this->call('session', 'DELETE', []);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'DELETE', []);
        assertEquals(0, $ret['ret']);#

        # Should be logged out
        $ret = $this->call('session', 'GET', []);
        assertEquals(1, $ret['ret']);

        }

    public function testFacebook()
    {
        # With no token should fail.
        $ret = $this->call('session', 'POST', [
            'fblogin' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Rest of testing done in include test.

        }

    public function testGoogle()
    {
        # With no token should fail.
        $ret = $this->call('session', 'POST', [
            'googlelogin' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Rest of testing done in include test.

        }

    public function testNative()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertNotNull($u->addEmail('test@test.com'));

        # Mock the user ("your hair looks terrible") to check the welcome mail is sent.
        $u = $this->getMockBuilder('User')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, $id])
            ->setMethods(array('sendIt'))
            ->getMock();
        $u->method('sendIt')->will($this->returnCallback(function ($mailer, $message) {
            return ($this->sendMock($mailer, $message));
        }));

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $group1);
        $g->setPrivate('welcomemail', 'Test - please ignore');
        $this->log("Add first time");
        $u->addMembership($group1);

        self::assertEquals(1, count($this->msgsSent));

        # Add membership again and check the welcome is not sent.
        $this->log("Add second time");
        $u->addMembership($group1);
        self::assertEquals(1, count($this->msgsSent));

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

        $this->log("Session get");
        $this->dbhr->setErrorLog(TRUE);
        $this->dbhm->setErrorLog(TRUE);
        $ret = $this->call('session', 'GET', []);
        $this->dbhr->setErrorLog(FALSE);
        $this->dbhm->setErrorLog(FALSE);
        $this->log("Session got");
        assertEquals(0, $ret['ret']);
        assertEquals($group1, $ret['groups'][0]['id']);
        assertEquals('test@test.com', $ret['emails'][0]['email']);

        # Set something
        $ret = $this->call('session', 'PATCH', [
            'settings' => ['test' => 1],
            'displayname' => "Testing User",
            'email' => 'test2@test.com',
            'onholidaytill' => ISODate('@' . time()),
            'relevantallowed' => 0,
            'notifications' => [
                'push' => [
                    'type' => 'Google',
                    'subscription' => 'Test'
                ]
            ]
        ]);
        assertEquals(10, $ret['ret']);
        $ret = $this->call('session', 'GET', []);
        $this->log(var_export($ret, true));
        assertEquals(0, $ret['ret']);
        assertEquals([
            "test" => 1,
            'notificationmails' => true,
            'modnotifs' => 4,
            'backupmodnotifs' => 12,
            'notifications' => [
                'email' => TRUE,
                'emailmine' => FALSE,
                'push' => TRUE,
                'facebook' => TRUE,
                'app' => TRUE
            ]
        ], $ret['me']['settings']);
        assertEquals('Testing User', $ret['me']['displayname']);
        assertEquals('test@test.com', $ret['me']['email']);
        assertFalse(array_key_exists('relevantallowed', $ret['me']));

        # Confirm it
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'test2@test.com';");
        assertEquals(1, count($emails));
        foreach ($emails as $email) {
            $ret = $this->call('session', 'PATCH', [
                'key' => 'wibble'
            ]);
            assertEquals(11, $ret['ret']);

            $ret = $this->call('session', 'PATCH', [
                'key' => $email['validatekey']
            ]);
            assertEquals(0, $ret['ret']);
        }

        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        $this->log("Confirmed " . var_export($ret, TRUE));
        assertEquals('test2@test.com', $ret['me']['email']);

        $ret = $this->call('session', 'PATCH', [
            'settings' => ['test' => 1],
            'displayname' => "Testing User",
            'email' => 'test2@test.com',
            'notifications' => [
                'push' => [
                    'type' => 'Firefox',
                    'subscription' => 'Test'
                ]
            ]
        ]);
        assertEquals(0, $ret['ret']);

        # Quick test for notification coverage.
        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('curl_exec'))
            ->getMock();
        $mock->method('curl_exec')->willReturn('NotRegistered');
        $mock->notify($id);

        $mock = $this->getMockBuilder('PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new Exception());
        $mock->notify($id);

        $g->delete();

        }

    public function testUidAndKey()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $l = $u->loginLink(USER_SITE, $id, '/', 'test', TRUE);

        if (preg_match('/.*k=(.*)\&/', $l, $matches)) {
            $key = $matches[1];

            # Test with wrong key.
            $ret = $this->call('session', 'POST', [
                'u' => $id,
                'k' => '1'
            ]);

            assertEquals(1, $ret['ret']);

            $ret = $this->call('session', 'POST', [
                'u' => $id,
                'k' => $key
            ]);

            assertEquals(0, $ret['ret']);
            assertEquals($id, $ret['user']['id']);

        } else {
            assertFalse(TRUE);
        }
    }

    public function testPatch()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertNotNull($u->addEmail('test@test.com'));
        $u = User::get($this->dbhm, $this->dbhm, $id);

        $ret = $this->call('session', 'PATCH', [
            'firstname' => 'Test2',
            'lastname' => 'User2'
        ]);
        assertEquals(1, $ret['ret']);

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'PATCH', [
            'firstname' => 'Test2',
            'lastname' => 'User2'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals('Test2', $ret['me']['firstname']);
        assertEquals('User2', $ret['me']['lastname']);

        # Set to an email already in use
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertNotNull($u->addEmail('test3@test.com'));
        $ret = $this->call('session', 'PATCH', [
            'settings' => json_encode(['test' => 1]),
            'email' => 'test3@test.com'
        ]);
        assertEquals(10, $ret['ret']);

        # Change password and check it works.
        $u = User::get($this->dbhm, $this->dbhm, $id);
        $u->addLogin(User::LOGIN_NATIVE, $u->getId(), 'testpw');
        $ret = $this->call('session', 'POST', [
            'email' => 'test3@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'PATCH', [
            'password' => 'testpw2'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'POST', [
            'email', 'test3@test.com',
            'password' => 'testpw2'
        ]);

        $u->delete();

        }

    public function testWork()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created user $id");
        assertNotNull($u->addEmail('test@test.com'));
        $u = User::get($this->dbhm, $this->dbhm, $id);

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $g1 = Group::get($this->dbhr, $this->dbhm, $group1);
        $g2 = Group::get($this->dbhr, $this->dbhm, $group2);
        $u->addMembership($group1, User::ROLE_MODERATOR);
        $u->addMembership($group2, User::ROLE_MODERATOR);

        # Send one message to pending on each.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup2', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals($group1, $ret['groups'][0]['id']);
        assertEquals($group2, $ret['groups'][1]['id']);
        assertEquals(1, $ret['groups'][0]['work']['pending']);
        assertEquals(1, $ret['groups'][1]['work']['pending']);
        assertEquals(0, $ret['groups'][0]['work']['spam']);
        assertEquals(0, $ret['groups'][1]['work']['spam']);
        assertEquals(2, $ret['work']['pending']);
        assertEquals(0, $ret['work']['spam']);

        # Get again, just for work.
        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        assertFalse(array_key_exists('configs', $ret));
        assertEquals(0, $ret['ret']);
        assertEquals($group1, $ret['groups'][0]['id']);
        assertEquals($group2, $ret['groups'][1]['id']);
        assertEquals(1, $ret['groups'][0]['work']['pending']);
        assertEquals(1, $ret['groups'][1]['work']['pending']);
        assertEquals(0, $ret['groups'][0]['work']['spam']);
        assertEquals(0, $ret['groups'][1]['work']['spam']);
        assertEquals(2, $ret['work']['pending']);
        assertEquals(0, $ret['work']['spam']);

        $g1->delete();
        $g2->delete();

        }

    public function testPartner()
    {
        $key = randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`) VALUES ('UT', ?);", [$key]);
        assertNotNull($id);
        assertFalse(partner($this->dbhr, 'wibble'));
        assertTrue(partner($this->dbhr, $key));

        $this->dbhm->preExec("DELETE FROM partners_keys WHERE partner = 'UT';");

        }

    public function testPushCreds()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created user $id");

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        assertTrue($n->add($id, PushNotifications::PUSH_TEST, 'test'));

        $ret = $this->call('session', 'GET', []);
        assertEquals(1, $ret['ret']);

        # Normally this would be in a separate API call, so we need to override here.
        global $sessionPrepared;
        $sessionPrepared = FALSE;

        # Now log in using our creds
        $ret = $this->call('session', 'GET', [
            'pushcreds' => 'test'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['me']['id']);

        assertEquals(1, $n->remove($id));

        }

    public function testLostPassword()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $ret = $this->call('session', 'POST', [
            'email' => $email,
            'action' => 'LostPassword'
        ]);
        assertEquals(2, $ret['ret']);

        $u->addEmail($email);

        $ret = $this->call('session', 'POST', [
            'email' => $email,
            'action' => 'LostPassword'
        ]);
        assertEquals(0, $ret['ret']);

        }

    public function testForget()
    {
        # Try logged out - should fail.
        $ret = $this->call('session', 'POST', [
            'action' => 'Forget'
        ]);
        assertEquals(1, $ret['ret']);

        $u = $this->getMockBuilder('User')
            ->setConstructorArgs([$this->dbhm, $this->dbhm])
            ->setMethods(array('sendIt'))
            ->getMock();
        $u->method('sendIt')->will($this->returnCallback(function ($mailer, $message) {
            return ($this->sendMock($mailer, $message));
        }));

        $id = $u->create('Test', 'User', NULL);
        assertNotNull($u->addEmail('test@test.com'));
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $u->setPrivate('yahooid', -1);
        $u->setPrivate('yahooUserId', -2);

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);

        # Now forget ourselves - should fail as a mod.
        $this->log("Forget myself - should fail as mod");
        $ret = $this->call('session', 'POST', [
            'action' => 'Forget'
        ]);
        $this->log("Returned " . var_export($ret,TRUE));
        assertEquals(2, $ret['ret']);

        $u->setPrivate('systemrole', User::SYSTEMROLE_USER);
        $this->log("Forget myself - should work");
        $ret = $this->call('session', 'POST', [
            'action' => 'Forget'
        ]);
        assertEquals(0, $ret['ret']);

        # Should be logged out.
        $ret = $this->call('session', 'GET', []);
        assertEquals(1, $ret['ret']);

        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertEquals(strpos($u->getName(), 'Deleted User'), 0);
        assertNull($u->getPrivate('yahooid'));
        assertNull($u->getPrivate('yahooUserId'));
    }

    public function testAboutMe()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertNotNull($u->addEmail('test@test.com'));

        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->log("Got info" . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'PATCH', [
            'aboutme' => "Something about me"
        ]);
        self::assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $id,
            'info' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        self::assertEquals('Something about me', $ret['user']['info']['aboutme']['text']);
    }

    public function testAppVersion()
    {
        $ret = $this->call('session', 'GET', [
            'appversion' => 2
        ]);
        assertEquals(123, $ret['ret']);
        $ret = $this->call('session', 'GET', [
            'appversion' => 3
        ]);
        assertEquals(1, $ret['ret']);
    }

    public function testRelated() {
        $u1 = User::get($this->dbhm, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        assertNotNull($u1->addEmail('test1@test.com'));
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u1->login('testpw'));

        $u2 = User::get($this->dbhm, $this->dbhm);
        $id2 = $u1->create('Test', 'User', NULL);

        $ret = $this->call('session', 'POST', [
            'action' => 'Related',
            'userlist' => [ $id1, $id2 ]
        ]);

        $this->waitBackground();

        $related = $u1->getRelated($id1);
        assertEquals($id2, $related[0]['user2']);
        $related = $u2->getRelated($id2);
        assertEquals($id1, $related[0]['user2']);
    }
//
//    public function testSheila() {
//        $_SESSION['id'] = 25880780;
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//        $ret = $this->call('session', 'GET', [
//            'components' => [
//                'work'
//            ]
//        ]);
//        $this->log("Duration {$ret['duration']} DB {$ret['dbwaittime']}");
//        $this->dbhr->errorLog = FALSE;
//        $this->dbhm->errorLog = FALSE;
//    }
}
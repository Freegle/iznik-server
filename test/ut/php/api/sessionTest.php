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
class sessionTest extends IznikAPITestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->msgsSent = [];

        $this->dbhm->preExec("DELETE FROM users_push_notifications WHERE `type` = 'Test';");
    }

    protected function tearDown() : void
    {
    }

    public function sendMock($mailer, $message)
    {
        $this->msgsSent[] = $message->toString();
    }

    public function testLoggedOut()
    {
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(1, $ret['ret']);

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

        $this->assertEquals(1, $ret['ret']);

        }

    public function testYahoo()
    {
        # Logged out should cause redirect
        $ret = $this->call('session', 'POST', [
            'yahoologin' => 1
        ]);
        $this->assertEquals(1, $ret['ret']);
        $this->assertTrue(array_key_exists('redirect', $ret));

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
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);

        $mock->method('getAttributes')->willThrowException(new \Exception());
        $ret = $this->call('session', 'POST', [
            'yahoologin' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Logout
        $ret = $this->call('session', 'DELETE', []);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'DELETE', []);
        $this->assertEquals(0, $ret['ret']);#

        # Should be logged out
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(1, $ret['ret']);

        }

    public function testFacebook()
    {
        # With no token should fail.
        $ret = $this->call('session', 'POST', [
            'fblogin' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Rest of testing done in include test.

        }

    public function testGoogle()
    {
        # With no token should fail.
        $ret = $this->call('session', 'POST', [
            'googlelogin' => 1
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Rest of testing done in include test.

        }

    public function testApple()
    {
        # With no token should fail.
        $ret = $this->call('session', 'POST', [
            'applelogin' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Rest of testing done in include test.

    }

    public function testNative()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertNotNull($u->addEmail('test@test.com'));

        # Mock the user ("your hair looks terrible") to check the welcome mail is sent.
        $u = $this->getMockBuilder('Freegle\Iznik\User')
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
        $g->setPrivate('onhere', TRUE);
        $this->log("Add first time");
        $u->addMembership($group1);

        self::assertEquals(1, count($this->msgsSent));

        # Add membership again and check the welcome is not sent.
        $this->log("Add second time");
        $u->addMembership($group1);
        self::assertEquals(1, count($this->msgsSent));

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->log("Session get");
        $ret = $this->call('session', 'GET', []);
        $this->log("Session got");
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($group1, $ret['groups'][0]['id']);
        $this->assertEquals('test@test.com', $ret['emails'][0]['email']);
        $this->assertNotNull($ret['jwt']);

        # Set something
        $ret = $this->call('session', 'PATCH', [
            'settings' => ['test' => 1],
            'displayname' => "Testing User",
            'email' => 'test2@test.com',
            'onholidaytill' => Utils::ISODate('@' . time()),
            'relevantallowed' => 0,
            'newslettersallowed' => 0,
            'notifications' => [
                'push' => [
                    'type' => 'Google',
                    'subscription' => 'Test'
                ]
            ],
            'engagement' => TRUE
        ]);
        $this->assertEquals(10, $ret['ret']);
        $ret = $this->call('session', 'GET', []);
        $this->log(var_export($ret, true));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals([
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
            ],
                         'engagement' => TRUE
        ], $ret['me']['settings']);
        $this->assertEquals('Testing User', $ret['me']['displayname']);
        $this->assertEquals('test@test.com', $ret['me']['email']);
        $this->assertFalse(array_key_exists('relevantallowed', $ret['me']));
        $this->assertFalse(array_key_exists('newslettersallowed', $ret['me']));

        # Confirm it
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'test2@test.com';");
        $this->assertEquals(1, count($emails));
        foreach ($emails as $email) {
            $ret = $this->call('session', 'PATCH', [
                'key' => 'wibble'
            ]);
            $this->assertEquals(11, $ret['ret']);

            $ret = $this->call('session', 'PATCH', [
                'key' => $email['validatekey']
            ]);
            $this->assertEquals(0, $ret['ret']);
        }

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->log("Confirmed " . var_export($ret, TRUE));
        $this->assertEquals('test2@test.com', $ret['me']['email']);

        $ret = $this->call('session', 'PATCH', [
            'settings' => ['test' => 1],
            'displayname' => "Testing User",
            'email' => 'test2@test.com',
            'notifications' => [
                'push' => [
                    'type' => 'Firefox',
                    'subscription' => 'Test'
                ]
            ],
            'engagement' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Quick test for notification coverage.
        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('curl_exec'))
            ->getMock();
        $mock->method('curl_exec')->willReturn('NotRegistered');
        $mock->notify($id, TRUE);

        $mock = $this->getMockBuilder('Freegle\Iznik\PushNotifications')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('uthook'))
            ->getMock();
        $mock->method('uthook')->willThrowException(new \Exception());
        $mock->notify($id, TRUE);

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

            $this->assertEquals(1, $ret['ret']);

            $ret = $this->call('session', 'POST', [
                'u' => $id,
                'k' => $key
            ]);

            $this->assertEquals(0, $ret['ret']);
            $this->assertEquals($id, $ret['user']['id']);

        } else {
            $this->assertFalse(TRUE);
        }
    }

    public function testPatch()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertNotNull($u->addEmail('test@test.com'));
        $u = User::get($this->dbhm, $this->dbhm, $id);

        $ret = $this->call('session', 'PATCH', [
            'firstname' => 'Test2',
            'lastname' => 'User2'
        ]);
        $this->assertEquals(1, $ret['ret']);

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'PATCH', [
            'firstname' => 'Test2',
            'lastname' => 'User2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('Test2', $ret['me']['firstname']);
        $this->assertEquals('User2', $ret['me']['lastname']);

        # Set to an email already in use
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertNotNull($u->addEmail('test3@test.com'));
        $ret = $this->call('session', 'PATCH', [
            'settings' => json_encode(['test' => 1]),
            'email' => 'test3@test.com'
        ]);
        $this->assertEquals(10, $ret['ret']);

        # Change password and check it works.
        $u = User::get($this->dbhm, $this->dbhm, $id);
        $u->addLogin(User::LOGIN_NATIVE, $u->getId(), 'testpw');
        $ret = $this->call('session', 'POST', [
            'email' => 'test3@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'PATCH', [
            'password' => 'testpw2'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'POST', [
            'email', 'test3@test.com',
            'password' => 'testpw2'
        ]);

        $u->delete();

    }

    public function testConfigs() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('configs', $ret));

        $ret = $this->call('session', 'GET', [
            'components' => [
                'configs'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('configs', $ret));
    }

    public function testWork()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created user $id");
        $this->assertNotNull($u->addEmail('test@test.com'));
        $u = User::get($this->dbhm, $this->dbhm, $id);
        $u->setPrivate('permissions', json_encode([ User::PERM_NATIONAL_VOLUNTEERS, User::PERM_GIFTAID ]));
        $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

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
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup2', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        list ($id, $failok) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($group1, $ret['groups'][0]['id']);
        $this->assertEquals($group2, $ret['groups'][1]['id']);
        $this->assertEquals(1, $ret['groups'][0]['work']['pending']);
        $this->assertEquals(1, $ret['groups'][1]['work']['pending']);
        $this->assertEquals(0, $ret['groups'][0]['work']['spam']);
        $this->assertEquals(0, $ret['groups'][1]['work']['spam']);
        $this->assertEquals(2, $ret['work']['pending']);
        $this->assertEquals(0, $ret['work']['spam']);
        $this->assertEquals(0, $ret['work']['spammerpendingadd']);
        $this->assertEquals(0, $ret['work']['spammerpendingremove']);

        # Get again, just for work.
        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        $this->assertFalse(array_key_exists('configs', $ret));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($group1, $ret['groups'][0]['id']);
        $this->assertEquals($group2, $ret['groups'][1]['id']);
        $this->assertEquals(1, $ret['groups'][0]['work']['pending']);
        $this->assertEquals(1, $ret['groups'][1]['work']['pending']);
        $this->assertEquals(0, $ret['groups'][0]['work']['spam']);
        $this->assertEquals(0, $ret['groups'][1]['work']['spam']);
        $this->assertEquals(2, $ret['work']['pending']);
        $this->assertEquals(0, $ret['work']['spam']);

        $g1->delete();
        $g2->delete();
    }

    public function testPartner()
    {
        global $sessionPrepared;
        $sessionPrepared = FALSE;

        $key = Utils::randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`) VALUES ('UT', ?);", [$key]);
        $this->assertNotNull($id);
        list ($partner, $domain) = Session::partner($this->dbhr, 'wibble');
        $this->assertFalse($partner);
        list ($partner, $domain) = Session::partner($this->dbhr, $key);
        $this->assertEquals('UT', $partner['partner']);

        $this->dbhm->preExec("DELETE FROM partners_keys WHERE partner = 'UT';");
    }

    public function testPushCreds()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created user $id");

        $n = new PushNotifications($this->dbhr, $this->dbhm);
        $this->assertTrue($n->add($id, PushNotifications::PUSH_TEST, 'test'));

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(1, $ret['ret']);

        # Normally this would be in a separate API call, so we need to override here.
        global $sessionPrepared;
        $sessionPrepared = FALSE;

        # Now log in using our creds
        $ret = $this->call('session', 'GET', [
            'pushcreds' => 'test'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['me']['id']);

        $this->assertEquals(1, $n->remove($id));

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
        $this->assertEquals(2, $ret['ret']);

        $u->addEmail($email);

        $ret = $this->call('session', 'POST', [
            'email' => $email,
            'action' => 'LostPassword'
        ]);
        $this->assertEquals(0, $ret['ret']);

        }

    public function testConfirmUnsubscribe()
    {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');

        $ret = $this->call('session', 'POST', [
            'action' => 'Unsubscribe'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('session', 'POST', [
            'action' => 'Unsubscribe',
            'email' => 'zzzz'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $ret = $this->call('session', 'POST', [
            'action' => 'Unsubscribe',
            'email' => 'test@test.com'
        ]);
        $this->assertEquals(0, $ret['ret']);
    }

    public function testForget()
    {
        # Try logged out - should fail.
        $ret = $this->call('session', 'POST', [
            'action' => 'Forget'
        ]);
        $this->assertEquals(1, $ret['ret']);

        $u = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([$this->dbhm, $this->dbhm])
            ->setMethods(array('sendIt'))
            ->getMock();
        $u->method('sendIt')->will($this->returnCallback(function ($mailer, $message) {
            return ($this->sendMock($mailer, $message));
        }));

        $id = $u->create('Test', 'User', NULL);
        $this->assertNotNull($u->addEmail('test@test.com'));
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $u->setPrivate('yahooid', -1);

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);

        # Now forget ourselves - should fail as a mod.
        $this->log("Forget myself - should fail as mod");
        $ret = $this->call('session', 'POST', [
            'action' => 'Forget'
        ]);
        $this->log("Returned " . var_export($ret,TRUE));
        $this->assertEquals(2, $ret['ret']);

        $u->setPrivate('systemrole', User::SYSTEMROLE_USER);
        $this->log("Forget myself - should work");
        $ret = $this->call('session', 'POST', [
            'action' => 'Forget'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Should be logged out.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(1, $ret['ret']);

        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertEquals(strpos($u->getName(), 'Deleted User'), 0);
        $this->assertNull($u->getPrivate('yahooid'));
    }

    public function testAboutMe()
    {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertNotNull($u->addEmail('test@test.com'));

        # Set a location otherwise we won't add to the newsfeed.
        $u->setSetting('mylocation', [
            'lng' => 179.15,
            'lat' => 8.5
        ]);

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->log("Got info" . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('session', 'PATCH', [
            'aboutme' => "Something long and interesting about me"
        ]);
        self::assertEquals(0, $ret['ret']);

        $ret = $this->call('user', 'GET', [
            'id' => $id,
            'info' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals('Something long and interesting about me', $ret['user']['info']['aboutme']['text']);

        # Check if the newsfeed entry was added
        $ret = $this->call('newsfeed', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $newsfeedid = NULL;
        foreach ($ret['newsfeed'] as $n) {
            error_log(var_export($n, TRUE));
            if (Utils::presdef('userid', $n, 0) == $id && $n['type'] == Newsfeed::TYPE_ABOUT_ME && strcmp($n['message'], 'Something long and interesting about me') === 0) {
                $found = TRUE;
                $newsfeedid = $n['id'];
            }
        }
        $this->assertTrue($found);

        # Again for coverage.
        $ret = $this->call('session', 'PATCH', [
            'aboutme' => "Something else long and interesting about me"
        ]);
        self::assertEquals(0, $ret['ret']);
        $ret = $this->call('user', 'GET', [
            'id' => $id,
            'info' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        self::assertEquals('Something else long and interesting about me', $ret['user']['info']['aboutme']['text']);

        # Check if the newsfeed entry was updated, as recent.
        $ret = $this->call('newsfeed', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $newsfeedid = NULL;
        foreach ($ret['newsfeed'] as $n) {
            error_log(var_export($n, TRUE));
            if ($n['id'] == $newsfeedid && Utils::presdef('userid', $n, 0) == $id && $n['type'] == Newsfeed::TYPE_ABOUT_ME && strcmp($n['message'], 'Something else long and interesting about me') === 0) {
                $found = TRUE;
            }
        }
        $this->assertTrue($found);
    }

    public function testAppVersion()
    {
        $ret = $this->call('session', 'GET', [
            'appversion' => 2
        ]);
        $this->assertEquals(123, $ret['ret']);
        $ret = $this->call('session', 'GET', [
            'appversion' => 3
        ]);
        $this->assertEquals(1, $ret['ret']);
    }

    public function testRelated() {
        $u1 = User::get($this->dbhm, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $this->assertNotNull($u1->addEmail('test1@test.com'));
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u1->login('testpw'));

        $u2 = User::get($this->dbhm, $this->dbhm);
        $id2 = $u1->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $ret = $this->call('session', 'POST', [
            'action' => 'Related',
            'userlist' => [ $id1, $id2 ]
        ]);

        $this->waitBackground();

        $related = $u1->getRelated($id1);
        $this->assertEquals($id2, $related[0]['user2']);
        $related = $u2->getRelated($id2);
        $this->assertEquals($id1, $related[0]['user2']);

        $u3 = User::get($this->dbhm, $this->dbhm);
        $id3 = $u3->create('Test', 'User', NULL);
        $u3->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $this->assertGreaterThan(0, $u3->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u3->login('testpw'));

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::RELATED,
            'limit' => PHP_INT_MAX
        ]);

        $found = FALSE;

        foreach (Utils::presdef('members', $ret, []) as $member) {
            if ($member['id'] == $id1) {
                $found = TRUE;
                $this->assertEquals($id2, $member['relatedto']['id']);
            }
        }

        $this->assertTrue($found);

        # Again by group id for coverage.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $u1 = User::get($this->dbhm, $this->dbhm, $id1);
        $u2 = User::get($this->dbhm, $this->dbhm, $id2);
        $u3 = User::get($this->dbhm, $this->dbhm, $id3);
        $u1->addMembership($gid);
        $u2->addMembership($gid);
        $u3->addMembership($gid, User::ROLE_MODERATOR);

        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::RELATED,
            'limit' => PHP_INT_MAX
        ]);

        $found = FALSE;

        foreach (Utils::presdef('members', $ret, []) as $member) {
            if ($member['id'] == $id1) {
                $found = TRUE;
                $this->assertEquals($id2, $member['relatedto']['id']);
            }
        }

        $this->assertTrue($found);

        # Mods etc shouldn't show.
        $u1->setPrivate('systemrole', User::ROLE_MODERATOR);
        $ret = $this->call('memberships', 'GET', [
            'collection' => MembershipCollection::RELATED,
            'limit' => PHP_INT_MAX
        ]);

        $this->assertEquals(0, count($ret['members']));
    }

    public function testRelatedWork() {
        // Create two related members on a group.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);

        $u1 = User::get($this->dbhm, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $u1->addMembership($gid);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u1->login('testpw'));

        $u2 = User::get($this->dbhm, $this->dbhm);
        $id2 = $u1->create('Test', 'User', NULL);
        $u1->addMembership($gid);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $ret = $this->call('session', 'POST', [
            'action' => 'Related',
            'userlist' => [ $id1, $id2 ]
        ]);

        $this->waitBackground();

        // Create a mod.
        $u3 = User::get($this->dbhm, $this->dbhm);
        $id3 = $u3->create('Test', 'User', NULL);
        $u3->addMembership($gid, User::ROLE_MODERATOR);
        $this->assertGreaterThan(0, $u3->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u3->login('testpw'));

        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, $ret['work']['relatedmembers']);

        // Mods shouldn't count.
        $u1->setPrivate('systemrole', User::ROLE_MODERATOR);

        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['relatedmembers']);

        // Forget the user - shouldn't show in work.
        $u1->forget('UT');

        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(0, $ret['work']['relatedmembers']);
    }

    public function testTwitter() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        if (!$gid) {
            $gid = $g->create('FreeglePlayground', Group::GROUP_REUSE);
            $t = new Twitter($this->dbhr, $this->dbhm, $gid);
            $t->set('FreeglePlaygrnd', getenv('PLAYGROUND_TOKEN'), getenv('PLAYGROUND_SECRET'));
        }

        $gid = $g->create('testgroup', Group::GROUP_UT);
        $t = new Twitter($this->dbhr, $this->dbhm, $gid);
        $t->set('test', 'test', 'test');
        $atts = $t->getPublic();
        $this->assertEquals('test', $atts['name']);
        $this->assertEquals('test', $atts['token']);
        $this->assertEquals('test', $atts['secret']);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $_SESSION['id'] = $uid;
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals('test', $ret['groups'][0]['twitter']['name']);
    }

    public function testFacebookPage() {
        $g = new Group($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        $f = new GroupFacebook($this->dbhr, $this->dbhm, $gid);
        $f->add($gid, '123', 'test', 123, GroupFacebook::TYPE_PAGE);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $_SESSION['id'] = $uid;
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals('test', $ret['groups'][0]['facebook'][0]['name']);
    }

    public function testPhone() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $_SESSION['id'] = $id;

        $ret = $this->call('session', 'PATCH', [
            'phone' => 123
        ]);

        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(44123, $ret['me']['phone']);

        $ret = $this->call('session', 'PATCH', [
            'phone' => NULL
        ]);
        $this->assertEquals(0, $ret['ret']);
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('phone', $ret['me']));
    }

    public function testVersion() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addLogin(User::LOGIN_NATIVE, $u->getId(), 'testpw');
        $u->login('testpw');

        $ret = $this->call('session', 'GET', [
            'modtools' => FALSE,
            'webversion' => 1,
            'appversion' => 2
        ]);
        $this->assertEquals(123, $ret['ret']);

        $ret = $this->call('session', 'GET', [
            'modtools' => FALSE,
            'webversion' => 1,
            'appversion' => 3
        ]);
        $this->assertEquals(0, $ret['ret']);

        $this->waitBackground();

        $versions = $this->dbhr->preQuery("SELECT * FROM users_builddates WHERE userid = ?;", [
            $id
        ]);
        $this->assertEquals(1, $versions[0]['webversion']);
        $this->assertEquals(3, $versions[0]['appversion']);
    }

    public function testDiscourseCookie() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('session', 'GET', []);
        $persistent = $ret['persistent'];
        $_SESSION['id'] = NULL;
        global $sessionPrepared;
        $sessionPrepared = FALSE;
        $ret = $this->call('session', 'GET', [
            'persistent' => $persistent
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('me', $ret));
    }

    public function testPhpSessionHeader() {
        $ret = $this->call('session', 'GET', []);
        $session = $ret['session'];
        $ret = $this->call('session', 'GET', []);
        @session_destroy();
        $GLOBALS['sessionPrepared'] = FALSE;
        $_SERVER['HTTP_X_IZNIK_PHP_SESSION'] = $session;
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals($session, $ret['session']);
        $_SERVER['HTTP_X_IZNIK_PHP_SESSION'] = NULL;
    }
}
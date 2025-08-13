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
        $mock->method('validate')->willReturn(TRUE);
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
        # Create a user so that the confirm will trigger a merge.
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test2@test.com', 'testpw');

        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');

        # Mock the group ("your hair looks terrible") to check the welcome mail is sent.
        $g = $this->getMockBuilder('Freegle\Iznik\Group')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, $id])
            ->setMethods(array('sendIt'))
            ->getMock();
        $g->method('sendIt')->will($this->returnCallback(function ($mailer, $message) {
            return ($this->sendMock($mailer, $message));
        }));

        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setPrivate('welcomemail', 'Test - please ignore');
        $g->setPrivate('onhere', TRUE);
        $this->log("Add first time");
        $u->addMembership($group1, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED, NULL, NULL, TRUE, $g);
        $u->processMemberships($g);

        self::assertEquals(1, count($this->msgsSent));

        # Add membership again and check the welcome is not sent.
        $this->log("Add second time");
        $u->addMembership($group1);
        self::assertEquals(1, count($this->msgsSent));

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
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals([
            "test" => 1,
            'notificationmails' => TRUE,
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
            error_log("SEssion no4 {$_SESSION['id']}" . var_export($ret, TRUE));
            $this->assertEquals($id, $_SESSION['id']);
            $this->assertEquals($id, $ret['persistent']['userid']);
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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');

        $ret = $this->call('session', 'PATCH', [
            'firstname' => 'Test2',
            'lastname' => 'User2'
        ]);
        $this->assertEquals(1, $ret['ret']);

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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test3@test.com', 'testpw');
        $ret = $this->call('session', 'PATCH', [
            'settings' => json_encode(['test' => 1]),
            'email' => 'test3@test.com'
        ]);
        $this->assertEquals(10, $ret['ret']);

        # Change password and check it works.
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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
        $this->log("Created user $id");
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

        $ret = $this->call('session', 'POST', [
            'email' => 'test@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $_SESSION['supportAllowed'] = TRUE;

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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
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

        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'temp@test.com', 'testpw');

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
        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');

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
        self::assertTrue($u->sendOurMails());
        self::assertTrue($u->notifsOn(User::NOTIFS_PUSH));
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

        # Should still have name in DB.
        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertEquals(strpos($u->getName(), 'Test User'), 0);
        $this->assertNotNull($u->getPrivate('yahooid'));
        self::assertFalse($u->sendOurMails());
        self::assertFalse($u->notifsOn(User::NOTIFS_PUSH));

        # Not due a forget yet.
        self::assertEquals(0, $u->processForgets($id));

        # Make it due a forget.
        $u->setPrivate('deleted', '2001-01-01');
        self::assertEquals(1, $u->processForgets($id));

        $u = new User($this->dbhr, $this->dbhm, $id);
        self::assertEquals(strpos($u->getName(), 'Deleted User'), 0);
        $this->assertNull($u->getPrivate('yahooid'));
        self::assertFalse($u->sendOurMails());
        self::assertFalse($u->notifsOn(User::NOTIFS_PUSH));
    }

    public function testAboutMe()
    {
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');

        # Set a location otherwise we won't add to the newsfeed.
        $u->setSetting('mylocation', [
            'lng' => 179.15,
            'lat' => 8.5
        ]);

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
        list($u1, $id1, $emailid1) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test1@test.com', 'testpw');

        list($u2, $id2, $emailid2) = $this->createTestUser('Test', 'User', NULL, 'test2@test.com', 'testpw');
        $this->assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Need to ensure that there is a log from the IP that we're about to check.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->dbhm->preExec("INSERT INTO logs_api (`userid`, `ip`, `session`, `request`, `response`) VALUES (?, ?, '123', '', 'Success');", [
            $id1,
            '127.0.0.1'
        ]);

        $ret = $this->call('session', 'POST', [
            'action' => 'Related',
            'userlist' => [ $id1, $id2 ]
        ]);

        $this->waitBackground();

        $related = $u1->getRelated($id1);
        $this->assertEquals($id2, $related[0]['user2']);
        $related = $u2->getRelated($id2);
        $this->assertEquals($id1, $related[0]['user2']);

        list($u3, $id3, $emailid3) = $this->createTestUserAndLogin('Test', 'User', NULL, 'test3@test.com', 'testpw');
        $u3->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

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
        list($g, $gid) = $this->createTestGroup('testgroup1', Group::GROUP_REUSE);

        list($u1, $id1, $emailid1) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MEMBER, 'Test', 'User', NULL, 'test1@test.com', 'testpw');

        list($u2, $id2, $emailid2) = $this->createTestUserWithMembership($gid, User::ROLE_MEMBER, 'Test', 'User', NULL, 'test2@test.com', 'testpw');
        $this->assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Need to ensure that there is a log from the IP that we're about to check.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->dbhm->preExec("INSERT INTO logs_api (`userid`, `ip`, `session`, `request`, `response`) VALUES (?, ?, '123', '', 'Success');", [
            $id1,
            '127.0.0.1'
        ]);

        $ret = $this->call('session', 'POST', [
            'action' => 'Related',
            'userlist' => [ $id1, $id2 ]
        ]);

        $this->waitBackground();

        // Create a mod.
        list($u3, $id3, $emailid3) = $this->createTestUserWithMembershipAndLogin($gid, User::ROLE_MODERATOR, 'Test', 'User', NULL, 'test3@test.com', 'testpw');

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

    public function testFacebookPage() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_UT);

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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
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
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');
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

    public function testSimpleEmail() {
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test@test.com', 'testpw');

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $u->addMembership($group1);

        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('session', 'PATCH', [
            'simplemail' => User::SIMPLE_MAIL_FULL,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $u = new User($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(-1, $u->getMembershipAtt($group1, 'emailfrequency'));
        $this->assertEquals(1, $u->getMembershipAtt($group1, 'volunteeringallowed'));
        $this->assertEquals(1, $u->getMembershipAtt($group1, 'eventsallowed'));
        $this->assertEquals(User::SIMPLE_MAIL_FULL, $u->getSetting('simplemail', NULL));

        $ret = $this->call('session', 'PATCH', [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $u = new User($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(24, $u->getMembershipAtt($group1, 'emailfrequency'));
        $this->assertEquals(NULL, $u->getMembershipAtt($group1, 'volunteeringallowed'));
        $this->assertEquals(NULL, $u->getMembershipAtt($group1, 'eventsallowed'));
        $this->assertEquals(User::SIMPLE_MAIL_BASIC, $u->getSetting('simplemail', NULL));

        $ret = $this->call('session', 'PATCH', [
            'simplemail' => User::SIMPLE_MAIL_NONE,
        ]);
        $this->assertEquals(0, $ret['ret']);

        $u = new User($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, $u->getMembershipAtt($group1, 'emailfrequency'));
        $this->assertEquals(NULL, $u->getMembershipAtt($group1, 'volunteeringallowed'));
        $this->assertEquals(NULL, $u->getMembershipAtt($group1, 'eventsallowed'));
        $this->assertEquals(User::SIMPLE_MAIL_NONE, $u->getSetting('simplemail', NULL));

        # Joining an additional group should default to none.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $u->addMembership($group2);

        $u = new User($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(0, $u->getMembershipAtt($group2, 'emailfrequency'));
        $this->assertEquals(NULL, $u->getMembershipAtt($group2, 'volunteeringallowed'));
        $this->assertEquals(NULL, $u->getMembershipAtt($group2, 'eventsallowed'));
    }

    public function testConfirmTwice() {
        # Setting the email twice in quick successsion should
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test123@test.com', 'testpw');
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('session', 'PATCH', [
            'email' => 'test456@test.com',
        ]);

        $this->assertEquals(10, $ret['ret']);

        # Find the key
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'test456@test.com';");
        $this->assertEquals(1, count($emails));
        $key1 = $emails[0]['validatekey'];

        $ret = $this->call('session', 'PATCH', [
            'email' => 'test456@test.com',
            'dup' => TRUE
        ]);

        $this->assertEquals(10, $ret['ret']);
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'test456@test.com';");
        $this->assertEquals(1, count($emails));
        $key2 = $emails[0]['validatekey'];

        $this->assertEquals($key1, $key2);
    }

    public function testPECR() {
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test123@test.com', 'testpw');
        $this->assertTrue($u->login('testpw'));

        $ret = $this->call('session', 'PATCH', [
            'marketingconsent' => TRUE
        ]);

        $u = new User($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(1, $u->getPrivate('marketingconsent', 0));
    }

    public function testSpammerLogin() {
        list($u, $id, $emailid) = $this->createTestUser('Test', 'User', NULL, 'test123@test.com', 'testpw');

        # Add to the spammer list.
        $s = new Spam($this->dbhr, $this->dbhm);
        $this->assertNotNull($s->addSpammer($id, Spam::TYPE_SPAMMER, 'UT'));

        # Should be able to appear to log in.
        $ret = $this->call('session', 'POST', [
            'email' => 'test123@test.com',
            'password' => 'testpw'
        ]);
        $this->assertEquals(0, $ret['ret']);

        # But hasn't in fact got a session.
        $ret = $this->call('session', 'GET', []);
        $this->assertEquals(1, $ret['ret']);
    }

    // TODO Disabled for now.
//    public function testSupportSecureLogin() {
//        # Create a user with support tools access.
//        $u = User::get($this->dbhm, $this->dbhm);
//        $id = $u->create('Test', 'User', NULL);
//        $this->assertNotNull($u->addEmail('test123@test.com'));
//        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
//        $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
//
//        # Login native.
//        $ret = $this->call('session', 'POST', [
//            'email' => 'test123@test.com',
//            'password' => 'testpw'
//        ]);
//        $this->assertEquals(0, $ret['ret']);
//
//        $ret = $this->call('session', 'GET', []);
//        $this->assertEquals(0, $ret['ret']);
//        $this->assertEquals(User::SYSTEMROLE_MODERATOR, $ret['me']['systemrole']);
//        $this->assertTrue($ret['me']['supportdisabled']);
//
//        $_SESSION['supportAllowed'] = TRUE;
//        $ret = $this->call('session', 'GET', []);
//        $this->assertEquals(0, $ret['ret']);
//        $this->assertEquals(User::SYSTEMROLE_SUPPORT, $ret['me']['systemrole']);
//    }
}
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
class userTest extends IznikTestCase {
    private $dbhr, $dbhm, $msgsSent;

    public function sendMock($mailer, $message) {
        $this->msgsSent[] = $message->toString();
    }

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->msgsSent = [];

        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
        $dbhm->preExec("DELETE users, users_logins FROM users INNER JOIN users_logins ON users.id = users_logins.userid WHERE uid IN ('testid', '1234');");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE firstname = 'Test' AND lastname = 'User';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort LIKE 'testgroup%';");
        $dbhm->preExec("DELETE FROM users_emails WHERE email = 'bit-bucket@test.smtp.org'");
        $dbhm->preExec("DELETE FROM users_emails WHERE email = 'test@test.com'");
    }

    public function testBasic() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->log("Created $id");

        $this->log("Get - not cached");
        $u = User::get($this->dbhr, $this->dbhm, $id);

        $this->log("Get - cached");
        $u = User::get($this->dbhr, $this->dbhm, $id);

        $this->log("Get - deleted");
        User::clearCache($id);
        $u = User::get($this->dbhr, $this->dbhm, $id);

        $atts = $u->getPublic();
        $this->assertEquals('Test', $atts['firstname']);
        $this->assertEquals('User', $atts['lastname']);
        $this->assertNull($atts['fullname']);
        $this->assertEquals('Test User', $u->getName());
        $this->assertEquals($id, $u->getPrivate('id'));
        $this->assertNull($u->getPrivate('invalidid'));

        $u->setPrivate('yahooid', 'testyahootest');
        $this->assertEquals($id, $u->findByYahooId('testyahootest'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $u->addMembership($group1);
        $_SESSION['id'] = $u->getId();
        $this->assertEquals('testgroup1', $u->getInfo()['publiclocation']['display']);
        $_SESSION['id'] = NULL;
        $this->assertGreaterThan(0, $u->delete());

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $atts = $u->getPublic();
        $this->assertNull($atts['firstname']);
        $this->assertNull($atts['lastname']);
        $this->assertEquals('Test User', $atts['fullname']);
        $this->assertEquals('Test User', $u->getName());
        $this->assertEquals($id, $u->getPrivate('id'));
        $this->assertGreaterThan(0, $u->delete());
    }

    public function testInfos()
    {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', null);
        $this->log("Created $id");

        $this->assertNotNull($u->setAboutMe('UT'));
        $this->assertNotNull($u->getAboutMe());

        $users = [
            $id => [
                'id' => $id
            ]
        ];

        $u->getInfos($users);
        $this->assertEquals(0, $users[$id]['info']['offers']);
    }

    public function testLinkLogin() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);

        $url1 = $u->loginLink(USER_SITE, $id, '/', NULL, TRUE);
        $this->log("Login url $url1");
        $url = $u->loginLink(USER_SITE, $id, '/', NULL, TRUE);
        $this->log("Login url $url1");
        self::assertEquals($url1, $url);
        $p = strpos($url, 'k=');
        $key = substr($url, $p + 2);
        $this->log("Key $key");
        $u->linkLogin($key);
        self::assertEquals($id, $_SESSION['id']);

        # Should not see the login link.
        $atts = $u->getPublic();
        $this->assertFalse(Utils::pres('loginlink', $atts));

        # Shouldn't be able to use link login on deleted user.
        $u->forget("UT");
        $url2 = $u->loginLink(USER_SITE, $id, '/', NULL, TRUE);
        $p = strpos($url2, 'k=');
        $key = substr($url2, $p + 2);
        $this->log("Key $key");
        $_SESSION['id'] = NULL;
        self::assertFalse($u->linkLogin($key));
    }

    public function testEmails() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertEquals(0, count($u->getEmails()));

        # Add an email - should work.
        $this->assertNull($u->findByEmailHash(md5('test@test.com')));
        $eid = $u->addEmail('test@test.com');
        $this->assertGreaterThan(0, $eid);
        $this->assertEquals(0, $u->getEmailAge('test@test.com'));
        $this->assertEquals('test@test.com', $u->getEmailById($eid));
        $this->assertEquals($id, $u->findByEmailHash(md5('test@test.com')));

        # Check it's there
        $emails = $u->getEmails();
        $this->assertEquals(1, count($emails));
        $this->assertEquals('test@test.com', $emails[0]['email']);

        # Add it again - should work
        $this->assertGreaterThan(0, $u->addEmail('test@test.com'));

        # Add a second
        $this->assertGreaterThan(0, $u->addEmail('test2@test.com', 0));
        $emails = $u->getEmails();
        $this->assertEquals(2, count($emails));
        $this->assertEquals(0, $emails[1]['preferred']);
        $this->assertEquals($id, $u->findByEmail('test2@test.com'));
        $this->assertEquals($id, $u->findByEmail("wibble-$id@" . USER_DOMAIN)); // Should parse the UID out of it.
        $this->assertGreaterThan(0, $u->removeEmail('test2@test.com'));
        $this->assertNull($u->findByEmail('test2@test.com'));

        $this->assertEquals($id, $u->findByEmail('test@test.com'));
        $this->assertNull($u->findByEmail('testinvalid@test.com'));

        # Add a new preferred
        $this->assertGreaterThan(0, $u->addEmail('test3@test.com', 1));
        $emails = $u->getEmails();
        $this->assertEquals(2, count($emails));
        $this->assertEquals(1, $emails[0]['preferred']);
        $this->assertEquals('test3@test.com', $emails[0]['email']);

        # Change to non-preferred.
        $this->assertGreaterThan(0, $u->addEmail('test3@test.com', 0));
        $emails = $u->getEmails();
        $this->log("Non-preferred " . var_export($emails, TRUE));
        $this->assertEquals(2, count($emails));
        $this->assertEquals(0, $emails[0]['preferred']);
        $this->assertEquals(0, $emails[1]['preferred']);
        $this->assertEquals('test@test.com', $emails[0]['email']);
        $this->assertEquals('test3@test.com', $emails[1]['email']);

        # Change to preferred.
        $this->assertGreaterThan(0, $u->addEmail('test3@test.com', 1));
        $emails = $u->getEmails();
        $this->assertEquals(2, count($emails));
        $this->assertEquals(1, $emails[0]['preferred']);
        $this->assertEquals('test3@test.com', $emails[0]['email']);

        # Add them as memberships and check we get the right ones.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $emailid1 = $u->getIdForEmail('test@test.com')['id'];
        $emailid3 = $u->getIdForEmail('test3@test.com')['id'];
        $this->log("emailid1 $emailid1 emailid3 $emailid3");
        $u->addMembership($group1, User::ROLE_MEMBER, $emailid1);
        $u->removeMembership($group1);
        $u->addMembership($group1, User::ROLE_MEMBER, $emailid3);
        $u->addMembership($group1, User::ROLE_MEMBER, $emailid3);
        $this->assertNull($u->getIdForEmail('wibble@test.com'));
    }

    public function testLogins() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertEquals(0, count($u->getEmails()));

        # Add a login - should work.
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_YAHOO, 'testid'));

        # Check it's there
        $logins = $u->getLogins();
        $this->assertEquals(1, count($logins));
        $this->assertEquals('testid', $logins[0]['uid']);

        # Add it again - should work
        $this->assertEquals(1, $u->addLogin(User::LOGIN_YAHOO, 'testid'));

        # Add a second
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, '1234'));
        $logins = $u->getLogins();
        $this->assertEquals(2, count($logins));
        $this->assertEquals($id, $u->findByLogin(User::LOGIN_FACEBOOK, '1234'));
        $this->assertNull($u->findByLogin(User::LOGIN_YAHOO, '1234'));
        $this->assertNull($u->findByLogin(User::LOGIN_FACEBOOK, 'testid'));
        $this->assertGreaterThan(0, $u->removeLogin(User::LOGIN_FACEBOOK, '1234'));
        $this->assertNull($u->findByLogin(User::LOGIN_FACEBOOK, '1234'));

        $this->assertEquals($id, $u->findByLogin(User::LOGIN_YAHOO, 'testid'));
        $this->assertNull($u->findByLogin(User::LOGIN_YAHOO, 'testinvalid'));

        # Test native
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $this->assertFalse($u->login('testpwbad'));
    }

    public function testErrors() {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->assertEquals(0, $u->addEmail('test-owner@yahoogroups.com'));

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());
        $u->setDbhm($mock);
        $id = $u->create(NULL, NULL, 'Test User');
        $this->assertNull($id);
    }

    public function testMemberships() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);

        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        User::clearCache($id);
        $eid = $u->addEmail('test@test.com');
        $this->assertGreaterThan(0, $eid);
        $u = User::get($this->dbhm, $this->dbhm, $id);
        $this->assertGreaterThan(0, $u->addEmail('test@test.com'));
        $this->assertEquals($u->getRoleForGroup($group1), User::ROLE_NONMEMBER);
        $this->assertFalse($u->isModOrOwner($group1));

        // Again for coverage.
        $this->assertFalse($u->isModOrOwner($group1));

        $u->addMembership($group1, User::ROLE_MEMBER, $eid);
        $this->assertEquals($u->getRoleForGroup($group1), User::ROLE_MEMBER);
        $this->assertFalse($u->isModOrOwner($group1));
        $u->setGroupSettings($group1, [
            'testsetting' => 'test'
        ]);
        $this->assertEquals('test', $u->getGroupSettings($group1)['testsetting']);
        $atts = $u->getPublic();
        $this->assertFalse(array_key_exists('applied', $atts));

        $this->log("Set owner");
        $u->setRole(User::ROLE_OWNER, $group1);
        $this->assertEquals($u->getRoleForGroup($group1), User::ROLE_OWNER);
        $this->assertTrue($u->isModOrOwner($group1));
        $this->assertTrue($u->isModOrOwner($group1));
        $this->assertTrue(array_key_exists('work', $u->getMemberships(FALSE, NULL, TRUE)[0]));
        $settings = $u->getGroupSettings($group1);
        $this->log("Settings " . var_export($settings, TRUE));
        $this->assertEquals('test', $settings['testsetting']);
        $this->assertTrue(array_key_exists('configid', $settings));
        $modships = $u->getModeratorships();
        $this->assertEquals(1, count($modships));

        # Should be able to see the applied history.
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $atts = $u->getPublic();
        $this->log("Applied " . var_export($atts['applied'], TRUE));
        $this->assertEquals(1, count($atts['applied']));

        # Get again for coverage.
        $users = [
            $u->getId() => [
                'id' => $u->getId()
            ],
        ];

        $rets = $u->getPublics($users);
        $this->assertEquals(1, count($rets[$u->getId()]['applied']));
        $rets2 = $u->getPublics($rets);
        $this->assertEquals(1, count($rets2[$u->getId()]['applied']));

        $u->setRole(User::ROLE_MODERATOR, $group1);
        # We had a problem preserving the emails off setting - test here.
        $u->setMembershipAtt($group1, 'emailfrequency', 0);
        $this->assertEquals($u->getRoleForGroup($group1), User::ROLE_MODERATOR);
        $this->assertTrue($u->isModOrOwner($group1));
        $membs = $u->getMemberships(FALSE, NULL, TRUE, TRUE);
        $this->assertEquals(0, $membs[0]['mysettings']['emailfrequency']);
        $this->assertTrue(array_key_exists('work', $membs[0]));
        $modships = $u->getModeratorships();
        $this->assertEquals(1, count($modships));

        $u->addMembership($group2, User::ROLE_MEMBER, $eid);
        $membs = $u->getMemberships();
        $this->assertEquals(2, count($membs));

        // Check history.
        $this->waitBackground();
        $hist = $u->getMembershipHistory();
        $this->assertEquals($group2, $hist[0]['group']['id']);

        // Support and admin users have a mod role on the group even if not a member
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'testgroup1@yahoogroups.com', $msg);
        list ($mid, $failok) = $m->save();
        $m = new Message($this->dbhm, $this->dbhm, $mid);

        # Make it not from us else we'll have moderator role.
        $m->setPrivate('fromuser', NULL);

        $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $this->assertEquals($u->getRoleForGroup($group1), User::ROLE_MODERATOR);
        $this->assertEquals(User::ROLE_MODERATOR, $m->getRoleForMessage()[0]);
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $this->log("Check role for group");
        $this->assertEquals($u->getRoleForGroup($group1), User::ROLE_OWNER);
        $this->log("Check role for message");
        $me = Session::whoAmI($this->dbhr, $this->dbhm);
        $me->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $this->assertEquals(User::SYSTEMROLE_ADMIN, $me->getPrivate('systemrole'));
        $this->assertEquals(User::ROLE_OWNER, $m->getRoleForMessage()[0]);

        # Ban ourselves; can't rejoin
        $this->log("Ban " . $u->getId() . " from $group2");
        $u->removeMembership($group2, TRUE);
        $membs = $u->getMemberships();
        $this->log("Memberships after ban " . var_export($membs, TRUE));

        # Should have the membership of group1.
        $this->assertEquals(1, count($membs));
        $this->assertFalse($u->addMembership($group2));

        $g = Group::get($this->dbhr, $this->dbhm, $group1);
        $g->delete();
        $g = Group::get($this->dbhr, $this->dbhm, $group2);
        $g->delete();

        $membs = $u->getMemberships();
        $this->assertEquals(0, count($membs));
    }

    public function testMerge() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $group3 = $g->create('testgroup3', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $id2 = $u->create(NULL, NULL, 'Test User');
        $id3 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $this->assertGreaterThan(0, $u1->addEmail('test1@test.com'));
        $this->assertGreaterThan(0, $u1->addEmail('test2@test.com', 0));

        # Set up various memberships
        $u1->addMembership($group1, User::ROLE_MODERATOR);
        $u2->addMembership($group1, User::ROLE_MEMBER);
        $u2->addMembership($group2, User::ROLE_OWNER);
        $u1->addMembership($group3, User::ROLE_MEMBER);
        $u2->addMembership($group3, User::ROLE_MODERATOR);
        $settings = [ 'test' => 1 ];
        $u2->setGroupSettings($group1, $settings);
        $u2->setGroupSettings($group2, $settings);
        error_log("Set setting for {$u2->getId()} on $group2");
        $u1->clearMembershipCache();
        $this->assertEquals([ 'active' => 1, 'pushnotify' => 1, 'showchat' => 1, 'eventsallowed' => 1, 'volunteeringallowed' => 1], $u1->getGroupSettings($group2));

        # Set up some chats
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($cid1, $blocked) = $c->createConversation($id1, $id3);
        list ($cid2, $blocked) = $c->createConversation($id2, $id3);
        $cid3 = $c->createUser2Mod($id2, $group1);
        $this->log("Created to mods $cid3");
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $str = "Test from $id1 to $id3 in $cid1";
        list ($mid1, $banned) = $cm->create($cid1, $id1, $str);
        $this->log("Created $mid1 $str");
        $str = "Test from $id2 to $id3 in $cid2";
        list ($mid2, $banned) = $cm->create($cid2, $id2, $str);
        $this->log("Created $mid2 $str");

        # Ensure we have a default config.
        $mc = new ModConfig($this->dbhr, $this->dbhm);
        $mcid = $mc->create('UT Test');
        $mc->setPrivate('default', 1);

        # We should get the group back and a default config.
        $this->log("Check settings for $id2 on $group2");
        $this->assertEquals(1, $u2->getGroupSettings($group1)['test'] );
        $this->assertEquals(1, $u2->getGroupSettings($group2)['test'] );
        $this->assertNotNull($u2->getGroupSettings($group2)['configid']);

        # Merge u2 into u1
        $this->assertTrue($u1->merge($id1, $id2, "UT"));

        # Pick up new settings.
        $u1 = new User($this->dbhm, $this->dbhm, $id1, FALSE);
        $u2 = new User($this->dbhm, $this->dbhm, $id2, FALSE);

        $this->log("Check post merge $id1 on $group2");
        $this->assertEquals(1, $u1->getGroupSettings($group2)['test'] );
        $this->assertNotNull($u1->getGroupSettings($group2)['configid']);

        # u2 doesn't exist
        $this->assertNull($u2->getId());

        # Now u1 is a member of all three
        $membs = $u1->getMemberships();
        $this->assertEquals(3, count($membs));
        $this->assertEquals($group1, $membs[0]['id']);
        $this->assertEquals($group2, $membs[1]['id']);
        $this->assertEquals($group3, $membs[2]['id']);

        # The merge should have preserved the highest setting.
        $this->assertEquals(User::ROLE_MODERATOR, $membs[0]['role']);
        $this->assertEquals(User::ROLE_OWNER, $membs[1]['role']);
        $this->assertEquals(User::ROLE_MODERATOR, $membs[2]['role']);

        $emails = $u1->getEmails();
        $this->log("Emails " . var_export($emails, true));
        $this->assertEquals(2, count($emails));
        $this->assertEquals('test1@test.com', $emails[0]['email']);
        $this->assertEquals(1, $emails[0]['preferred']);
        $this->assertEquals('test2@test.com', $emails[1]['email']);
        $this->assertEquals(0, $emails[1]['preferred']);

        # Check chats
        list ($cid1a, $blocked) = $c->createConversation($id1, $id3);
        self::assertEquals($cid1a, $cid1);
        $c = new ChatRoom($this->dbhr, $this->dbhm, $cid1);
        list ($msgs, $users) = $c->getMessages();
        $this->log("Messages " . var_export($msgs, TRUE));
        $this->assertEquals(2, count($msgs));

        $cid3a = $c->createUser2Mod($id1, $group1);
        self::assertEquals($cid3a, $cid3);

        # Check the merge history shows.
        $this->waitBackground();
        $ctx = NULL;
        $logs = [ $id1 => [ 'id' => $id1 ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, TRUE, $ctx);

        $this->assertEquals(1, count($logs[$id1]['merges']));
        $this->assertEquals($id2, $logs[$id1]['merges'][0]['from']);
        $this->assertEquals($id1, $logs[$id1]['merges'][0]['to']);

        $mc->delete();
    }

    public function testMergeReal() {
        # Simulates processing from real emails migration script.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group = $g->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $id2 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $eid1 = $u1->addEmail('test1@test.com');
        $eid2 = $u2->addEmail('test2@test.com');

        # Set up various memberships
        $u1->addMembership($group, User::ROLE_MEMBER, $eid1);
        $u2->addMembership($group, User::ROLE_MEMBER, $eid2);

        # Merge u2 into u1
        $this->assertTrue($u1->merge($id1, $id2, "UT"));

        # Pick up new settings.
        $u1 = User::get($this->dbhm, $this->dbhm, $id1);

        $membershipid = $this->dbhm->preQuery("SELECT id FROM memberships WHERE userid = ?;", [ $id1 ])[0]['id'];
        $this->log("Membershipid $membershipid");
    }


    public function testMergeError() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $group3 = $g->create('testgroup3', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $id2 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $this->assertGreaterThan(0, $u1->addEmail('test1@test.com'));
        $this->assertGreaterThan(0, $u1->addEmail('test2@test.com', 1));

        # Set up various memberships
        $u1->addMembership($group1, User::ROLE_MODERATOR);
        $u2->addMembership($group1, User::ROLE_MEMBER);
        $u2->addMembership($group2, User::ROLE_OWNER);
        $u1->addMembership($group3, User::ROLE_MEMBER);
        $u2->addMembership($group3, User::ROLE_MODERATOR);

        global $dbconfig;

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());
        $u1->setDbhm($mock);

        # Merge u2 into u1
        $this->assertFalse($u1->merge($id1, $id2, "UT"));

        # Pick up new settings.
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);

        # Both exist
        $this->assertNotNull($u1->getId());
        $this->assertNotNull($u2->getId());

        }

    public function testMergeForbidden()
    {
        $g = Group::get($this->dbhr, $this->dbhm);

        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $id2 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $settings = $u1->getPublic()['settings'];
        $settings['canmerge'] = FALSE;
        $u1->setPrivate('settings', json_encode($settings));
        $this->assertFalse($u1->merge($id1, $id2, "Should fail"));
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $this->assertEquals($id1, $u1->getId());
        $this->assertEquals($id2, $u2->getId());
    }

    public function testSystemRoleMax() {

        $u = User::get($this->dbhr, $this->dbhm);

        $this->assertEquals(User::SYSTEMROLE_ADMIN, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_ADMIN));
        $this->assertEquals(User::SYSTEMROLE_ADMIN, $u->systemRoleMax(User::SYSTEMROLE_ADMIN, User::SYSTEMROLE_SUPPORT));

        $this->assertEquals(User::SYSTEMROLE_SUPPORT, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT));
        $this->assertEquals(User::SYSTEMROLE_SUPPORT, $u->systemRoleMax(User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_USER));

        $this->assertEquals(User::SYSTEMROLE_MODERATOR, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_MODERATOR));
        $this->assertEquals(User::SYSTEMROLE_MODERATOR, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_USER));

        $this->assertEquals(User::SYSTEMROLE_USER, $u->systemRoleMax(User::SYSTEMROLE_USER, User::SYSTEMROLE_USER));

        }

    public function testRoleMax() {

        $u = User::get($this->dbhr, $this->dbhm);

        $this->assertEquals(User::ROLE_OWNER, $u->roleMax(User::ROLE_MEMBER, User::ROLE_OWNER));
        $this->assertEquals(User::ROLE_OWNER, $u->roleMax(User::ROLE_OWNER, User::ROLE_MODERATOR));

        $this->assertEquals(User::ROLE_MODERATOR, $u->roleMax(User::ROLE_MEMBER, User::ROLE_MODERATOR));
        $this->assertEquals(User::ROLE_MODERATOR, $u->roleMax(User::ROLE_MODERATOR, User::ROLE_NONMEMBER));

        $this->assertEquals(User::ROLE_MEMBER, $u->roleMax(User::ROLE_MEMBER, User::ROLE_MEMBER));
        $this->assertEquals(User::ROLE_MEMBER, $u->roleMax(User::ROLE_MEMBER, User::ROLE_NONMEMBER));

        $this->assertEquals(User::ROLE_NONMEMBER, $u->roleMax(User::ROLE_NONMEMBER, User::ROLE_NONMEMBER));

        }

    public function testRoleMin() {

        $u = User::get($this->dbhr, $this->dbhm);

        $this->assertEquals(User::ROLE_MEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_OWNER));
        $this->assertEquals(User::ROLE_MODERATOR, $u->roleMin(User::ROLE_OWNER, User::ROLE_MODERATOR));

        $this->assertEquals(User::ROLE_MEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_MODERATOR));
        $this->assertEquals(User::ROLE_NONMEMBER, $u->roleMin(User::ROLE_MODERATOR, User::ROLE_NONMEMBER));

        $this->assertEquals(User::ROLE_MEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_MEMBER));
        $this->assertEquals(User::ROLE_NONMEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_NONMEMBER));

        $this->assertEquals(User::ROLE_NONMEMBER, $u->roleMax(User::ROLE_NONMEMBER, User::ROLE_NONMEMBER));

        }

    public function testMail() {
        $this->log(__METHOD__ );

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $g = Group::get($this->dbhr, $this->dbhm);
        $group = $g->create('testgroup1', Group::GROUP_REUSE);

        # Suppress mails.
        $u = $this->getMockBuilder('Freegle\Iznik\User')
        ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
        ->setMethods(array('mailer'))
        ->getMock();
        $u->method('mailer')->willReturn(false);
        $this->assertGreaterThan(0, $u->addEmail('test@test.com'));
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccfollmembto', 'Specific');
        $c->setPrivate('ccfollmembaddr', 'test@test.com');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s->setPrivate('action', 'Reject');

        $u->mail($group, "test", "test", $sid);

        $s->delete();

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s->setPrivate('action', 'Leave Approved Member');

        $this->log("Mail them");
        $u->mail($group, "test", "test", $sid, 'Leave Approved Member');

        $s->delete();
        $c->delete();

        }

    public function testComments() {
        $u1 = User::get($this->dbhr, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $u2 = User::get($this->dbhr, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u1->login('testpw'));

        # Reset u1 to match what Session::whoAmI will give so that when we change the role in u1, the role
        # returned by Session::whoAmI will have changed.
        $u1 = Session::whoAmI($this->dbhr, $this->dbhm);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);

        # Try to add a comment when not a mod.
        $this->assertNull($u2->addComment($gid, "Test comment"));
        $u1->addMembership($gid);
        $this->assertNull($u2->addComment($gid, "Test comment"));
        $this->log("Set role mod");
        $u1->setRole(User::ROLE_MODERATOR, $gid);
        $cid = $u2->addComment($gid, "Test comment");
        $this->assertNotNull($cid);
        $atts = $u2->getPublic();
        $this->assertEquals(1, count($atts['comments']));
        $this->assertEquals($cid, $atts['comments'][0]['id']);
        $this->assertEquals("Test comment", $atts['comments'][0]['user1']);
        $this->assertEquals($id1, $atts['comments'][0]['byuserid']);
        $this->assertNull($atts['comments'][0]['user2']);

        # Get it
        $atts = $u2->getComment($cid);
        $this->assertEquals("Test comment", $atts['user1']);
        $this->assertEquals($id1, $atts['byuserid']);
        $this->assertNull($atts['user2']);
        $this->assertNull($u2->getComment(-1));

        # Edit it
        $this->assertTrue($u2->editComment($cid, "Test comment2"));
        $atts = $u2->getPublic();
        $this->assertEquals(1, count($atts['comments']));
        $this->assertEquals($cid, $atts['comments'][0]['id']);
        $this->assertEquals("Test comment2", $atts['comments'][0]['user1']);

        # Can't see comments when a user
        $u1->setRole(User::ROLE_MEMBER, $gid);
        $atts = $u2->getPublic();
        $this->assertFalse(array_key_exists('comments', $atts));

        # Try to delete a comment when not a mod
        $u1->removeMembership($gid);
        $this->assertFalse($u2->deleteComment($cid));
        $u1->addMembership($gid);
        $this->assertFalse($u2->deleteComment($cid));
        $u1->addMembership($gid, User::ROLE_MODERATOR);
        $this->assertTrue($u2->deleteComment($cid));
        $atts = $u2->getPublic();
        $this->assertEquals(0, count($atts['comments']));

        # Delete all
        $cid = $u2->addComment($gid, "Test comment");
        $this->assertNotNull($cid);
        $this->assertTrue($u2->deleteComments());
        $atts = $u2->getPublic();
        $this->assertEquals(0, count($atts['comments']));

        }

    /**
     * @dataProvider checkProvider
     */
    public function testCheck($mod) {
        $u1 = User::get($this->dbhr, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u1->login('testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);

        $g = Group::get($this->dbhr, $this->dbhm);

        $groupids = [];

        for ($i = 0; $i < Spam::SEEN_THRESHOLD + 1; $i++) {
            $gid = $g->create("testgroup$i", Group::GROUP_REUSE);
            $groupids[] = $gid;

            $u1->addMembership($gid, User::ROLE_MODERATOR);
            $u2->addMembership($gid, $mod ? User::ROLE_MODERATOR : User::ROLE_MEMBER);
            $u1->processMemberships();

            $u2 = User::get($this->dbhr, $this->dbhm, $id2, FALSE);
            $this->waitBackground();
            $atts = $u2->getPublic();

            $this->log("$i");

            # Should not show for review until we exceed the threshold.
            if ($i < Spam::SEEN_THRESHOLD || $mod) {
                $this->assertNull($u2->getMembershipAtt($gid, 'reviewrequestedat'), "Shouldn't be flagged as not exceeded threshold");
            } else {
                # Should now show for review on this group, but only the member, not the mod.
                $this->assertNotNull($u2->getMembershipAtt($gid, 'reviewrequestedat'));
                $ctx = NULL;
                $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::SPAM, [ $gid ]);
                $this->assertEquals(1, count($membs), "Should be flagged on $gid");

                # ...but not any previous groups because we flagged as reviewed on those.
                foreach ($groupids as $checkgid) {
                    if ($checkgid != $gid) {
                        $this->assertNotNull($u2->getMembershipAtt($checkgid, 'reviewrequestedat'));
                        $ctx = NULL;
                        $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::SPAM, [ $checkgid ]);
                        $this->assertEquals(0, count($membs), "Shouldn't be flagged on $checkgid");
                    }
                }
            }

            # Flag as reviewed.  Should stop us seeing it next time.
            $u1->memberReview($gid, FALSE, 'UT');
            $u2->memberReview($gid, FALSE, 'UT');
        }
    }

    public function checkProvider() {
        return [
            [ FALSE ],
            [ TRUE ]
        ];
    }

    public function testVerifyMail() {
        $_SERVER['HTTP_HOST'] = 'localhost';

        # Test add when it's not in use anywhere
        $u1 = User::get($this->dbhr, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $_SESSION['id'] = $id1;
        $this->assertFalse($u1->verifyEmail('bit-bucket@test.smtp.org'));

        # Confirm it
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'bit-bucket@test.smtp.org';");
        $this->assertEquals(1, count($emails));
        foreach ($emails as $email) {
            $this->assertNotFalse($u1->confirmEmail($email['validatekey']));
        }

        # Test add when it's in use for another user
        $u2 = User::get($this->dbhr, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $this->assertFalse($u2->verifyEmail('bit-bucket@test.smtp.org'));

        # Now confirm that- should trigger a merge.
        $this->assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u2->login('testpw'));
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'bit-bucket@test.smtp.org';");
        $this->assertEquals(1, count($emails));
        foreach ($emails as $email) {
            $this->assertNotFalse($u2->confirmEmail($email['validatekey']));
        }

        # Test add when it's already one of ours.
        $this->assertNotNull($u2->addEmail('test@test.com'));
        $this->assertTrue($u2->verifyEmail('test@test.com'));

    }

    public function testConfirmUnsubscribe() {
        $_SERVER['HTTP_HOST'] = 'localhost';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');

        $s = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm, $uid ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));

        $s->confirmUnsubscribe();
        $this->assertEquals(1, count($this->msgsSent));
        $this->assertTrue(strpos($this->msgsSent[0], '&k=') !== FALSE);
        $this->assertTrue(strpos($this->msgsSent[0], '&confirm') !== FALSE);
    }

    public function testCanon() {
        $this->assertEquals('test@testcom', User::canonMail('test@test.com'));
        $this->assertEquals('test@testcom', User::canonMail('test+fake@test.com'));
        $this->assertEquals('firstlast@gmailcom', User::canonMail('first.last@gmail.com'));
        $this->assertEquals('first.last@othercom', User::canonMail('first.last@other.com'));
        $this->assertEquals('test@usertrashnothingcom', User::canonMail('test-g1@user.trashnothing.com'));
        $this->assertEquals('test@usertrashnothingcom', User::canonMail('test-x1@user.trashnothing.com'));
        $this->assertEquals('test-x1@usertrashnothingcom', User::canonMail('test-x1-x2@user.trashnothing.com'));
        $this->assertEquals('app+test@proxymailfacebookcom', User::canonMail('app+test@proxymail.facebook.com'));
        $this->assertEquals('+123@testcom', User::canonMail('+123@testcom'));
    }

    public function testInvent() {
        # No emails - should invent something.
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $email = $u->inventEmail();
        $this->log("No emails, invented $email");
        $this->assertFalse(strpos($email, 'test'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('tes2t');
        $email = $u->inventEmail();
        $this->log("Invalid, invented $email");
        $this->assertFalse(strpos($email, 'test'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $email = $u->inventEmail();
        $this->log("Unusable email, invented $email");
        $this->assertFalse(strpos($email, 'test'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->setPrivate('yahooid', '-wibble');
        $email = $u->inventEmail();
        $this->log("Yahoo ID, invented $email");
        $this->assertNotFalse(strpos($email, 'wibble'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('wobble@wobble.com');
        $email = $u->inventEmail();
        $this->log("Other email, invented $email");
        $this->assertNotFalse(strpos($email, 'wobble'));

        # Call again now we have one.
        $email2 = $u->inventEmail();
        $this->log("Other email again, invented $email2");
        $this->assertEquals($email, $email2);

        $id = $u->create(NULL, NULL, "Test - User");
        $email = $u->inventEmail();
        $this->log("No emails, invented $email");
        error_log("Invented $email");

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Wibble', 'User', NULL);
        $u->addEmail('real%test.com@gtempaccount.com');
        $email = $u->inventEmail();
        $this->log("Other email, invented $email");
        error_log("Invented $email");
        $this->assertFalse(strpos($email, 'test'));
    }

    public function testThank() {
        $s = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->willReturn(TRUE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertNotNull($id);
        $u->addEmail('test@test.com');
        $u->thankDonation();

        }

    public function testNativeWelcome() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        # Mock the group ("your hair looks terrible") to check the welcome mail is sent.
        $g = $this->getMockBuilder('Freegle\Iznik\Group')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, $gid])
            ->setMethods(array('sendIt'))
            ->getMock();
        $g->method('sendIt')->will($this->returnCallback(function ($mailer, $message) {
            return ($this->sendMock($mailer, $message));
        }));

        $g->setPrivate('onhere', TRUE);
        $g->setPrivate('welcomemail', "Test welcome");

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');

        $s = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm, $uid ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            error_log("Mock");
            return($this->sendMock($mailer, $message));
        }));

        # Welcome mail sent on application.
        $s->addMembership($gid, User::ROLE_MEMBER, NULL, MembershipCollection::APPROVED, NULL, NULL, TRUE, $g);
        $s->processMemberships($g);
        $this->assertEquals(1, count($this->msgsSent));
    }

    public function testInvite() {
        $s = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->willReturn(TRUE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');

        # Invite - should work
        $invited = $u->invite('test2@test.com');
        $this->assertTrue($invited);

        # Invite again - should fail
        $invited = $u->invite('test2@test.com');
        $this->assertFalse($invited);

        }

    public function testProfile() {
        $u = new User($this->dbhr, $this->dbhm);

        $uid = $u->create("Test", "User", "Test User");
        $this->log("Created user $uid");
        $eid = $u->addEmail('gravatar@ehibbert.org.uk');
        $this->log("Email $eid");
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->log("gravatar@ehibbert.org.uk " . var_export($atts['profile'], TRUE));
        $this->assertTrue($atts['profile']['gravatar']);

        $uid = $u->create("Test", "User", "Test User");
        $u->addEmail('atrusty-gxxxx@user.trashnothing.com');
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->log("atrusty " . var_export($atts['profile'], TRUE));
        $this->assertTrue($atts['profile']['TN']);

        $uid = $u->create("Test", "User", "Test User");
        $this->log("Created user $uid");
        $eid = $u->addEmail('test@gmail.com');
        $this->log("Email $eid");
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->assertTrue($atts['profile']['gravatar']);

        $uid = $u->create("Test", "User", "Test User");
        $this->log("Created user $uid");
        $eid = $u->addEmail('test@gmail.com');
        $u->setSetting('useprofile', FALSE);
        User::clearCache();
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->assertTrue($atts['profile']['default']);
    }

    public function testBadYahooId() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', '42decfdc9afca38d682324e2e5a02123');
        $u->setPrivate('yahooid', '42decfdc9afca38d682324e2e5a02123');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        self::assertLessThan(32, strlen($atts['fullname']));

        }

    public function testAFreegler() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'A freegler');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        self::assertNotEquals('A freegler', $atts['fullname']);
     }

    public function testSetting() {
        $u = User::get($this->dbhm, $this->dbhm);
        $u->create('Test', 'User', 'A freegler');
        $this->assertTrue($u->getSetting('notificationmails', TRUE));

        $settings = json_decode($u->getPrivate('settings'), TRUE);
        $settings['notificationmails'] = FALSE;
        $u->setPrivate('settings', json_encode($settings));
        $this->assertFalse($u->getSetting('notificationmails', TRUE));

        }

    public function testFreegleMembership() {
        $u1 = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u1->create('Test', 'User', 'A freegler');
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create('Test', 'User', 'A freegler');

        # Check that if we are a mod on a Freegle group we can see membership of other Freegle groups.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid1 = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid2 = $g->create('testgroup2', Group::GROUP_FREEGLE);

        $u1->addMembership($gid1, User::ROLE_MODERATOR);

        $u2->addMembership($gid2, User::ROLE_MEMBER);

        # Make the membership look old otherwise it will show up anyway.
        $u2->setMembershipAtt($gid2, 'added', '2001-01-01');

        $this->assertTrue($u1->login('testpw'));

        $atts = $u2->getPublic(NULL, FALSE, FALSE, TRUE);
        self::assertEquals(1, count($atts['memberof']));
        self::assertEquals($gid2, $atts['memberof'][0]['id']);

        }

    public function testNonFreegleMembership() {
        $u1 = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u1->create('Test', 'User', 'A freegler');
        $this->assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u2 = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u2->create('Test', 'User', 'A freegler');

        # Check that if we are a mod on a Freegle group we can see membership of other Freegle groups.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid1 = $g->create('testgroup1', Group::GROUP_FREEGLE);
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid2 = $g->create('testgroup2', Group::GROUP_REUSE);

        $u1->addMembership($gid1, User::ROLE_MODERATOR);

        $u2->addMembership($gid2, User::ROLE_MEMBER);

        # Make the membership look old otherwise it will show up anyway.
        $u2->setMembershipAtt($gid2, 'added', '2001-01-01');

        $this->assertTrue($u1->login('testpw'));

        $atts = $u2->getPublic(NULL, FALSE, FALSE, TRUE);
        self::assertEquals(0, count($atts['memberof']));

        }

    public function exportParams() {
        return([
            [ true, 24, 24 ],
            [ false, 12, 12 ],
            [ false, 4, 4 ],
            [ false, 2, 2 ],
            [ false, 1, 1 ],
            [ false, 0, 0 ],
            [ false, -1, -1 ]
        ]);
    }

    /**
     * @param $modnotifs
     * @param $backupmodnotifs
     * @dataProvider exportParams
     */
    public function testExport($background, $modnotifs, $backupmodnotifs) {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid2 = $u->create('Test', 'User', 'Test User');
        $uid = $u->create('Test', 'User', 'Test User');
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);

        # Set up some things to ensure we have coverage.
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, 'testid', 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $n = new Newsfeed($this->dbhr, $this->dbhm);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($uid, $uid2);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $mid = $m->create($rid, $uid, "Test");

        $settings = [
            'mylocation' => [
                'id' => 1,
                'lat' => 8.51111,
                'lng' => 179.11111,
                'area' => [
                    'name' => 'Somewhere'
                ]
            ],
            'modnotifs' => $modnotifs,
            'backupmodnotifs' => $backupmodnotifs
        ];

        $u->invite('test@test.com');
        $u->addPhone('1234');

        $u->setPrivate('settings', json_encode($settings));
        $this->assertEquals(8.51111, $u->getPublic()['settings']['mylocation']['lat']);
        $this->assertEquals(179.11111, $u->getPublic()['settings']['mylocation']['lng']);
        $this->assertEquals('Somewhere', $u->getPublic()['settings']['mylocation']['area']['name']);

        # Get blurred location.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);
        $u->addMembership($gid);
        $atts = $u->getPublic();
        $latlngs = $u->getLatLngs([ $atts ], TRUE, TRUE, TRUE, NULL, Utils::BLUR_1K);
        $this->assertEquals(8.5153, $latlngs[$u->getId()]['lat']);
        $this->assertEquals(179.1191, $latlngs[$u->getId()]['lng']);
        $this->assertEquals('testgroup', $latlngs[$u->getId()]['group']);

        $nid = $n->create(Newsfeed::TYPE_MESSAGE, $uid, 'Test');

        if ($background) {
            # Export
            list ($id, $tag) = $u->requestExport(FALSE);
            $count = 0;

            do {
                $ret = $u->getExport($uid, $id, $tag);

                $count++;
                $this->log("...waiting for export $count");
                sleep(1);
            } while (!Utils::pres('data', $ret) && $count < 600);

            $ret = $ret['data'];
        } else {
            # Export
            list ($id, $tag) = $u->requestExport(TRUE);
            $ret = $u->export($id, $tag);
        }

        $this->assertEquals($uid, $ret['Our_internal_ID_for_you']);

        $n = new Newsfeed($this->dbhr, $this->dbhm, $nid);
        $n->delete();

        #file_put_contents('/tmp/export', $encoded);

    }

    public function testForget() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $uid = $u->create('Test', 'User', 'Test User');

        # Set up some things to ensure coverage.
        $email = $u->inventEmail();
        $u->addEmail($email);
        $u->addEmail('test@test.com');
        $u->setPrivate('yahooid', 'test');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        # Log in to generate log.
        $u->login('testpw');
        $_SESSION['id'] = NULL;

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $u->addMembership($group1);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: OFFER: thing (place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list ($mid, $failok) = $m->save();
        $m = new Message($this->dbhm, $this->dbhm, $mid);

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        list ($cid1, $blocked) = $c->createConversation($uid, $uid1);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $str = "Test";
        list ($mid1, $banned) = $cm->create($cid1, $uid, $str);

        $u->forget('Test');

        User::clearCache();
        $this->waitBackground();

        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $log = $this->findLog(Log::TYPE_USER, Log::SUBTYPE_DELETED, $logs[$u->getId()]['logs']);
        $this->assertNotNull($log);

        # Get logs for coverage.
        $u = User::get($this->dbhm, $this->dbhm, $uid);

        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $this->assertEquals(0, strpos($logs[$u->getId()]['logs'][0]['user']['fullname'], 'Deleted User'));

        # Check we zapped things
        $emails = $u->getEmails();
        self::assertEquals(1, count($emails));
        self::assertEquals($email, $emails[0]['email']);
        self::assertEquals('Deleted User #' . $uid, $u->getPrivate('fullname'));
        self::assertEquals(NULL, $u->getPrivate('firstname'));
        self::assertEquals(NULL, $u->getPrivate('lastname'));
        self::assertEquals(NULL, $u->getPrivate('yahooid'));
        self::assertEquals(0, count($u->getLogins()));
        self::assertEquals(0, count($u->getMemberships()));
        $this->assertNotNull($m->hasOutcome());
    }

    public function testRetention() {
        $u = User::get($this->dbhm, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $uid2 = $u->create('Test', 'User', 'Test User');
        $u->setPrivate('yahooid', -1);
        $this->waitBackground();

        self::assertEquals(0, $u->userRetention($uid1));
        $u->setPrivate('lastaccess', '2000-01-01');
        self::assertEquals(1, $u->userRetention($uid2));

        $u = User::get($this->dbhm, $this->dbhm, $uid2);
        $this->assertNull($u->getPrivate('yahooid'));
    }

    public function testPhone() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');

        $this->assertEquals('+441234567890', $u->formatPhone('01234 567890'));

        $u->addPhone('01234 567890');
        $u->sms('Test message', 'https://' . USER_SITE, TWILIO_TEST_FROM, TWILIO_TEST_SID, TWILIO_TEST_AUTHTOKEN);

        # Again - too recent to send
        $u->sms('Test message', 'https://' . USER_SITE, TWILIO_TEST_FROM, TWILIO_TEST_SID, TWILIO_TEST_AUTHTOKEN);

        # Test with error.
        $u->removePhone();
        $this->assertNotNull($u->addPhone('+15005550001'));
        $u->sms('Test message', 'https://' . USER_SITE, TWILIO_TEST_FROM, TWILIO_TEST_SID, TWILIO_TEST_AUTHTOKEN);
    }

    public function testKudos() {
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setPrivate('lat', 8.5);
        $g->setPrivate('lng', 179.3);
        $g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.3 8.3, 179.3 8.6, 179.1 8.6, 179.1 8.3))');

        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->assertNotNull($areaid);
        $areaatts = $l->getPublic();
        $this->assertNull($areaatts['areaid']);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $this->log("Created $uid, add membership of $gid");
        $rc = $u->addMembership($gid);
        $this->assertNotNull($u->isApprovedMember($gid));
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, $uid, 'testpw'));
        $u->setPrivate('lastlocation', $fullpcid);
        $u->setSetting('mylocation', $areaatts);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list ($mid, $failok) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->setPrivate('sourceheader', Message::PLATFORM);
        $m->setPrivate('fromuser', $uid);

        $u->updateKudos($uid, TRUE);
        $kudos = $u->getKudos($uid);
        $this->assertEquals(0, $kudos['kudos']);
        $top = $u->topKudos($gid);
        $this->assertEquals($uid, $top[0]['user']['id']);

        # No mods as not got Facebook login
        $mods = $u->possibleMods($gid);
        $this->assertEquals(1, count($mods));
        $this->assertEquals($uid, $mods[0]['user']['id']);

        }

    public function testActiveSince() {
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $this->dbhm->preExec("UPDATE users SET lastaccess = NOW() WHERE id = ?;", [
            $uid
        ]);

        $ids = $u->getActiveSince('5 minutes ago', 'tomorrow');
        $this->assertTrue(in_array($uid, $ids));
    }

    public function testEncodeId() {
        $this->assertEquals(123, User::decodeId(User::encodeId(123)));

        # Test we can search on UID.
        $u = User::get($this->dbhm, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $uid2 = $u->create('Test', 'User', 'Test User');
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($uid1, $uid2);
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $_SESSION['id'] = $uid2;
        $enc = User::encodeId($uid1);
        $this->assertEquals($uid1, User::decodeId($enc));
        $ctx = NULL;
        $search = $u->search($enc, $ctx);
        $this->assertEquals(1, count($search));
        $this->assertEquals($uid1, $search[0]['id']);

        # Should see the login link.
        $this->assertNotNull(Utils::presdef('loginlink', $search[0], NULL));

        # Should see the chat rooms.
        $this->assertEquals(1, count(Utils::pres('chatrooms', $search[0], NULL)));
    }

    public function testActiveCounts() {
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->user = $u;

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $this->assertEquals(1, $u->addMembership($group1));
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item 1 (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list ($mid, $failok) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->route($m);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item 2 (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list ($mid, $failok) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->route($m);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'WANTED: Test item 1 (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list ($mid, $failok) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->route($m);

        $info = $u->getInfo();
        $this->assertEquals(2, $info['offers']);
        $this->assertEquals(1, $info['wanteds']);
        $this->assertEquals(2, $info['openoffers']);
        $this->assertEquals(1, $info['openwanteds']);

        $this->assertEquals([
            'offers' => 2,
            'wanteds' => 1
        ], $u->getActiveCounts());
    }

    public function testHide() {
        $u = new User($this->dbhm, $this->dbhm);
        $uid = $u->create("Test", "User", "A freegler");
        $u = new User($this->dbhm, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $this->assertNotEquals('A freegler', $atts['fullname']);
        $u = new User($this->dbhm, $this->dbhm, $uid);
        $this->assertEquals(1, $u->getPrivate('inventedname'));
    }

    public function testResurrect() {
        $u = new User($this->dbhm, $this->dbhm);
        $uid = $u->create(NULL, NULL, "Deleted User #1");
        $name = $u->getName();
        $this->assertNotFalse(strpos($name, 'Deleted User'));

        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $name = $u->getName();
        $this->assertFalse(strpos($name, 'Deleted User'));
    }

    public function testSplit() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->setPrivate('yahooid', '-testyahooid');
        $this->assertNotNull($u->addEmail('test@test.com'));
        $u->split('test@test.com');
        $this->assertNotNull($u->findByEmail('test@test.com'));
        $this->assertNotNull($u->findByYahooId('-testyahooid'));
    }

    public function testSplitWithChats() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id1 = $u->create('Test', 'User', NULL);
        $u->setPrivate('yahooid', '-testyahooid');
        $this->assertNotNull($u->addEmail('test@test.com'));

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);
        $u->addMembership($this->gid);

        # Create a message, so we can reference it from chats, so that those chats get split.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $id2 = $u->create('Test', 'User', NULL);
        $id3 = $u->create('Test', 'User', NULL);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid1, $blocked) = $r->createConversation($id1, $id2);
        list ($rid2, $blocked) = $r->createConversation($id3, $id1);

        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $mid1 = $cm->create($rid1, $id1, 'Test', ChatMessage::TYPE_INTERESTED, $id);
        $mid2 = $cm->create($rid2, $id1, 'Test', ChatMessage::TYPE_INTERESTED, $id);

        $u = User::get($this->dbhm, $this->dbhm, $id1);
        $newid = $u->split('test@test.com');

        $this->assertNotNull($u->findByEmail('test@test.com'));
        $this->assertNotNull($u->findByYahooId('-testyahooid'));

        $chats = $r->listForUser(Session::modtools(), $newid);
        $this->assertEquals(2, count($chats));
    }

    public function testEmailHistory() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->dbhm->preExec("INSERT IGNORE INTO logs_emails (timestamp, eximid, userid, `from`, `to`, messageid, subject, status) VALUES (NOW(),?,?,?,?,?,?,?);", [
            Utils::randstr(32),
            $id,
            'test@test.com',
            'test@test.com',
            Utils::randstr(32),
            Utils::randstr(32),
            Utils::randstr(32)
        ], FALSE);

        $atts = $u->getPublic(NULL, TRUE, TRUE, TRUE, TRUE, FALSE, TRUE, [ MessageCollection::APPROVED ], FALSE);
        $this->assertEquals(1, count($atts['emailhistory']));
    }

    public function testDeletedUserLogs() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create('Test', 'User', NULL);
        $this->assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $this->assertTrue($u->login('testpw'));
        $u->forget("UT");
        $this->waitBackground();
        $ctx = NULL;
        $logs = [ $u->getId() => [ 'id' => $u->getId() ] ];
        $u->getPublicLogs($u, $logs, FALSE, $ctx);
        $this->assertEquals(0, strpos($logs[$u->getId()]['logs'][0]['user']['fullname'], 'Deleted User'));
        $this->assertEquals(0, strpos($logs[$u->getId()]['logs'][1]['byuser']['fullname'], 'Deleted User'));
    }

    public function testMailer() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');

        $mock = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, $id])
            ->setMethods(array('sendIt'))
            ->getMock();
        $mock->method('sendIt')->will($this->returnCallback(function($mailer, $message) {
            return($this->sendMock($mailer, $message));
        }));
        $mock->mailer($u, NULL, "Test", "test@test.com", "test@test.com", "Test", "test@test.com", "Test", "Test");
        $this->assertEquals(1, count($this->msgsSent));

        $mock = $this->getMockBuilder('Freegle\Iznik\User')
            ->setConstructorArgs([$this->dbhm, $this->dbhm, $id])
            ->setMethods(array('sendIt'))
            ->getMock();
        $mock->method('sendIt')->willThrowException(new \Exception());
        $mock->mailer($u, NULL, "Test", "test@test.com", "test@test.com", "Test", "test@test.com", "Test", "Test");
        $this->assertEquals(1, count($this->msgsSent));
    }

    public function testChatCounts() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        # Set up a user with 2 MT messages and 1 FD message and check that we calculate the payload correctly.
        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        $u->addMembership($gid, User::ROLE_MODERATOR);

        $id2 = $u->create(NULL, NULL, 'Test User');
        $u->addMembership($gid);
        $id3 = $u->create(NULL, NULL, 'Test User');
        $u->addMembership($gid);
        $id4 = $u->create(NULL, NULL, 'Test User');
        $u->addMembership($gid);

        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($r1, $blocked) = $r->createConversation($id1, $id2);
        $m = new ChatMessage($this->dbhr, $this->dbhm);
        $m->create($r1, $id2, 'Test');

        $r2 = $r->createUser2Mod($id2, $gid);
        $m->create($r2, $id2, 'Test');
        $r3 = $r->createUser2Mod($id2, $gid);
        $m->create($r3, $id3, 'Test');

        $u = User::get($this->dbhr, $this->dbhm, $id1);
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route) = $u->getNotificationPayload(FALSE);
        $this->assertEquals(1, $chatcount);
        list ($total, $chatcount, $notifcount, $title, $message, $chatids, $route) = $u->getNotificationPayload(TRUE);
        $this->assertEquals(2, $chatcount);
    }

    public function testFormatPhone() {
        $u = new User($this->dbhr, $this->dbhm);
        $this->assertEquals('+447888888888' ,$u->formatPhone('+44447888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('+4444447888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('4444447888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('447888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('4407888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('+4407888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('07888888888'));
        $this->assertEquals('+447888888888' ,$u->formatPhone('+440447888888888'));
    }

    public function testJobAds() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');

        $settings = [
            'mylocation' => [
                'lat' => 52.57,
                'lng' => -2.03,
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));
        $jobs = $u->getJobAds();
        $this->assertGreaterThan(0, strlen($jobs['jobs']));
    }

    public function testSetPostcode() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');

        $l = new Location($this->dbhr, $this->dbhm);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');

        $settings = [
            'mylocation' => [
                'lat' => 8.51111,
                'lng' => 179.11111,
                'type' => 'Postcode',
                'id' => $pcid,
                'name' => 'TV1'
            ],
        ];

        $u->setPrivate('settings', json_encode($settings));
        $this->waitBackground();
        $logs = [ $uid => [ 'id' => $uid ] ];
        $u = new User($this->dbhr, $this->dbhm);
        $u->getPublicLogs($u, $logs, FALSE, $ctx, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_USER, Log::SUBTYPE_POSTCODECHANGE, $logs[$uid]['logs']);
        $this->assertNotNull($log);
    }

    public function testObfuscate() {
        $u = new User($this->dbhr, $this->dbhm);
        $this->assertEquals('t***@test.com', $u->obfuscateEmail('t@test.com'));
        $this->assertEquals('t***@test.com', $u->obfuscateEmail('te@test.com'));
        $this->assertEquals('t***@test.com', $u->obfuscateEmail('tes@test.com'));
        $this->assertEquals('t***t@test.com', $u->obfuscateEmail('test@test.com'));
        $this->assertEquals('t***1@test.com', $u->obfuscateEmail('test1@test.com'));
        $this->assertEquals('t***2@test.com', $u->obfuscateEmail('test12@test.com'));
        $this->assertEquals('tes***890@test.com', $u->obfuscateEmail('test1234567890@test.com'));
        $this->assertEquals('Your Apple ID', $u->obfuscateEmail('1234@privaterelay.appleid.com'));
    }

    public function testGetCity() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);

        $settings = [
            'mylocation' => [
                'id' => 1,
                'lat' => 55.9,
                'lng' => -3.15,
                'area' => [
                    'name' => 'Somewhere'
                ]
            ]
        ];

        $u->setPrivate('settings', json_encode($settings));
        list ($city, $lat, $lng) = $u->getCity();
        $this->assertEquals(55.9, $lat);
        $this->assertEquals(-3.15, $lng);
        $this->assertEquals('Edinburgh', $city);
    }

    public function testBadEmail() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $this->assertNull($u->addEmail('notify-2023105-3506086@users.ilovefreegle.org'));
        $this->assertNull($u->addEmail('replyto-2023105@users.ilovefreegle.org'));
    }

    public function testTNName() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'wibble-g123');
        $this->assertEquals('wibble', $u->getName(TRUE, NULL, TRUE));
    }

    public function testGmailVariants() {
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test.user@gmail.com');
        $this->assertTrue($u->verifyEmail('testuser@gmail.com'));
    }

    public function testMergeBanned() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $group3 = $g->create('testgroup3', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $id2 = $u->create(NULL, NULL, 'Test User');
        $id3 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        $this->assertGreaterThan(0, $u1->addEmail('test1@test.com'));
        $this->assertGreaterThan(0, $u1->addEmail('test2@test.com', 0));

        # Set up various memberships
        $u1->addMembership($group1, User::ROLE_MODERATOR);
        $u2->addMembership($group1, User::ROLE_MEMBER);
        $u2->addMembership($group2, User::ROLE_OWNER);
        $u1->addMembership($group3, User::ROLE_MEMBER);
        $u2->addMembership($group3, User::ROLE_MODERATOR);

        # Ban u1 on group2.
        $u1->removeMembership($group2, TRUE);

        # Merge u2 into u1
        $this->assertTrue($u1->merge($id1, $id2, "UT"));

        # Now u1 should be banned on group2
        $this->assertTrue($u1->isBanned($group2));
        $membs = $u1->getMemberships();
        $this->assertEquals(2, count($membs));
        $this->assertEquals($group1, $membs[0]['id']);
        $this->assertEquals($group3, $membs[1]['id']);
    }
}


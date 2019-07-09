<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/message/Message.php';
require_once IZNIK_BASE . '/include/newsfeed/Newsfeed.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class userTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
        $dbhm->preExec("DELETE users, users_logins FROM users INNER JOIN users_logins ON users.id = users_logins.userid WHERE uid IN ('testid', '1234');");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE firstname = 'Test' AND lastname = 'User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort LIKE 'testgroup%';");
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
        assertEquals('Test', $atts['firstname']);
        assertEquals('User', $atts['lastname']);
        assertNull($atts['fullname']);
        assertEquals('Test User', $u->getName());
        assertEquals($id, $u->getPrivate('id'));
        assertNull($u->getPrivate('invalidid'));

        $u->setPrivate('yahooid', 'testyahootest');
        assertEquals($id, $u->findByYahooId('testyahootest'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $u->addMembership($group1);
        assertGreaterThan(0, $u->delete());

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $atts = $u->getPublic();
        assertNull($atts['firstname']);
        assertNull($atts['lastname']);
        assertEquals('Test User', $atts['fullname']);
        assertEquals('Test User', $u->getName());
        assertEquals($id, $u->getPrivate('id'));
        assertGreaterThan(0, $u->delete());

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

        }

    public function testEmails() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertEquals(0, count($u->getEmails()));

        # Add an email - should work.
        $eid = $u->addEmail('test@test.com');
        assertGreaterThan(0, $eid);
        assertEquals(0, $u->getEmailAge('test@test.com'));
        assertEquals('test@test.com', $u->getEmailById($eid));
        assertEquals($id, $u->findByEmailHash(md5('test@test.com')));

        # Check it's there
        $emails = $u->getEmails();
        assertEquals(1, count($emails));
        assertEquals('test@test.com', $emails[0]['email']);

        # Add it again - should work
        assertGreaterThan(0, $u->addEmail('test@test.com'));

        # Add a second
        assertGreaterThan(0, $u->addEmail('test2@test.com', 0));
        $emails = $u->getEmails();
        assertEquals(2, count($emails));
        assertEquals(0, $emails[1]['preferred']);
        assertEquals($id, $u->findByEmail('test2@test.com'));
        assertEquals($id, $u->findByEmail("wibble-$id@" . USER_DOMAIN)); // Should parse the UID out of it.
        assertGreaterThan(0, $u->removeEmail('test2@test.com'));
        assertNull($u->findByEmail('test2@test.com'));

        assertEquals($id, $u->findByEmail('test@test.com'));
        assertNull($u->findByEmail('testinvalid@test.com'));

        # Add a new preferred
        assertGreaterThan(0, $u->addEmail('test3@test.com', 1));
        $emails = $u->getEmails();
        assertEquals(2, count($emails));
        assertEquals(1, $emails[0]['preferred']);
        assertEquals('test3@test.com', $emails[0]['email']);

        # Change to non-preferred.
        assertGreaterThan(0, $u->addEmail('test3@test.com', 0));
        $emails = $u->getEmails();
        $this->log("Non-preferred " . var_export($emails, TRUE));
        assertEquals(2, count($emails));
        assertEquals(0, $emails[0]['preferred']);
        assertEquals(0, $emails[1]['preferred']);
        assertEquals('test@test.com', $emails[0]['email']);
        assertEquals('test3@test.com', $emails[1]['email']);

        # Change to preferred.
        assertGreaterThan(0, $u->addEmail('test3@test.com', 1));
        $emails = $u->getEmails();
        assertEquals(2, count($emails));
        assertEquals(1, $emails[0]['preferred']);
        assertEquals('test3@test.com', $emails[0]['email']);

        # Add them as memberships and check we get the right ones.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);
        $emailid1 = $u->getIdForEmail('test@test.com')['id'];
        $emailid3 = $u->getIdForEmail('test3@test.com')['id'];
        $this->log("emailid1 $emailid1 emailid3 $emailid3");
        $u->addMembership($group1, User::ROLE_MEMBER, $emailid1);
        assertEquals($emailid1, $u->getEmailForYahooGroup($group1, FALSE, TRUE)[0]);
        $u->removeMembership($group1);
        $u->addMembership($group1, User::ROLE_MEMBER, $emailid3);
        $u->addMembership($group1, User::ROLE_MEMBER, $emailid3);
        assertEquals($emailid3, $u->getEmailForYahooGroup($group1, FALSE, TRUE)[0]);
        assertNull($u->getIdForEmail('wibble@test.com'))['id'];
        assertNull($u->getEmailForYahooGroup(-1)[0]);

        }

    public function testLogins() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertEquals(0, count($u->getEmails()));

        # Add a login - should work.
        assertGreaterThan(0, $u->addLogin(User::LOGIN_YAHOO, 'testid'));

        # Check it's there
        $logins = $u->getLogins();
        assertEquals(1, count($logins));
        assertEquals('testid', $logins[0]['uid']);

        # Add it again - should work
        assertEquals(1, $u->addLogin(User::LOGIN_YAHOO, 'testid'));

        # Add a second
        assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, '1234'));
        $logins = $u->getLogins();
        assertEquals(2, count($logins));
        assertEquals($id, $u->findByLogin(User::LOGIN_FACEBOOK, '1234'));
        assertNull($u->findByLogin(User::LOGIN_YAHOO, '1234'));
        assertNull($u->findByLogin(User::LOGIN_FACEBOOK, 'testid'));
        assertGreaterThan(0, $u->removeLogin(User::LOGIN_FACEBOOK, '1234'));
        assertNull($u->findByLogin(User::LOGIN_FACEBOOK, '1234'));

        assertEquals($id, $u->findByLogin(User::LOGIN_YAHOO, 'testid'));
        assertNull($u->findByLogin(User::LOGIN_YAHOO, 'testinvalid'));

        # Test native
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        assertFalse($u->login('testpwbad'));

        }

    public function testErrors() {
        $u = User::get($this->dbhr, $this->dbhm);
        assertEquals(0, $u->addEmail('test-owner@yahoogroups.com'));

        $mock = $this->getMockBuilder('LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new Exception());
        $u->setDbhm($mock);
        $id = $u->create(NULL, NULL, 'Test User');
        assertNull($id);

        }

    public function testMemberships() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);
        $group2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);

        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create(NULL, NULL, 'Test User');
        $this->dbhm->preExec("UPDATE users SET yahooUserId = 1 WHERE id = $id;");
        User::clearCache($id);
        $eid = $u->addEmail('test@test.com');
        assertGreaterThan(0, $eid);
        $u->setPrivate('yahooUserId', 1);
        $u = User::get($this->dbhm, $this->dbhm, $id);
        assertGreaterThan(0, $u->addEmail('test@test.com'));
        assertEquals($u->getRoleForGroup($group1), User::ROLE_NONMEMBER);
        assertFalse($u->isModOrOwner($group1));

        $u->addMembership($group1, User::ROLE_MEMBER, $eid);
        assertEquals($u->getRoleForGroup($group1), User::ROLE_MEMBER);
        assertFalse($u->isModOrOwner($group1));
        $u->setGroupSettings($group1, [
            'testsetting' => 'test'
        ]);
        assertEquals('test', $u->getGroupSettings($group1)['testsetting']);
        $atts = $u->getPublic();
        assertFalse(array_key_exists('applied', $atts));

        $this->log("Set owner");
        $u->setRole(User::ROLE_OWNER, $group1);
        assertEquals($u->getRoleForGroup($group1), User::ROLE_OWNER);
        assertTrue($u->isModOrOwner($group1));
        assertTrue(array_key_exists('work', $u->getMemberships(FALSE, NULL, TRUE)[0]));
        $settings = $u->getGroupSettings($group1);
        $this->log("Settings " . var_export($settings, TRUE));
        assertEquals('test', $settings['testsetting']);
        assertTrue(array_key_exists('configid', $settings));
        $modships = $u->getModeratorships();
        assertEquals(1, count($modships));

        # Should be able to see the applied history.
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $atts = $u->getPublic();
        $this->log("Applied " . var_export($atts['applied'], TRUE));
        assertEquals(1, count($atts['applied']));

        $u->setRole(User::ROLE_MODERATOR, $group1);
        assertEquals($u->getRoleForGroup($group1), User::ROLE_MODERATOR);
        assertTrue($u->isModOrOwner($group1));
        assertTrue(array_key_exists('work', $u->getMemberships(FALSE, NULL, TRUE)[0]));
        $modships = $u->getModeratorships();
        assertEquals(1, count($modships));

        $u->addMembership($group2, User::ROLE_MEMBER, $eid);
        $membs = $u->getMemberships();
        assertEquals(2, count($membs));

        $u->removeMembership($group1);
        assertEquals($u->getRoleForGroup($group1), User::ROLE_NONMEMBER);
        $membs = $u->getMemberships();
        assertEquals(1, count($membs));
        assertEquals($group2, $membs[0]['id']);

        # Plugin work should exist
        $p = new Plugin($this->dbhr, $this->dbhm);
        $work = $p->get([$group1]);
        assertEquals(1, count($work));
        assertEquals($group1, $work[0]['groupid']);
        assertEquals('{"type":"RemoveApprovedMember","email":"test@test.com"}', $work[0]['data']);
        $pid = $work[0]['id'];
        $p->delete($pid);

        // Support and admin users have a mod role on the group even if not a member
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $m = new Message($this->dbhm, $this->dbhm, $mid);

        # Make it not from us else we'll have moderator role.
        $m->setPrivate('fromuser', NULL);

        $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        assertEquals($u->getRoleForGroup($group1), User::ROLE_MODERATOR);
        assertEquals(User::ROLE_MODERATOR, $m->getRoleForMessage()[0]);
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        $this->log("Check role for group");
        assertEquals($u->getRoleForGroup($group1), User::ROLE_OWNER);
        $this->log("Check role for message");
        $me = whoAmI($this->dbhr, $this->dbhm);
        $me->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        assertEquals(User::SYSTEMROLE_ADMIN, $me->getPrivate('systemrole'));
        assertEquals(User::ROLE_OWNER, $m->getRoleForMessage()[0]);

        # Ban ourselves; can't rejoin
        $this->log("Ban " . $u->getId() . " from $group2");
        $u->removeMembership($group2, TRUE);
        $membs = $u->getMemberships();
        $this->log("Memberships after ban " . var_export($membs, TRUE));

        # Should have the membership of group1, implicitly added because we sent a message from that group.
        assertEquals(1, count($membs));
        assertFalse($u->addMembership($group2));

        $g = Group::get($this->dbhr, $this->dbhm, $group1);
        $g->delete();
        $g = Group::get($this->dbhr, $this->dbhm, $group2);
        $g->delete();

        $membs = $u->getMemberships();
        assertEquals(0, count($membs));

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
        assertGreaterThan(0, $u1->addEmail('test1@test.com'));
        assertGreaterThan(0, $u1->addEmail('test2@test.com', 0));

        # Set up various memberships
        $u1->addMembership($group1, User::ROLE_MODERATOR);
        $u2->addMembership($group1, User::ROLE_MEMBER);
        $u2->addMembership($group2, User::ROLE_OWNER);
        $u1->addMembership($group3, User::ROLE_MEMBER);
        $u2->addMembership($group3, User::ROLE_MODERATOR);
        $settings = [ 'test' => 1];
        $u2->setGroupSettings($group2, $settings);
        $u1->clearMembershipCache();
        assertEquals([ 'active' => 1, 'pushnotify' => 1, 'showchat' => 1, 'eventsallowed' => 1, 'volunteeringallowed' => 1], $u1->getGroupSettings($group2));

        # Set up some chats
        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $cid1 = $c->createConversation($id1, $id3);
        $cid2 = $c->createConversation($id2, $id3);
        $cid3 = $c->createUser2Mod($id2, $group1);
        $this->log("Created to mods $cid3");
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $str = "Test from $id1 to $id3 in $cid1";
        $mid1 = $cm->create($cid1, $id1, $str);
        $this->log("Created $mid1 $str");
        $str = "Test from $id2 to $id3 in $cid2";
        $mid2 = $cm->create($cid2, $id2, $str);
        $this->log("Created $mid2 $str");

        # Ensure we have a default config.
        $mc = new ModConfig($this->dbhr, $this->dbhm);
        $mcid = $mc->create('UT Test');
        $mc->setPrivate('default', 1);

        # We should get the group back and a default config.
        $this->log("Check settings for $id2 on $group2");
        assertEquals(1, $u2->getGroupSettings($group2)['test'] );
        assertNotNull($u2->getGroupSettings($group2)['configid']);

        # Merge u2 into u1
        assertTrue($u1->merge($id1, $id2, "UT"));

        # Pick up new settings.
        $u1 = new User($this->dbhm, $this->dbhm, $id1, FALSE);
        $u2 = new User($this->dbhm, $this->dbhm, $id2, FALSE);

        $this->log("Check post merge $id1 on $group2");
        assertEquals(1, $u1->getGroupSettings($group2)['test'] );
        assertNotNull($u1->getGroupSettings($group2)['configid']);

        # u2 doesn't exist
        assertNull($u2->getId());

        # Now u1 is a member of all three
        $membs = $u1->getMemberships();
        assertEquals(3, count($membs));
        assertEquals($group1, $membs[0]['id']);
        assertEquals($group2, $membs[1]['id']);
        assertEquals($group3, $membs[2]['id']);

        # The merge should have preserved the highest setting.
        assertEquals(User::ROLE_MODERATOR, $membs[0]['role']);
        assertEquals(User::ROLE_OWNER, $membs[1]['role']);
        assertEquals(User::ROLE_MODERATOR, $membs[2]['role']);

        $emails = $u1->getEmails();
        $this->log("Emails " . var_export($emails, true));
        assertEquals(2, count($emails));
        assertEquals('test1@test.com', $emails[0]['email']);
        assertEquals(1, $emails[0]['preferred']);
        assertEquals('test2@test.com', $emails[1]['email']);
        assertEquals(0, $emails[1]['preferred']);

        # Check chats
        $cid1a = $c->createConversation($id1, $id3);
        self::assertEquals($cid1a, $cid1);
        $c = new ChatRoom($this->dbhr, $this->dbhm, $cid1);
        list ($msgs, $users) = $c->getMessages();
        $this->log("Messages " . var_export($msgs, TRUE));
        assertEquals(2, count($msgs));

        $cid3a = $c->createUser2Mod($id1, $group1);
        self::assertEquals($cid3a, $cid3);

        $mc->delete();

        }

    public function testMergeReal() {
        # Simulates processing from real emails migration script.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group = $g->create('testgroup', Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);

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
        assertTrue($u1->merge($id1, $id2, "UT"));

        # Pick up new settings.
        $u1 = User::get($this->dbhm, $this->dbhm, $id1);

        $membershipid = $this->dbhm->preQuery("SELECT id FROM memberships WHERE userid = ?;", [ $id1 ])[0]['id'];
        $this->log("Membershipid $membershipid");

        # Should have both Yahoo memberships.
        $yahoomembers = $this->dbhm->preQuery("SELECT * FROM memberships_yahoo WHERE membershipid = ? ORDER BY emailid;", [ $membershipid ]);
        $this->log("Yahoo memberships " . var_export($yahoomembers, TRUE));
        assertEquals($eid1, $yahoomembers[0]['emailid']);
        assertEquals($eid2, $yahoomembers[1]['emailid']);

        }


    public function testDoubleAdd() {
        # Simulates processing from real emails migration script.
        $g = Group::get($this->dbhr, $this->dbhm);
        $group = $g->create('testgroup', Group::GROUP_REUSE);
        $g->setPrivate('onyahoo', 1);

        $u = User::get($this->dbhr, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $eid1 = $u->addEmail('test1@test.com');
        $eid2 = $u->addEmail('test2@test.com');

        # Set up membership with two emails
        $u->addMembership($group, User::ROLE_MEMBER, $eid1);
        $u->addMembership($group, User::ROLE_MEMBER, $eid2);

        $membershipid = $this->dbhm->preQuery("SELECT id FROM memberships WHERE userid = ?;", [ $id1 ])[0]['id'];
        $this->log("Membershipid $membershipid");

        # Should have both Yahoo memberships.
        $yahoomembers = $this->dbhm->preQuery("SELECT * FROM memberships_yahoo WHERE membershipid = ? ORDER BY emailid;", [ $membershipid ]);
        $this->log("Yahoo memberships " . var_export($yahoomembers, TRUE));
        self::assertEquals(2, count($yahoomembers));
        assertEquals($eid1, $yahoomembers[0]['emailid']);
        assertEquals($eid2, $yahoomembers[1]['emailid']);

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
        assertGreaterThan(0, $u1->addEmail('test1@test.com'));
        assertGreaterThan(0, $u1->addEmail('test2@test.com', 1));

        # Set up various memberships
        $u1->addMembership($group1, User::ROLE_MODERATOR);
        $u2->addMembership($group1, User::ROLE_MEMBER);
        $u2->addMembership($group2, User::ROLE_OWNER);
        $u1->addMembership($group3, User::ROLE_MEMBER);
        $u2->addMembership($group3, User::ROLE_MODERATOR);

        $dbconfig = array (
            'host' => SQLHOST,
            'port_read' => SQLPORT_READ,
            'port_mod' => SQLPORT_MOD,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        $dsn = "mysql:host={$dbconfig['host']};port={$dbconfig['port_read']};dbname={$dbconfig['database']};charset=utf8";

        $mock = $this->getMockBuilder('LoggedPDO')
            ->setConstructorArgs(array($dsn, $dbconfig['user'], $dbconfig['pass'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ), TRUE))
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new Exception());
        $u1->setDbhm($mock);

        # Merge u2 into u1
        assertFalse($u1->merge($id1, $id2, "UT"));

        # Pick up new settings.
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);

        # Both exist
        assertNotNull($u1->getId());
        assertNotNull($u2->getId());

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
        assertFalse($u1->merge($id1, $id2, "Should fail"));
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        assertEquals($id1, $u1->getId());
        assertEquals($id2, $u2->getId());
    }

    public function testSystemRoleMax() {

        $u = User::get($this->dbhr, $this->dbhm);

        assertEquals(User::SYSTEMROLE_ADMIN, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_ADMIN));
        assertEquals(User::SYSTEMROLE_ADMIN, $u->systemRoleMax(User::SYSTEMROLE_ADMIN, User::SYSTEMROLE_SUPPORT));

        assertEquals(User::SYSTEMROLE_SUPPORT, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT));
        assertEquals(User::SYSTEMROLE_SUPPORT, $u->systemRoleMax(User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_USER));

        assertEquals(User::SYSTEMROLE_MODERATOR, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_MODERATOR));
        assertEquals(User::SYSTEMROLE_MODERATOR, $u->systemRoleMax(User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_USER));

        assertEquals(User::SYSTEMROLE_USER, $u->systemRoleMax(User::SYSTEMROLE_USER, User::SYSTEMROLE_USER));

        }

    public function testRoleMax() {

        $u = User::get($this->dbhr, $this->dbhm);

        assertEquals(User::ROLE_OWNER, $u->roleMax(User::ROLE_MEMBER, User::ROLE_OWNER));
        assertEquals(User::ROLE_OWNER, $u->roleMax(User::ROLE_OWNER, User::ROLE_MODERATOR));

        assertEquals(User::ROLE_MODERATOR, $u->roleMax(User::ROLE_MEMBER, User::ROLE_MODERATOR));
        assertEquals(User::ROLE_MODERATOR, $u->roleMax(User::ROLE_MODERATOR, User::ROLE_NONMEMBER));

        assertEquals(User::ROLE_MEMBER, $u->roleMax(User::ROLE_MEMBER, User::ROLE_MEMBER));
        assertEquals(User::ROLE_MEMBER, $u->roleMax(User::ROLE_MEMBER, User::ROLE_NONMEMBER));

        assertEquals(User::ROLE_NONMEMBER, $u->roleMax(User::ROLE_NONMEMBER, User::ROLE_NONMEMBER));

        }

    public function testRoleMin() {

        $u = User::get($this->dbhr, $this->dbhm);

        assertEquals(User::ROLE_MEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_OWNER));
        assertEquals(User::ROLE_MODERATOR, $u->roleMin(User::ROLE_OWNER, User::ROLE_MODERATOR));

        assertEquals(User::ROLE_MEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_MODERATOR));
        assertEquals(User::ROLE_NONMEMBER, $u->roleMin(User::ROLE_MODERATOR, User::ROLE_NONMEMBER));

        assertEquals(User::ROLE_MEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_MEMBER));
        assertEquals(User::ROLE_NONMEMBER, $u->roleMin(User::ROLE_MEMBER, User::ROLE_NONMEMBER));

        assertEquals(User::ROLE_NONMEMBER, $u->roleMax(User::ROLE_NONMEMBER, User::ROLE_NONMEMBER));

        }

    public function testMail() {
        $this->log(__METHOD__ );

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $g = Group::get($this->dbhr, $this->dbhm);
        $group = $g->create('testgroup1', Group::GROUP_REUSE);

        # Suppress mails.
        $u = $this->getMockBuilder('User')
        ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
        ->setMethods(array('mailer'))
        ->getMock();
        $u->method('mailer')->willReturn(false);
        assertGreaterThan(0, $u->addEmail('test@test.com'));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccfollmembto', 'Specific');
        $c->setPrivate('ccfollmembaddr', 'test@test.com');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s->setPrivate('action', 'Reject Member');

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
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u1->login('testpw'));

        # Reset u1 to match what whoAmI will give so that when we change the role in u1, the role
        # returned by whoAmI will have changed.
        $u1 = whoAmI($this->dbhr, $this->dbhm);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);

        # Try to add a comment when not a mod.
        assertNull($u2->addComment($gid, "Test comment"));
        $u1->addMembership($gid);
        assertNull($u2->addComment($gid, "Test comment"));
        $this->log("Set role mod");
        $u1->setRole(User::ROLE_MODERATOR, $gid);
        $cid = $u2->addComment($gid, "Test comment");
        assertNotNull($cid);
        $atts = $u2->getPublic();
        assertEquals(1, count($atts['comments']));
        assertEquals($cid, $atts['comments'][0]['id']);
        assertEquals("Test comment", $atts['comments'][0]['user1']);
        assertEquals($id1, $atts['comments'][0]['byuserid']);
        assertNull($atts['comments'][0]['user2']);

        # Get it
        $atts = $u2->getComment($cid);
        assertEquals("Test comment", $atts['user1']);
        assertEquals($id1, $atts['byuserid']);
        assertNull($atts['user2']);
        assertNull($u2->getComment(-1));

        # Edit it
        assertTrue($u2->editComment($cid, "Test comment2"));
        $atts = $u2->getPublic();
        assertEquals(1, count($atts['comments']));
        assertEquals($cid, $atts['comments'][0]['id']);
        assertEquals("Test comment2", $atts['comments'][0]['user1']);

        # Can't see comments when a user
        $u1->setRole(User::ROLE_MEMBER, $gid);
        $atts = $u2->getPublic();
        assertEquals(0, count($atts['comments']));

        # Try to delete a comment when not a mod
        $u1->removeMembership($gid);
        assertFalse($u2->deleteComment($cid));
        $u1->addMembership($gid);
        assertFalse($u2->deleteComment($cid));
        $u1->addMembership($gid, User::ROLE_MODERATOR);
        assertTrue($u2->deleteComment($cid));
        $atts = $u2->getPublic();
        assertEquals(0, count($atts['comments']));

        # Delete all
        $cid = $u2->addComment($gid, "Test comment");
        assertNotNull($cid);
        assertTrue($u2->deleteComments());
        $atts = $u2->getPublic();
        assertEquals(0, count($atts['comments']));

        }

    public function testCheck(){
        $u1 = User::get($this->dbhr, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u1->login('testpw'));
        $u2 = User::get($this->dbhr, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);

        $g = Group::get($this->dbhr, $this->dbhm);
        $groupids = [];

        for ($i = 0; $i < Spam::SEEN_THRESHOLD + 1; $i++) {
            $gid = $g->create("testgroup$i", Group::GROUP_REUSE);
            $g = Group::get($this->dbhr, $this->dbhm);
            $groupids[] = $gid;
            $u1->addMembership($gid, User::ROLE_MODERATOR);
            $u2->addMembership($gid);

            $u2 = User::get($this->dbhr, $this->dbhm, $id2, FALSE);
            $atts = $u2->getPublic();

            $this->log("$i");
            if ($i < Spam::SEEN_THRESHOLD) {
                assertFalse(pres('suspectcount', $atts));
            } else {
                assertEquals(1, pres('suspectcount', $atts));
                $membs = $g->getMembers(10, NULL, $ctx, NULL, MembershipCollection::SPAM, [ $gid ]);
                assertEquals(2, count($membs));
            }
        }

        }

    public function testVerifyMail() {
        $_SERVER['HTTP_HOST'] = 'localhost';

        # Test add when it's not in use anywhere
        $u1 = User::get($this->dbhr, $this->dbhm);
        $id1 = $u1->create('Test', 'User', NULL);
        $u1 = User::get($this->dbhr, $this->dbhm, $id1);
        assertFalse($u1->verifyEmail('bit-bucket@test.smtp.org'));

        # Confirm it
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'bit-bucket@test.smtp.org';");
        assertEquals(1, count($emails));
        foreach ($emails as $email) {
            assertTrue($u1->confirmEmail($email['validatekey']));
        }

        # Test add when it's in use for another user
        $u2 = User::get($this->dbhr, $this->dbhm);
        $id2 = $u2->create('Test', 'User', NULL);
        $u2 = User::get($this->dbhr, $this->dbhm, $id2);
        assertFalse($u2->verifyEmail('bit-bucket@test.smtp.org'));

        # Now confirm that- should trigger a merge.
        assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u2->login('testpw'));
        $emails = $this->dbhr->preQuery("SELECT * FROM users_emails WHERE email = 'bit-bucket@test.smtp.org';");
        assertEquals(1, count($emails));
        foreach ($emails as $email) {
            assertTrue($u2->confirmEmail($email['validatekey']));
        }

        # Test add when it's already one of ours.
        assertNotNull($u2->addEmail('test@test.com'));
        assertTrue($u2->verifyEmail('test@test.com'));

        }

    public function testCanon() {
        assertEquals('test@testcom', User::canonMail('test@test.com'));
        assertEquals('test@testcom', User::canonMail('test+fake@test.com'));
        assertEquals('firstlast@gmailcom', User::canonMail('first.last@gmail.com', TRUE));
        assertEquals('firstlast@gmailcom', User::canonMail('first.last@gmail.com', FALSE));
        assertEquals('first.last@othercom', User::canonMail('first.last@other.com', FALSE));
        assertEquals('firstlast@othercom', User::canonMail('first.last@other.com', TRUE));
        assertEquals('test@usertrashnothingcom', User::canonMail('test-g1@user.trashnothing.com'));
        assertEquals('test@usertrashnothingcom', User::canonMail('test-x1@user.trashnothing.com'));
        assertEquals('test-x1@usertrashnothingcom', User::canonMail('test-x1-x2@user.trashnothing.com'));
        assertEquals('app+test@proxymailfacebookcom', User::canonMail('app+test@proxymail.facebook.com'));

        }

    public function testInvent() {
        # No emails - should invent something.
        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $email = $u->inventEmail();
        $this->log("No emails, invented $email");
        assertFalse(strpos($email, 'test'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('tes2t');
        $email = $u->inventEmail();
        $this->log("Invalid, invented $email");
        assertFalse(strpos($email, 'test'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');
        $email = $u->inventEmail();
        $this->log("Unusable email, invented $email");
        assertFalse(strpos($email, 'test'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->setPrivate('yahooid', '-wibble');
        $email = $u->inventEmail();
        $this->log("Yahoo ID, invented $email");
        assertNotFalse(strpos($email, 'wibble'));

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('wobble@wobble.com');
        $email = $u->inventEmail();
        $this->log("Other email, invented $email");
        assertNotFalse(strpos($email, 'wobble'));

        # Call again now we have one.
        $email2 = $u->inventEmail();
        $this->log("Other email again, invented $email2");
        assertEquals($email, $email2);

        $id = $u->create(NULL, NULL, "Test - User");
        $email = $u->inventEmail();
        $this->log("No emails, invented $email");
        error_log("Invented $email");
    }

    public function testThank() {
        $s = $this->getMockBuilder('User')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->willReturn(TRUE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        assertNotNull($id);
        $u->addEmail('test@test.com');
        $u->thankDonation();

        }

    public function testInvite() {
        $s = $this->getMockBuilder('User')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm ])
            ->setMethods(array('sendIt'))
            ->getMock();
        $s->method('sendIt')->willReturn(TRUE);

        $u = User::get($this->dbhr, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->addEmail('test@test.com');

        # Invite - should work
        $invited = $u->invite('test2@test.com');
        assertTrue($invited);

        # Invite again - should fail
        $invited = $u->invite('test2@test.com');
        assertFalse($invited);

        }

    public function testProfile() {
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('norfolkmod@gmail.com');
        $this->dbhm->preExec("DELETE FROM users_images WHERE userid = ?;", [ $uid ]);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->log("Profile " . var_export($atts['profile'], TRUE));
        assertTrue($atts['profile']['gravatar']);
        $this->log("norfolkmod@gmail.com URL {$atts['profile']['url']}");

        $uid = $u->create("Test", "User", "Test User");
        $this->log("Created user $uid");
        $eid = $u->addEmail('gravatar@ehibbert.org.uk');
        $this->log("Email $eid");
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->log("gravatar@ehibbert.org.uk " . var_export($atts['profile'], TRUE));
        assertTrue($atts['profile']['gravatar']);

        $uid = $u->create("Test", "User", "Test User");
        $u->addEmail('atrusty-gxxxx@user.trashnothing.com');
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        $this->log("atrusty " . var_export($atts['profile'], TRUE));
        assertTrue($atts['profile']['TN']);

        }

    public function testBadYahooId() {
        $u = User::get($this->dbhr, $this->dbhm);
        $u->create('Test', 'User', '42decfdc9afca38d682324e2e5a02123');
        $u->setPrivate('yahooid', '42decfdc9afca38d682324e2e5a02123');
        $atts = $u->getPublic();
        self::assertLessThan(32, $atts['fullname']);

        }

    public function testAFreegler() {
        $u = User::get($this->dbhr, $this->dbhm);
        $u->create('Test', 'User', 'A freegler');
        $atts = $u->getPublic();
        self::assertNotEquals('A freegler', $atts['fullname']);

        }

    public function testSetting() {
        $u = User::get($this->dbhm, $this->dbhm);
        $u->create('Test', 'User', 'A freegler');
        assertTrue($u->getSetting('notificationmails', TRUE));

        $settings = json_decode($u->getPrivate('settings'), TRUE);
        $settings['notificationmails'] = FALSE;
        $u->setPrivate('settings', json_encode($settings));
        assertFalse($u->getSetting('notificationmails', TRUE));

        }

    public function testFreegleMembership() {
        $u1 = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u1->create('Test', 'User', 'A freegler');
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

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

        assertTrue($u1->login('testpw'));

        $ctx = NULL;
        $atts = $u2->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, TRUE);
        self::assertEquals(1, count($atts['memberof']));
        self::assertEquals($gid2, $atts['memberof'][0]['id']);

        }

    public function testNonFreegleMembership() {
        $u1 = User::get($this->dbhr, $this->dbhm);
        $uid1 = $u1->create('Test', 'User', 'A freegler');
        assertGreaterThan(0, $u1->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

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

        assertTrue($u1->login('testpw'));

        $ctx = NULL;
        $atts = $u2->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, TRUE);
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
        $uid = $u->create('Test', 'User', 'Test User');
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);

        # Set up some things to ensure we have coverage.
        $atts = $u->getPublic();
        $u->ensureAvatar($atts);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, 'testid', 'testpw'));
        assertTrue($u->login('testpw'));
        $n = new Newsfeed($this->dbhr, $this->dbhm);

        $settings = [
            'mylocation' => [
                'lat' => 8.5,
                'lng' => 179.1
            ],
            'modnotifs' => $modnotifs,
            'backupmodnotifs' => $backupmodnotifs
        ];

        $u->invite('test@test.com');
        $u->addPhone('1234');

        $u->setPrivate('settings', json_encode($settings));
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
            } while (!pres('data', $ret) && $count < 600);

            $ret = $ret['data'];
        } else {
            # Export
            list ($id, $tag) = $u->requestExport(TRUE);
            $ret = $u->export($id, $tag);
        }

        assertEquals($uid, $ret['Our_internal_ID_for_you']);

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
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $u->addMembership($group1);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $m = new Message($this->dbhm, $this->dbhm, $mid);

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $cid1 = $c->createConversation($uid1, $uid1);
        $cm = new ChatMessage($this->dbhr, $this->dbhm);
        $str = "Test";
        $mid1 = $cm->create($cid1, $uid, $str);

        $u->forget('Test');

        $this->waitBackground();
        $atts = $u->getPublic(NULL, FALSE, TRUE);
        $log = $this->findLog(Log::TYPE_USER, Log::SUBTYPE_DELETED, $atts['logs']);
        assertNotNull($log);

        # Check we zapped things
        $u = User::get($this->dbhr, $this->dbhm, $uid);

        $emails = $u->getEmails();
        self::assertEquals(1, count($emails));
        self::assertEquals($email, $emails[0]['email']);
        self::assertEquals('Deleted User #' . $uid, $u->getPrivate('fullname'));
        self::assertEquals(NULL, $u->getPrivate('firstname'));
        self::assertEquals(NULL, $u->getPrivate('lastname'));
        self::assertEquals(NULL, $u->getPrivate('yahooid'));
        self::assertEquals(0, count($u->getLogins()));
        self::assertEquals(0, count($u->getMemberships()));

        }

    public function testRetention() {
        $u = User::get($this->dbhm, $this->dbhm);
        $uid1 = $u->create('Test', 'User', 'Test User');
        $uid2 = $u->create('Test', 'User', 'Test User');
        $u->setPrivate('yahooid', -1);

        self::assertEquals(0, $u->userRetention($uid1));
        $u->setPrivate('lastaccess', '2000-01-01');
        self::assertEquals(1, $u->userRetention($uid2));

        $u = User::get($this->dbhm, $this->dbhm, $uid2);
        assertNull($u->getPrivate('yahooid'));
    }

    public function testPhone() {
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');

        $u->addPhone('01234 567890');
        $u->sms('Test message', 'https://' . USER_SITE, TWILIO_TEST_FROM, TWILIO_TEST_SID, TWILIO_TEST_AUTHTOKEN);

        # Again - too recent to send
        $u->sms('Test message', 'https://' . USER_SITE, TWILIO_TEST_FROM, TWILIO_TEST_SID, TWILIO_TEST_AUTHTOKEN);

        # Test with error.
        $u->removePhone();
        assertNotNull($u->addPhone('+15005550001'));
        $u->sms('Test message', 'https://' . USER_SITE, TWILIO_TEST_FROM, TWILIO_TEST_SID, TWILIO_TEST_AUTHTOKEN);

        }

    public function testKudos() {
        $g = Group::get($this->dbhm, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setPrivate('lat', 8.5);
        $g->setPrivate('lng', 179.3);
        $g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.3 8.3, 179.3 8.6, 179.1 8.6, 179.1 8.3))');

        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        $areaatts = $l->getPublic();
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)', 0);

        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $this->log("Created $uid, add membership of $gid");
        $rc = $u->addMembership($gid);
        assertNotNull($u->isApprovedMember($gid));
        assertGreaterThan(0, $u->addLogin(User::LOGIN_FACEBOOK, $uid, 'testpw'));
        $u->setPrivate('lastlocation', $fullpcid);
        $u->setSetting('mylocation', $areaatts);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->setPrivate('sourceheader', Message::PLATFORM);
        $m->setPrivate('fromuser', $uid);

        $u->updateKudos($uid, TRUE);
        $kudos = $u->getKudos($uid);
        assertEquals(0, $kudos['kudos']);
        $top = $u->topKudos($gid);
        assertEquals($uid, $top[0]['user']['id']);

        # No mods as not got Facebook login
        $mods = $u->possibleMods($gid);
        assertEquals(1, count($mods));
        assertEquals($uid, $mods[0]['user']['id']);

        }

    public function testActiveSince() {
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $this->dbhm->preExec("UPDATE users SET lastaccess = NOW() WHERE id = ?;", [
            $uid
        ]);

        $ids = $u->getActiveSince('5 minutes ago', 'tomorrow');
        assertTrue(in_array($uid, $ids));
    }

    public function testEncodeId() {
        assertEquals(123, User::decodeId(User::encodeId(123)));

        # Test we can search on UID.
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $enc = User::encodeId($uid);
        assertEquals($uid, User::decodeId($enc));
        $ctx = NULL;
        $search = $u->search($enc, $ctx);
        assertEquals(1, count($search));
        assertEquals($uid, $search[0]['id']);
    }

    public function testActiveCounts() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item 1 (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->route($m);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item 2 (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->route($m);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'WANTED: Test item 1 (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'test@test.com', 'testgroup1@yahoogroups.com', $msg);
        list($mid, $already) = $m->save();
        $m = new Message($this->dbhr, $this->dbhm, $mid);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $r->route($m);

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail('test@test.com');
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $info = $u->getInfo();
        assertEquals(2, $info['offers']);
        assertEquals(1, $info['wanteds']);
        assertEquals(2, $info['openoffers']);
        assertEquals(1, $info['openwanteds']);

        assertEquals([
            'offers' => 2,
            'wanteds' => 1
        ], $u->getActiveCounts());
    }
}


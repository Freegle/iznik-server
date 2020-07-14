<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/config/ModConfig.php';
require_once IZNIK_BASE . '/include/config/StdMessage.php';
require_once IZNIK_BASE . '/include/config/BulkOp.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class configTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup1'");

        $this->user = User::get($this->dbhm, $this->dbhm);
        $this->uid = $this->user->create('Test', 'User', NULL);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
    }

    public function testBasic() {
        # Basic create
        $this->log("Create");
        $c = new ModConfig($this->dbhr, $this->dbhm);
        $id = $c->create('TestConfig');
        assertNotNull($id);
        $c = new ModConfig($this->dbhr, $this->dbhm, $id);
        $c->setPrivate('default', TRUE);
        assertNotNull($c);

        # Use on a group
        $this->log("Use on group");
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup1', Group::GROUP_REUSE);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($group1, User::ROLE_MODERATOR);
        $c->useOnGroup($uid, $group1);
        assertEquals($id, $c->getForGroup($uid, $group1));

        $this->log("Login and get");
        assertTrue($this->user->login('testpw'));
        $configs = $this->user->getConfigs(TRUE);
        unset($_SESSION['id']);

        # Another mod on this group with no config set up should pick this one up as shared.
        $this->log("Another mod");
        $c->setPrivate('default', FALSE);
        $uid2 = $u->create(NULL, NULL, 'Test User');
        $u2 = User::get($this->dbhr, $this->dbhm, $uid2);
        assertGreaterThan(0, $u2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u2->addMembership($group1, User::ROLE_OWNER);
        assertEquals($id, $c->getForGroup($uid, $group1));
        assertEquals($id, $c->getForGroup($uid2, $group1));

        assertTrue($u2->login('testpw'));

        # Sleep for redis cache to expire
        $this->log("Sleep redis");
        sleep(REDIS_TTL+1);
        $this->log("Slept redis");

        # Should show in our list of all configs.
        $configs = $u2->getConfigs(TRUE);
        $this->log("Got configs " . count($configs));
        $found = FALSE;
        foreach ($configs as $config) {
            if ($config['id'] == $id) {
                $found = TRUE;
            }
        }
        assertTrue($found);

        # Should also show in our active configs.
        $configs = $u2->getConfigs(FALSE);
        $this->log("Got configs " . count($configs));
        $found = FALSE;
        foreach ($configs as $config) {
            if ($config['id'] == $id) {
                $found = TRUE;
            }
        }
        assertTrue($found);

        unset($_SESSION['id']);

        $this->log("New StdMessage");
        $m = new StdMessage($this->dbhr, $this->dbhm);
        $mid = $m->create("TestStdMessage", $id);
        assertNotNull($mid);
        $m = new StdMessage($this->dbhr, $this->dbhm, $mid);
        $m->setPrivate('body', 'Test');
        assertEquals('Test', $m->getPublic()['body']);
        assertFalse(array_key_exists('body', $m->getPublic(FALSE)));

        assertEquals('TestConfig', $c->getPublic()['name']);
        assertEquals('TestStdMessage', $c->getPublic()['stdmsgs'][0]['title']);

        $this->log("Delete message");
        $m->delete();
        $this->log("Delete config");
        $c->delete();

        # Create as current user
        $this->log("As current user");
        assertTrue($this->user->login('testpw'));
        $id = $c->create('TestConfig');
        $this->log("Created $id");
        assertNotNull($id);
        $c = new ModConfig($this->dbhr, $this->dbhm, $id);
        assertNotNull($c);
        assertEquals($this->uid, $c->getPrivate('createdby'));

        $this->log("bulk op");
        $b = new BulkOp($this->dbhr, $this->dbhm);
        $bid = $b->create('TestBulk', $id);
        assertNotNull($bid);

        $this->log("GetConfigs");
        $configs = $this->user->getConfigs(TRUE);

        # Have to scan as there are defaults.
        $found = FALSE;
        foreach ($configs as $config) {
            if ($id == $config['id']) {
                $found = TRUE;
                assertEquals($bid, $config['bulkops'][0]['id']);
            }
        }
        assertTrue($found);

        # Sleep for background logging
        $this->log("Wait background");
        $this->waitBackground();

        $this->log("Find log");
        $logs = $this->user->getPublic(NULL, FALSE, TRUE)['logs'];
        $log = $this->findLog(Log::TYPE_CONFIG, Log::SUBTYPE_CREATED, $logs);
        assertEquals($this->uid, $log['byuser']['id']);

        # Copy
        $this->log("Copy");
        $m = new StdMessage($this->dbhr, $this->dbhm);
        $sid1 = $m->create("TestStdMessage1", $id);
        $sid2 = $m->create("TestStdMessage2", $id);
        $m = new StdMessage($this->dbhr, $this->dbhm, $sid1);
        $m->setPrivate('action', 'Approve');
        $id2 = $c->create('TestConfig (Copy)', $this->user->getId(), $id);
        $this->log("Copied $id to $id2");
        $c = new ModConfig($this->dbhr, $this->dbhm, $id);
        $c2 = new ModConfig($this->dbhr, $this->dbhm, $id2);
        $oldatts = $c->getPublic();
        $this->log("Old " . var_export($oldatts, true));
        $newatts = $c2->getPublic();
        $this->log("New " . var_export($newatts, true));

        # Should have created a message order during the copy.
        assertNull($oldatts['messageorder']);
        assertNotNull($newatts['messageorder']);

        assertEquals('TestConfig (Copy)', $newatts['name']);
        unset($oldatts['id']);
        unset($oldatts['name']);
        unset($oldatts['messageorder']);
        unset($oldatts['stdmsgs']);
        unset($oldatts['bulkops']);

        foreach ($oldatts as $att => $val) {
            assertEquals($val, $newatts[$att]);
        }

        assertEquals('Approve', $newatts['stdmsgs'][0]['action']);

        # As support we should be able to see the config.
        $this->log("Check can see");
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $c = new ModConfig($this->dbhr, $this->dbhm, $id);
        $c->setPrivate('createdby', NULL);
        assertTrue($c->canSee());
        assertTrue($c->canModify());

        # Export and import
        $exp = $c->export();
        $this->log("Export $exp");
        $id = $c->import($exp);
        $pub = $c->getPublic();
        $this->log(var_export($pub, TRUE));
        assertEquals(2, count($pub['stdmsgs']));

        $c->delete();

        }

    public function testErrors() {
        $mock = $this->getMockBuilder('LoggedPDO')
            ->disableOriginalConstructor()
            ->setMethods(array('preExec', 'preQuery'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new Exception());

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $c->setDbhm($mock);
        $id = $c->create('TestConfig');
        assertNull($id);

        $mock->method('preQuery')->willThrowException(new Exception());
        $id = $c->create('TestConfig');
        assertNull($id);

        $c = new StdMessage($this->dbhr, $this->dbhm);
        $c->setDbhm($mock);
        $id = $c->create('TestStd', $id);
        assertNull($id);

        $c = new BulkOp($this->dbhr, $this->dbhm);
        $c->setDbhm($mock);
        $id = $c->create('TestStd', $id);
        assertNull($id);

        }

    public function testCC()
    {
        $c = new ModConfig($this->dbhr, $this->dbhm);
        $id = $c->create('TestConfig');
        assertNotNull($id);
        $m = new StdMessage($this->dbhr, $this->dbhm);
        $mid = $m->create("TestStdMessage", $id);
        assertNotNull($mid);

        $c->setPrivate('ccrejectto', 'Specific');
        $c->setPrivate('ccrejectaddr', 'test-specific-reject@test.com');
        $c->setPrivate('ccfollowupto', 'Specific');
        $c->setPrivate('ccfollowupaddr', 'test-specific-follow@test.com');
        assertEquals('test-specific-reject@test.com', $c->getBcc('Reject'));

        $m->setPrivate('action', 'Delete Approved Message');
        assertEquals('test-specific-follow@test.com', $c->getBcc('Delete Approved Message'));
        assertEquals(NULL, $c->getBcc('Delete Approved Member'));

        }
}


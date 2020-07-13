<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/user/MembershipCollection.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/config/ModConfig.php';


/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class groupTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup2';");
        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM users WHERE yahooid = '-testid1';");
        $this->dbhm->preExec("DELETE FROM users WHERE yahooid = '-testyahooid';");
        $this->dbhm->preExec("DELETE FROM users WHERE yahooUserId = '-testid1';");
        $this->dbhm->preExec("DELETE FROM users WHERE yahooUserId = '-testyahoouserid';");
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.backwards LIKE 'moctset%';");
        $dbhm->preExec("DELETE FROM users_emails WHERE users_emails.backwards LIKE 'moctset%';");
    }

    public function testDefaults() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $this->dbhm->preExec("UPDATE groups SET settings = NULL WHERE id = ?;", [ $gid ]);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $atts = $g->getPublic();

        assertEquals(1, $atts['settings']['duplicates']['check']);

        assertGreaterThan(0 ,$g->delete());
    }

    public function testBasic() {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $atts = $g->getPublic();
        assertEquals('testgroup', $atts['nameshort']);
        assertEquals($atts['id'], $g->getPrivate('id'));
        assertNull($g->getPrivate('invalidid'));

        assertGreaterThan(0 ,$g->delete());
    }

    public function testSplit() {
        $u = User::get($this->dbhm, $this->dbhm);
        $id = $u->create('Test', 'User', NULL);
        $u->setPrivate('yahooid', '-testyahooid');
        $u->setPrivate('yahooUserId', '-testyahoouserid');
        assertNotNull($u->addEmail('test@test.com'));
        $u->split('test@test.com');
        assertNotNull($u->findByEmail('test@test.com'));
        assertNull($u->findByYahooId('-testyahooid'));
        assertNull($u->findByYahooUserId('-testyahoouserid'));

        }

    public function testErrors() {
        $dbconfig = array (
            'host' => SQLHOST,
            'port_read' => SQLPORT_READ,
            'port_mod' => SQLPORT_MOD,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        # Create duplicate group
        $g = Group::get($this->dbhr, $this->dbhm);
        $id = $g->create('testgroup', Group::GROUP_REUSE);
        assertEquals($id, $g->findByShortName('TeStGrOuP'));
        assertNotNull($id);
        $id2 = $g->create('testgroup', Group::GROUP_REUSE);
        assertNull($id2);

        $mock = $this->getMockBuilder('LoggedPDO')
            ->setConstructorArgs([
                "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8",
                $dbconfig['user'], $dbconfig['pass'], array(), TRUE
            ])
            ->setMethods(array('lastInsertId'))
            ->getMock();
        $mock->method('lastInsertId')->willThrowException(new Exception());
        $g->setDbhm($mock);
        $id2 = $g->create('testgroup2', Group::GROUP_REUSE);
        assertNull($id2);

        $g = Group::get($this->dbhr, $this->dbhm);
        $id2 = $g->findByShortName('zzzz');
        assertNull($id2);

        # Test errors in set members
        $this->log("Set Members errors");
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $eid = $this->user->addEmail('test@test.com');
        $this->user->addMembership($id);
    }

    public function testVoucher() {
        $this->log(__METHOD__ );

        $g = Group::get($this->dbhr, $this->dbhm);
        $id = $g->create('testgroup', Group::GROUP_REUSE);
        assertNotNull($id);
        assertNull($g->getPrivate('licensed'));
        assertNull($g->getPrivate('licenseduntil'));

        $voucher = $g->createVoucher();
        assertNotNull($voucher);
        assertFalse($g->redeemVoucher('wibble'));
        assertTrue($g->redeemVoucher($voucher));
        $g = Group::get($this->dbhr, $this->dbhm, $id);
        assertNotNull($g->getPrivate('licensed'));
        assertNotNull($g->getPrivate('licenseduntil'));

        }

    public function testLegacy() {
        $this->log(__METHOD__ );

        $sql = "SELECT id, legacyid FROM groups WHERE legacyid IS NOT NULL AND legacyid NOT IN (SELECT id FROM groups);";
        $groups = $this->dbhr->preQuery($sql);
        foreach ($groups as $group) {
            $this->log("Get legacy {$group['legacyid']}");
            $g = Group::get($this->dbhr, $this->dbhm, $group['legacyid']);
            $this->log("Returned id " . $g->getId());
            assertEquals($group['id'], $g->getId());
        }

        # Might not be any legacy groups in the DB.
        assertTrue(TRUE);
    }

    public function testOurPS() {
        $this->log(__METHOD__ );

        $g = new Group($this->dbhr, $this->dbhm);

        self::assertEquals(NULL, $g->ourPS(NULL));
        self::assertEquals(Group::POSTING_DEFAULT, $g->ourPS(Group::POSTING_DEFAULT));
        self::assertEquals(Group::POSTING_DEFAULT, $g->ourPS(Group::POSTING_UNMODERATED));
        self::assertEquals(Group::POSTING_PROHIBITED, $g->ourPS(Group::POSTING_PROHIBITED));
        self::assertEquals(Group::POSTING_MODERATED, $g->ourPS(Group::POSTING_MODERATED));

        }
}


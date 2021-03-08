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
class groupTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup2';");
        $this->dbhm->exec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM users WHERE yahooid = '-testid1';");
        $this->dbhm->preExec("DELETE FROM users WHERE yahooid = '-testyahooid';");
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec(
            "DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.backwards LIKE 'moctset%';"
        );
        $dbhm->preExec("DELETE FROM users_emails WHERE users_emails.backwards LIKE 'moctset%';");
    }

    public function testDefaults()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $this->dbhm->preExec("UPDATE groups SET settings = NULL WHERE id = ?;", [$gid]);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $atts = $g->getPublic();

        assertEquals(1, $atts['settings']['duplicates']['check']);

        assertGreaterThan(0, $g->delete());
    }

    public function testBasic()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $atts = $g->getPublic();
        assertEquals('testgroup', $atts['nameshort']);
        assertEquals($atts['id'], $g->getPrivate('id'));
        assertNull($g->getPrivate('invalidid'));

        assertGreaterThan(0, $g->delete());
    }

    public function testErrors()
    {
        global $dbconfig;

        # Create duplicate group
        $g = Group::get($this->dbhr, $this->dbhm);
        $id = $g->create('testgroup', Group::GROUP_REUSE);
        assertEquals($id, $g->findByShortName('TeStGrOuP'));
        assertNotNull($id);
        $id2 = $g->create('testgroup', Group::GROUP_REUSE);
        assertNull($id2);

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs(
                [$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], true]
            )
            ->setMethods(array('lastInsertId'))
            ->getMock();
        $mock->method('lastInsertId')->willThrowException(new \Exception());
        $g->setDbhm($mock);
        $id2 = $g->create('testgroup2', Group::GROUP_REUSE);
        assertNull($id2);

        $g = Group::get($this->dbhr, $this->dbhm);
        $id2 = $g->findByShortName('zzzz');
        assertNull($id2);

        # Test errors in set members
        $this->log("Set Members errors");
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(null, null, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $eid = $this->user->addEmail('test@test.com');
        $this->user->addMembership($id);
    }

    public function testLegacy()
    {
        $this->log(__METHOD__);

        $sql = "SELECT id, legacyid FROM groups WHERE legacyid IS NOT NULL AND legacyid NOT IN (SELECT id FROM groups);";
        $groups = $this->dbhr->preQuery($sql);
        foreach ($groups as $group) {
            $this->log("Get legacy {$group['legacyid']}");
            $g = Group::get($this->dbhr, $this->dbhm, $group['legacyid']);
            $this->log("Returned id " . $g->getId());
            assertEquals($group['id'], $g->getId());
        }

        # Might not be any legacy groups in the DB.
        assertTrue(true);
    }

    public function testOurPS()
    {
        $this->log(__METHOD__);

        $g = new Group($this->dbhr, $this->dbhm);

        self::assertEquals(null, $g->ourPS(null));
        self::assertEquals(Group::POSTING_DEFAULT, $g->ourPS(Group::POSTING_DEFAULT));
        self::assertEquals(Group::POSTING_DEFAULT, $g->ourPS(Group::POSTING_UNMODERATED));
        self::assertEquals(Group::POSTING_PROHIBITED, $g->ourPS(Group::POSTING_PROHIBITED));
        self::assertEquals(Group::POSTING_MODERATED, $g->ourPS(Group::POSTING_MODERATED));
    }

    public function testList()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);
        $g->setPrivate('contactmail', 'test@test.com');
        assertEquals('test@test.com', $g->getModsEmail());
        assertEquals('test@test.com', $g->getAutoEmail());
        $groups = $g->listByType(Group::GROUP_UT, true, false);

        $found = false;
        foreach ($groups as $group) {
            if (strcmp($group['modsmail'], 'test@test.com') === 0) {
                $found = true;
            }
        }

        assertTrue($found);
    }

    public function testWelcomeReview()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);

        assertEquals(0, $g->welcomeReview($gid, 1));
        $g->setPrivate('welcomemail', 'UT');

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(null, null, 'Test User');
        $u->addMembership($gid, User::ROLE_MODERATOR);
        assertEquals(0, $g->welcomeReview($gid, 1));

        $u->addEmail('test@test.com');
        assertEquals(1, $g->welcomeReview($gid, 1));
    }

    public function testBadPoly()
    {
        $g = Group::get($this->dbhr, $this->dbhm);
        $g->create('testgroup', Group::GROUP_UT);
        assertFalse($g->setPrivate('poly', 'POLYGON((-0.4473592541309017 51.68697551043059,-0.446920394897461 51.68772267915941,-0.44700622558593756 51.689425382919424,-0.44674873352050787 51.69086198940086,-0.446920394897461 51.69166008441168,-0.44683456420898443 51.6905427474563,-0.4471778869628906 51.68921254845188,-0.4466629028320313 51.68915933967866,-0.44631958007812506 51.688095151079544,-0.44700622558593756 51.68990425681258,-0.4472637176513672 51.69299021149185,-0.4464912414550782 51.69437350219,-0.44700622558593756 51.69565034838671,-0.44631958007812506 51.69735275394609,-0.44631958007812506 51.69852312061961,-0.44547097898441734 51.69997340066214,-0.42828761021485207 51.711349973683,-0.4172840510155993 51.71357763415542,-0.3849772500000199 51.71682179,-0.37603363232415177 51.70334614643072,-0.36056688232417855 51.69518697265063,-0.352890149638597 51.699338647030686,-0.34287197372555056 51.69694579130272,-0.33374301272215234 51.69894126690024,-0.33947821483764073 51.69355498548889,-0.3292995512470043 51.69064877666337,-0.3345704115820354 51.68561391239537,-0.33817690050784677 51.680514587498415,-0.3358602719238206 51.6743454716388,-0.3381351851709269 51.669983064362114,-0.33068957294676693 51.66503345550613,-0.3360878569903889 51.659106293204054,-0.32267454958332564 51.64303266628124,-0.3387869990121999 51.64826109055636,-0.34697930509651087 51.654456363632086,-0.3598358097071923 51.66262115608032,-0.3725025542801177 51.670856042571444,-0.38547642428534346 51.670716459817164,-0.3832716984753688 51.667030393173455,-0.38641534744147066 51.66306481435677,-0.384358610937511 51.64689595541792,-0.3795584924218929 51.64608147500487,-0.37166526910164066 51.6451948876468,-0.36995185535158726 51.641538599944255,-0.37613806496096913 51.63763450638031,-0.3690101545116704 51.62970867905037,-0.3655582319573796 51.62675598155824,-0.37103270100465124 51.62550819879922,-0.36721876068111214 51.62130732962477,-0.37895399153126164 51.61915435647963,-0.3838227673033998 51.61060560101222,-0.39137375209043057 51.61664681175795,-0.39961138238527383 51.61330775433955,-0.40265414522832543 51.618898841946674,-0.4037228022363024 51.62438267110494,-0.41285092842406357 51.62470895311864,-0.41356654990636343 51.626349066142495,-0.41419634070007305 51.628948161911914,-0.41508362355921236 51.63093442492195,-0.41528426091065285 51.63297387581582,-0.42092340208012047 51.636130125416805,-0.44667663193661156 51.63860196261842,-0.44290410495716515 51.64320422734749,-0.4339857599826473 51.64346013775833,-0.4374350807757992 51.64823270628105,-0.4278542301746029 51.65266446210444,-0.4272319576831478 51.67004447785799,-0.43560044980961266 51.67243316811328,-0.43997781492191734 51.6835964622445,-0.4473592541309017 51.68697551043059))'));
        assertTrue($g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.6, 179.1 8.6, 179.1 8.3))'));
    }
}

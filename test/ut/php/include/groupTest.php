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

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->exec("DELETE FROM `groups` WHERE nameshort = 'testgroup2';");
        $this->dbhm->exec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
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
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $this->dbhm->preExec("UPDATE `groups` SET settings = NULL WHERE id = ?;", [$gid]);
        $g = Group::get($this->dbhr, $this->dbhm, $gid);
        $atts = $g->getPublic();

        $this->assertEquals(1, $atts['settings']['duplicates']['check']);

        $this->assertGreaterThan(0, $g->delete());
    }

    public function testBasic()
    {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $atts = $g->getPublic();
        $this->assertEquals('testgroup', $atts['nameshort']);
        $this->assertEquals($atts['id'], $g->getPrivate('id'));
        $this->assertNull($g->getPrivate('invalidid'));

        $this->assertGreaterThan(0, $g->delete());
    }

    public function testErrors()
    {
        global $dbconfig;

        # Create duplicate group
        list($g, $id) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $this->assertEquals($id, $g->findByShortName('TeStGrOuP'));
        $this->assertNotNull($id);
        $id2 = $g->create('testgroup', Group::GROUP_REUSE);
        $this->assertNull($id2);

        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs(
                [$dbconfig['hosts_read'], $dbconfig['database'], $dbconfig['user'], $dbconfig['pass'], TRUE]
            )
            ->setMethods(array('lastInsertId'))
            ->getMock();
        $mock->method('lastInsertId')->willThrowException(new \Exception());
        $g->setDbhm($mock);
        $id2 = $g->create('testgroup2', Group::GROUP_REUSE);
        $this->assertNull($id2);

        $g = Group::get($this->dbhr, $this->dbhm);
        $id2 = $g->findByShortName('zzzz');
        $this->assertNull($id2);

        # Test errors in set members
        $this->log("Set Members errors");
        list($this->user, $this->uid, $eid) = $this->createTestUser(null, null, 'Test User', 'test@test.com', 'testpw');
        $this->user->addMembership($id);
    }

    public function testLegacy()
    {
        $this->log(__METHOD__);

        $sql = "SELECT id, legacyid FROM `groups` WHERE legacyid IS NOT NULL AND legacyid NOT IN (SELECT id FROM `groups`);";
        $groups = $this->dbhr->preQuery($sql);
        foreach ($groups as $group) {
            $this->log("Get legacy {$group['legacyid']}");
            $g = Group::get($this->dbhr, $this->dbhm, $group['legacyid']);
            $this->log("Returned id " . $g->getId());
            $this->assertEquals($group['id'], $g->getId());
        }

        # Might not be any legacy groups in the DB.
        $this->assertTrue(TRUE);
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
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_UT);
        $g->setPrivate('contactmail', 'test@test.com');
        $this->assertEquals('test@test.com', $g->getModsEmail());
        $this->assertEquals('test@test.com', $g->getAutoEmail());
        $groups = $g->listByType(Group::GROUP_UT, TRUE);

        $found = FALSE;
        foreach ($groups as $group) {
            if (strcmp($group['modsmail'], 'test@test.com') === 0) {
                $found = TRUE;
            }
        }

        $this->assertTrue($found);
    }

    public function testWelcomeReview()
    {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_UT);

        $this->assertEquals(0, $g->welcomeReview($gid, 1));
        $g->setPrivate('welcomemail', 'UT');

        list($u, $uid, $emailid) = $this->createTestUser(null, null, 'Test User', 'testwelcome@test.com', 'testpw');
        $u->addMembership($gid, User::ROLE_MODERATOR);
        $this->assertEquals(0, $g->welcomeReview($gid, 1));

        $u->addEmail('test@test.com');
        $this->assertEquals(1, $g->welcomeReview($gid, 1));
    }

    public function testBadPoly()
    {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_UT);
        $this->assertFalse($g->setPrivate('poly', 'POLYGON((-0.446920394897461 51.68772267915941,-0.44700622558593756 51.689425382919424,-0.44674873352050787 51.69086198940086,-0.446920394897461 51.69166008441168,-0.44683456420898443 51.6905427474563,-0.4471778869628906 51.68921254845188,-0.4466629028320313 51.68915933967866,-0.44631958007812506 51.688095151079544,-0.44700622558593756 51.68990425681258,-0.4472637176513672 51.69299021149185,-0.4464912414550782 51.69437350219,-0.44700622558593756 51.69565034838671,-0.44631958007812506 51.69735275394609,-0.44631958007812506 51.69852312061961,-0.44547097898441734 51.69997340066214,-0.42828761021485207 51.711349973683,-0.4172840510155993 51.71357763415542,-0.3849772500000199 51.71682179,-0.37603363232415177 51.70334614643072,-0.36056688232417855 51.69518697265063,-0.352890149638597 51.699338647030686,-0.34287197372555056 51.69694579130272,-0.33374301272215234 51.69894126690024,-0.33947821483764073 51.69355498548889,-0.3292995512470043 51.69064877666337,-0.3345704115820354 51.68561391239537,-0.33817690050784677 51.680514587498415,-0.3358602719238206 51.6743454716388,-0.3381351851709269 51.669983064362114,-0.33068957294676693 51.66503345550613,-0.3360878569903889 51.659106293204054,-0.32267454958332564 51.64303266628124,-0.3387869990121999 51.64826109055636,-0.34697930509651087 51.654456363632086,-0.3598358097071923 51.66262115608032,-0.3725025542801177 51.670856042571444,-0.38547642428534346 51.670716459817164,-0.3832716984753688 51.667030393173455,-0.38641534744147066 51.66306481435677,-0.384358610937511 51.64689595541792,-0.3795584924218929 51.64608147500487,-0.37166526910164066 51.6451948876468,-0.36995185535158726 51.641538599944255,-0.37613806496096913 51.63763450638031,-0.3690101545116704 51.62970867905037,-0.3655582319573796 51.62675598155824,-0.37103270100465124 51.62550819879922,-0.36721876068111214 51.62130732962477,-0.37895399153126164 51.61915435647963,-0.3838227673033998 51.61060560101222,-0.39137375209043057 51.61664681175795,-0.39961138238527383 51.61330775433955,-0.40265414522832543 51.618898841946674,-0.4037228022363024 51.62438267110494,-0.41285092842406357 51.62470895311864,-0.41356654990636343 51.626349066142495,-0.41419634070007305 51.628948161911914,-0.41508362355921236 51.63093442492195,-0.41528426091065285 51.63297387581582,-0.42092340208012047 51.636130125416805,-0.44667663193661156 51.63860196261842,-0.44290410495716515 51.64320422734749,-0.4339857599826473 51.64346013775833,-0.4374350807757992 51.64823270628105,-0.4278542301746029 51.65266446210444,-0.4272319576831478 51.67004447785799,-0.43560044980961266 51.67243316811328,-0.43997781492191734 51.6835964622445,-0.4473592541309017 51.68697551043059))'));
        $this->assertTrue($g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.6, 179.1 8.6, 179.1 8.3))'));
        $this->assertFalse($g->setPrivate('polyofficial', 'POLYGON((-1.3047601 50.815345799999996,-1.3079132 50.8193768,-1.3187062 50.822810900000015,-1.3297386 50.83211829999999,-1.3397933 50.84482239999999,-1.3340553 50.848953900000005,-1.3991159999999998 50.881870000000006,-1.4217535 50.89651429999999,-1.4407156999999997 50.904537599999976,-1.4469765000000003 50.90238329999999,-1.4515286 50.90260269999998,-1.4670681 50.9070703,-1.4749637 50.9211833,-1.4791603 50.92507080000002,-1.4757711000000002 50.928122799999976,-1.4807399999999997 50.92868699999999,-1.4818 50.93112099999999,-1.480922 50.929464999999986,-1.482498 50.92892599999999,-1.481011 50.928305,-1.482834 50.928225999999995,-1.4873936 50.9313161,-1.485306 50.93246600000002,-1.4888319 50.9344287,-1.4904508999999997 50.93326300000001,-1.5002859999999998 50.93447100000001,-1.503138 50.936174,-1.5076899999999998 50.94059200000001,-1.5081789 50.94661159999998,-1.5106783 50.9483631,-1.5102801 50.94977349999999,-1.521561 50.952173,-1.524194 50.950437999999984,-1.5311049999999997 50.95118200000003,-1.534406 50.954474999999995,-1.534413 50.959095999999974,-1.539479 50.96139600000001,-1.541495 50.96650600000001,-1.545023 50.96894700000002,-1.55603 50.96568900000001,-1.561596 50.965854,-1.5757039999999998 50.960721000000014,-1.584044 50.96200399999999,-1.5840446 50.95910820000002,-1.590105 50.951209000000006,-1.590924 50.95329400000001,-1.595592 50.953693999999984,-1.596518 50.95601699999999,-1.607768 50.954907,-1.6128050000000003 50.95804399999999,-1.619505 50.95853299999999,-1.623422 50.954583000000014,-1.6348580000000001 50.95918399999999,-1.6469005000000003 50.94899839999999,-1.6616830000000002 50.94522400000001,-1.6759866999999997 50.94901509999998,-1.689435 50.954643,-1.70105 50.96267199999999,-1.7098057000000002 50.97113629999999,-1.7196409999999998 50.97672699999997,-1.7338335 50.975978900000015,-1.7544260000000003 50.97783900000002,-1.755111 50.98057,-1.799933 50.99124,-1.8078930000000002 50.991749000000006,-1.8152549999999998 50.986009999999986,-1.827106 50.997003000000014,-1.8356939999999997 51.00915800000001,-1.8534060000000003 51.004626,-1.8740089999999998 50.984387000000005,-1.873964 51.00601599999995,-1.8853060000000001 51.000197,-1.927611 50.99761799999998,-1.9499620000000002 50.982257000000004,-1.955755 50.989301999999995,-1.957259 50.98710199999997,-1.9551400000000003 50.978036,-1.945069 50.975784000000004,-1.9391439999999998 50.96955199999999,-1.9299150000000003 50.96774199999999,-1.9276760000000002 50.964047999999984,-1.921538 50.96165499999999,-1.91638 50.95340899999998,-1.9125409999999998 50.952176000000016,-1.908332 50.944751,-1.8991119999999997 50.93833799999998,-1.898071 50.935413999999994,-1.8788280000000002 50.924271000000005,-1.8736959999999998 50.917491999999996,-1.8638210000000002 50.91924000000001,-1.8562789999999998 50.924399,-1.855763 50.92654099999997,-1.840469 50.93180899999999,-1.82592 50.926809999999996,-1.8105709999999997 50.926812000000005,-1.814586 50.923269999999995,-1.820431 50.91205200000004,-1.816618 50.90398899999999,-1.824977 50.89571599999999,-1.839894 50.897835999999984,-1.84858 50.88983299999999,-1.8440760000000003 50.886855,-1.848002 50.88228499999999,-1.845297 50.877943,-1.8485050000000003 50.87372400000001,-1.8478570000000003 50.86963199999999,-1.8534890000000002 50.86652300000001,-1.851682 50.86414,-1.8533450000000002 50.862869999999994,-1.8504799999999997 50.859999000000016,-1.850969 50.85867,-1.830033 50.85521900000002,-1.814856 50.85864700000001,-1.814769 50.861997,-1.8129480000000002 50.862276999999985,-1.812814 50.864518,-1.8080510000000003 50.864405000000005,-1.8063689999999997 50.8615424,-1.8071980000000003 50.86008399999999,-1.80511 50.85895500000001,-1.803324 50.853965000000024,-1.805882 50.852993,-1.7999660000000002 50.84839499999998,-1.8036649999999999 50.845158000000005,-1.8018970000000003 50.842365999999984,-1.796933 50.84090800000002,-1.8003243999999998 50.84071840000002,-1.7985292000000002 50.838907199999994,-1.7905139999999997 50.836592,-1.7921448000000002 50.833438,-1.7964707999999998 50.833014399999996,-1.7943114 50.8308736,-1.803425 50.830285,-1.8044631 50.8268679,-1.8029691999999997 50.8253076,-1.8046769 50.82393919999997,-1.803873 50.822042,-1.8014235 50.82231430000003,-1.8016778 50.8206889,-1.8071280999999997 50.816254399999984,-1.8026996 50.81352609999999,-1.8117157000000002 50.80856660000001,-1.8099622 50.804978900000016,-1.804956 50.80357800000002,-1.804623 50.80033,-1.8007162 50.79982300000001,-1.8030727 50.79802449999998,-1.8027569999999997 50.79621499999999,-1.804603 50.795852000000004,-1.801002 50.79435100000003,-1.803369 50.79210299999999,-1.806087 50.79211800000002,-1.801382 50.791878999999994,-1.802358 50.79037199999999,-1.7991289999999998 50.786004999999975,-1.7957010000000002 50.78584699999999,-1.797591 50.784027000000016,-1.7918745999999999 50.782955100000024,-1.791267 50.78061300000003,-1.78793 50.778861,-1.7905239999999998 50.776478999999995,-1.790324 50.774923,-1.788342 50.774438999999994,-1.790289 50.770988999999986,-1.7873580000000002 50.77052100000002,-1.7888580000000003 50.76777899999999,-1.7850829999999998 50.76479699999998,-1.7792753 50.76754600000002,-1.7764400000000002 50.76635500000001,-1.768982 50.769704,-1.770159 50.772479000000004,-1.7578120000000002 50.777935,-1.7481187 50.77886469999999,-1.745712 50.77620200000002,-1.748295 50.77520899999998,-1.7448089999999998 50.77306299999999,-1.738971 50.763090000000005,-1.7417070000000001 50.760285,-1.742039 50.756613000000016,-1.744517 50.75611700000001,-1.742356 50.74919399999999,-1.7441920000000002 50.747401,-1.717587 50.75210199999999,-1.681841 50.75179399999999,-1.6845050000000001 50.74385899999999,-1.6922152 50.73813669999999,-1.6463184 50.733960599999996,-1.5851312000000002 50.71973890000001,-1.5647079 50.71022810000001,-1.5504116 50.707826100000005,-1.5598282 50.71533719999998,-1.5476715 50.7264433,-1.5173060000000003 50.74325029999999,-1.4922544 50.75342260000001,-1.4512333 50.76295679999999,-1.4210893999999998 50.7671974,-1.37509 50.78574309999998,-1.3518806 50.78513070000001,-1.3428330000000002 50.78663230000001,-1.3281557 50.80248280000002,-1.3047601 50.815345799999996))'));
    }

    /**
     * @dataProvider popularProvider
     */
    public function testPopular($share) {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $g->setPrivate('polyofficial', 'POLYGON((179.25 8.5, 179.27 8.5, 179.27 8.6, 179.2 8.6, 179.25 8.5))');

        list($u, $uid, $emailid) = $this->createTestUser('Test', 'User', 'Test User', 'test@test.com', 'testpw');
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment'));
        $msg = str_replace("FreeglePlayground", "testgroup", $msg);
        $msg = str_replace('Basic test', 'OFFER: Test item (TV13)', $msg);
        $msg = str_replace("Hey", "Hey {{username}}", $msg);

        $r = new MailRouter($this->dbhm, $this->dbhm);
        list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg, $gid);

        $r->setLatLng(8.55, 179.26);

        $this->assertNotNull($id);
        $this->log("Created message $id");
        $rc = $r->route();
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $this->assertEquals([], $g->getPopularMessages($gid));

        # No views - no popular messages.
        $g->findPopularMessages();
        $this->assertEquals([], $g->getPopularMessages($gid));

        # Add a message but no Facebook links.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->like($m->getFromuser(), Message::LIKE_VIEW);
        $this->waitBackground();
        $g->findPopularMessages();
        $this->assertEquals([], $g->getPopularMessages($gid));

        # Add a Facebook link.
        $gf = new GroupFacebook($this->dbhr, $this->dbhm);
        $gf->add($gid, 'UT', 'UT', 1);
        $popid = $g->getPopularMessages($gid)[0]['msgid'];
        $this->assertEquals($id, $popid);

        if ($share) {
            $g->sharedPopularMessage($popid);
        } else {
            $g->hidPopularMessage($popid);
        }

        # Shouldn't show now.
        $this->assertEquals([], $g->getPopularMessages($gid));
    }

    public function popularProvider() {
        return [
            [ TRUE ],
            [ FALSE ]
        ];
    }
}

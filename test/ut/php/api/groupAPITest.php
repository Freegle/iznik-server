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
class groupAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test';");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup2';");

        # Create a moderator
        list($this->group, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        list($this->user, $this->uid) = $this->createTestUserWithMembership($this->groupid, User::ROLE_MEMBER, 'Test User', 'test@test.com', 'testpw');
    }

    protected function tearDown() : void {
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");

        parent::tearDown ();
    }

    public function testCreate()
    {
        # Not logged in - should fail
        $ret = $this->call('group', 'POST', [
            'action' => 'Create',
            'grouptype' => 'Reuse',
            'name' => 'testgroup'
        ]);
        $this->assertEquals(1, $ret['ret']);

        # Logged in - not mod, can't create
        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('group', 'POST', [
            'action' => 'Create',
            'grouptype' => 'Reuse',
            'name' => 'testgroup2'
        ]);

        $this->assertEquals(1, $ret['ret']);
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

        $ret = $this->call('group', 'POST', [
            'action' => 'Create',
            'grouptype' => 'Reuse',
            'name' => 'testgroup3'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);

        # Should be owner.
        $ret = $this->call('group', 'GET', [
            'id' => $ret['id'],
            'members' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(User::ROLE_OWNER, $ret['group']['myrole']);
        $this->assertEquals(1, count($ret['group']['members']));
        $this->assertEquals($this->uid, $ret['group']['members'][0]['userid']);
        $this->assertEquals(User::ROLE_OWNER, $ret['group']['members'][0]['role']);
    }

    public function testGet() {
        # Not logged in - shouldn't see members list
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->groupid, $ret['group']['id']);
        $this->assertFalse(Utils::pres('members', $ret['group']));

        # By short name
        $ret = $this->call('group', 'GET', [
            'id' => 'testgroup',
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($this->groupid, $ret['group']['id']);
        $this->assertFalse(Utils::pres('members', $ret['group']));

        # Duff shortname
        $ret = $this->call('group', 'GET', [
            'id' => 'testinggroup',
            'members' => TRUE
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Member - shouldn't see members list
        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('members', $ret['group']));

        # Moderator - should see members list
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->log("Members " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals(1, count($ret['group']['members']));
        $this->assertEquals('test@test.com', $ret['group']['members'][0]['email']);

        }

    public function testPatch() {
        # Not logged in - shouldn't be able to set
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'settings' => [
                'mapzoom' => 12
            ]
        ]);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('members', $ret));

        # Member - shouldn't either
        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'settings' => [
                'mapzoom' => 12
            ]
        ]);
        $this->assertEquals(1, $ret['ret']);
        $this->assertFalse(Utils::pres('members', $ret));

        # Owner - should be able to
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'settings' => [
                'mapzoom' => 12
            ]
        ]);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid
        ]);
        $this->assertEquals(12, $ret['group']['settings']['mapzoom']);

        # Support attributes
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $_SESSION['supportAllowed'] = TRUE;
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'lat' => 10
        ]);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid
        ]);
        $this->assertEquals(10, $ret['group']['lat']);

        # Valid and invalid polygon
        $polystr = 'POLYGON((59.58984375 9.102096738726456,54.66796875 -5.0909441750333855,65.7421875 -6.839169626342807,76.2890625 -4.740675384778361,74.8828125 6.4899833326706515,59.58984375 9.102096738726456))';
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'poly' => $polystr
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Check we can see it.
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'polygon' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Simplified val is different.
        $simpstr = 'POLYGON((76.2890625 -4.740675384778361,74.8828125 6.4899833326706515,59.58984375 9.102096738726456,54.66796875 -5.0909441750333855,65.7421875 -6.839169626342807,76.2890625 -4.740675384778361))';
        $this->assertEquals($simpstr, $ret['group']['polygon']);

        # Invalid polygon
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'poly' => 'POLYGON((59.58984375 9.102096738726456,54.66796875 -5.0909441750333855,65.7421875 -6.839169626342807,76.2890625 -4.740675384778361,74.8828125 6.4899833326706515,59.58984375 9.102096738726456)))'
        ]);
        $this->assertEquals(3, $ret['ret']);

        # Shouldn't have changed.
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'polygon' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($simpstr, $ret['group']['polygon']);

        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'poly' => 'POLYGON((-1.3047601 50.815345799999996,-1.3079132 50.8193768,-1.3187062 50.822810900000015,-1.3297386 50.83211829999999,-1.3397933 50.84482239999999,-1.3340553 50.848953900000005,-1.3991159999999998 50.881870000000006,-1.4217535 50.89651429999999,-1.4407156999999997 50.904537599999976,-1.4469765000000003 50.90238329999999,-1.4515286 50.90260269999998,-1.4670681 50.9070703,-1.4749637 50.9211833,-1.4791603 50.92507080000002,-1.4757711000000002 50.928122799999976,-1.4807399999999997 50.92868699999999,-1.4818 50.93112099999999,-1.480922 50.929464999999986,-1.482498 50.92892599999999,-1.481011 50.928305,-1.482834 50.928225999999995,-1.4873936 50.9313161,-1.485306 50.93246600000002,-1.4888319 50.9344287,-1.4904508999999997 50.93326300000001,-1.5002859999999998 50.93447100000001,-1.503138 50.936174,-1.5076899999999998 50.94059200000001,-1.5081789 50.94661159999998,-1.5106783 50.9483631,-1.5102801 50.94977349999999,-1.521561 50.952173,-1.524194 50.950437999999984,-1.5311049999999997 50.95118200000003,-1.534406 50.954474999999995,-1.534413 50.959095999999974,-1.539479 50.96139600000001,-1.541495 50.96650600000001,-1.545023 50.96894700000002,-1.55603 50.96568900000001,-1.561596 50.965854,-1.5757039999999998 50.960721000000014,-1.584044 50.96200399999999,-1.5840446 50.95910820000002,-1.590105 50.951209000000006,-1.590924 50.95329400000001,-1.595592 50.953693999999984,-1.596518 50.95601699999999,-1.607768 50.954907,-1.6128050000000003 50.95804399999999,-1.619505 50.95853299999999,-1.623422 50.954583000000014,-1.6348580000000001 50.95918399999999,-1.6469005000000003 50.94899839999999,-1.6616830000000002 50.94522400000001,-1.6759866999999997 50.94901509999998,-1.689435 50.954643,-1.70105 50.96267199999999,-1.7098057000000002 50.97113629999999,-1.7196409999999998 50.97672699999997,-1.7338335 50.975978900000015,-1.7544260000000003 50.97783900000002,-1.755111 50.98057,-1.799933 50.99124,-1.8078930000000002 50.991749000000006,-1.8152549999999998 50.986009999999986,-1.827106 50.997003000000014,-1.8356939999999997 51.00915800000001,-1.8534060000000003 51.004626,-1.8740089999999998 50.984387000000005,-1.873964 51.00601599999995,-1.8853060000000001 51.000197,-1.927611 50.99761799999998,-1.9499620000000002 50.982257000000004,-1.955755 50.989301999999995,-1.957259 50.98710199999997,-1.9551400000000003 50.978036,-1.945069 50.975784000000004,-1.9391439999999998 50.96955199999999,-1.9299150000000003 50.96774199999999,-1.9276760000000002 50.964047999999984,-1.921538 50.96165499999999,-1.91638 50.95340899999998,-1.9125409999999998 50.952176000000016,-1.908332 50.944751,-1.8991119999999997 50.93833799999998,-1.898071 50.935413999999994,-1.8788280000000002 50.924271000000005,-1.8736959999999998 50.917491999999996,-1.8638210000000002 50.91924000000001,-1.8562789999999998 50.924399,-1.855763 50.92654099999997,-1.840469 50.93180899999999,-1.82592 50.926809999999996,-1.8105709999999997 50.926812000000005,-1.814586 50.923269999999995,-1.820431 50.91205200000004,-1.816618 50.90398899999999,-1.824977 50.89571599999999,-1.839894 50.897835999999984,-1.84858 50.88983299999999,-1.8440760000000003 50.886855,-1.848002 50.88228499999999,-1.845297 50.877943,-1.8485050000000003 50.87372400000001,-1.8478570000000003 50.86963199999999,-1.8534890000000002 50.86652300000001,-1.851682 50.86414,-1.8533450000000002 50.862869999999994,-1.8504799999999997 50.859999000000016,-1.850969 50.85867,-1.830033 50.85521900000002,-1.814856 50.85864700000001,-1.814769 50.861997,-1.8129480000000002 50.862276999999985,-1.812814 50.864518,-1.8080510000000003 50.864405000000005,-1.8063689999999997 50.8615424,-1.8071980000000003 50.86008399999999,-1.80511 50.85895500000001,-1.803324 50.853965000000024,-1.805882 50.852993,-1.7999660000000002 50.84839499999998,-1.8036649999999999 50.845158000000005,-1.8018970000000003 50.842365999999984,-1.796933 50.84090800000002,-1.8003243999999998 50.84071840000002,-1.7985292000000002 50.838907199999994,-1.7905139999999997 50.836592,-1.7921448000000002 50.833438,-1.7964707999999998 50.833014399999996,-1.7943114 50.8308736,-1.803425 50.830285,-1.8044631 50.8268679,-1.8029691999999997 50.8253076,-1.8046769 50.82393919999997,-1.803873 50.822042,-1.8014235 50.82231430000003,-1.8016778 50.8206889,-1.8071280999999997 50.816254399999984,-1.8026996 50.81352609999999,-1.8117157000000002 50.80856660000001,-1.8099622 50.804978900000016,-1.804956 50.80357800000002,-1.804623 50.80033,-1.8007162 50.79982300000001,-1.8030727 50.79802449999998,-1.8027569999999997 50.79621499999999,-1.804603 50.795852000000004,-1.801002 50.79435100000003,-1.803369 50.79210299999999,-1.806087 50.79211800000002,-1.801382 50.791878999999994,-1.802358 50.79037199999999,-1.7991289999999998 50.786004999999975,-1.7957010000000002 50.78584699999999,-1.797591 50.784027000000016,-1.7918745999999999 50.782955100000024,-1.791267 50.78061300000003,-1.78793 50.778861,-1.7905239999999998 50.776478999999995,-1.790324 50.774923,-1.788342 50.774438999999994,-1.790289 50.770988999999986,-1.7873580000000002 50.77052100000002,-1.7888580000000003 50.76777899999999,-1.7850829999999998 50.76479699999998,-1.7792753 50.76754600000002,-1.7764400000000002 50.76635500000001,-1.768982 50.769704,-1.770159 50.772479000000004,-1.7578120000000002 50.777935,-1.7481187 50.77886469999999,-1.745712 50.77620200000002,-1.748295 50.77520899999998,-1.7448089999999998 50.77306299999999,-1.738971 50.763090000000005,-1.7417070000000001 50.760285,-1.742039 50.756613000000016,-1.744517 50.75611700000001,-1.742356 50.74919399999999,-1.7441920000000002 50.747401,-1.717587 50.75210199999999,-1.681841 50.75179399999999,-1.6845050000000001 50.74385899999999,-1.6922152 50.73813669999999,-1.6463184 50.733960599999996,-1.5851312000000002 50.71973890000001,-1.5647079 50.71022810000001,-1.5504116 50.707826100000005,-1.5598282 50.71533719999998,-1.5476715 50.7264433,-1.5173060000000003 50.74325029999999,-1.4922544 50.75342260000001,-1.4512333 50.76295679999999,-1.4210893999999998 50.7671974,-1.37509 50.78574309999998,-1.3518806 50.78513070000001,-1.3428330000000002 50.78663230000001,-1.3281557 50.80248280000002,-1.3047601 50.815345799999996))'
        ]);
        $this->assertEquals(3, $ret['ret']);

        # Profile
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        list ($attid, $uid) = $a->create(NULL, $data);
        $this->assertNotNull($attid);

        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'profile' => $attid,
            'tagline' => 'Test slogan'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'pending' => TRUE
        ]);
        $this->assertNotFalse(strpos($ret['group']['profile'], $attid));
        $this->assertEquals('Test slogan', $ret['group']['tagline']);

        # Null polygon
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'poly' => ''
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'polygon' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNull($ret['group']['polygon']);
    }

    public function testConfirmMod() {
        $ret = $this->call('group', 'POST', [
            'action' => 'ConfirmKey',
            'id' => $this->groupid
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $key = $ret['key'];

        # And again but with support status so it goes through.
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $this->addLoginAndLogin($this->user, 'testpw');
        $_SESSION['supportAllowed'] = TRUE;

        $ret = $this->call('group', 'POST', [
            'action' => 'ConfirmKey',
            'dup' => TRUE,
            'id' => $this->groupid
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(100, $ret['ret']);

        }

    public function testList() {
        $ret = $this->call('groups', 'GET', [
            'grouptype' => 'Freegle'
        ]);
        $this->assertEquals(0, $ret['ret']);

        }

    public function testShowmods() {
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'showmods' => TRUE
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertFalse(Utils::pres('showmods', $ret['group']));

        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('session', 'PATCH', [
            'settings' => [
                'showmod' => TRUE
            ]
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->log("Settings after patch for {$this->uid} " . $this->user->getPrivate('settings'));

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'showmods' => TRUE
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertTrue(array_key_exists('showmods', $ret['group']));
        $this->assertEquals(1, count($ret['group']['showmods']));
        $this->assertEquals($this->uid, $ret['group']['showmods'][0]['id']);

        }

    public function testAffiliation() {
        $this->addLoginAndLogin($this->user, 'testpw');

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $confdate = Utils::ISODate('@' . time());

        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'affiliationconfirmed' => $confdate
        ]);

        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'affiliationconfirmedby' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);

        $this->assertEquals($this->uid, $ret['group']['affiliationconfirmedby']['id']);
        $this->assertEquals($confdate, $ret['group']['affiliationconfirmed']);
    }

    public function testLastActive() {
        # Approve a message onto the group.
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        list($r, $id, $failok, $rc) = $this->createAndRouteMessage($msg, 'test@test.com', 'test@test.com');
        $this->assertEquals(MailRouter::PENDING, $rc);

        $this->addLoginAndLogin($this->user, 'testpw');
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Approve'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->waitBackground();

        # Get the mods.
        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'filter' => Group::FILTER_MODERATORS,
            'members' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['members']));
    }

    public function testSponsors()
    {
        $this->dbhm->preExec("INSERT INTO groups_sponsorship (groupid, name, startdate, enddate, contactname, contactemail, amount) VALUES (?, 'testsponsor', NOW(), NOW(), 'test', 'test', 1);", [
            $this->groupid
        ]);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'sponsors' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['group']['sponsors']));
        $this->assertEquals('testsponsor', $ret['group']['sponsors'][0]['name']);
    }
}


<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/message/MessageCollection.php';
require_once IZNIK_BASE . '/include/misc/Location.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class locationsAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM messages_history WHERE fromaddr = 'test@test.com';");

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE '??%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'TV1%';");
        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;

                # Use lng, lat order for geometry because the OSM data uses that.
                $dbhm->preExec("INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))'));",
                    [
                        $swlat,
                        $swlng,
                        $nelat,
                        $nelng
                    ]);
            }
        }

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        assertEquals(1, $this->user->addMembership($this->groupid));
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
    }

    public function testPost()
    {
        # Create two locations
        $l = new Location($this->dbhr, $this->dbhm);
        $lid1 = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        $lid2 = $l->create(NULL, 'Tuvalu Hugh Street', 'Road', 'POINT(179.2167 8.53333)');

        # Create a group there
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);

        # Create a message which should have the first subject suggested.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Test (Tuvalu High Street)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('OFFER: Test (Tuvalu High Street)', $m->getSubject());
        $atts = $m->getPublic(FALSE, FALSE);
        assertEquals('OFFER: Test (Tuvalu High Street)', $atts['suggestedsubject']);

        # Now block that subject from this group.

        # Shouldn't be able to do this as a member
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('locations', 'POST', [
            'id' => $lid1,
            'groupid' => $this->groupid,
            'messageid' => $id,
            'action' => 'Exclude',
            'byname' => true
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'POST', [
            'id' => $lid1,
            'groupid' => $this->groupid,
            'messageid' => $id,
            'action' => 'Exclude',
            'byname' => true,
            'dup' => 2
        ]);
        assertEquals(0, $ret['ret']);

        # Get the message back - should have suggested the other one this time.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('OFFER: Test (Tuvalu High Street)', $m->getSubject());
        $atts = $m->getPublic(FALSE, FALSE);
        assertEquals('OFFER: Test (Tuvalu Hugh Street)', $atts['suggestedsubject']);

        }

    public function testAreaAndPostcode() {
        $l = new Location($this->dbhr, $this->dbhm);

        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)', 0);

        $locs = $l->withinBox(8.4, 179, 8.6, 180);
        $this->log("Locs in box " . var_export($locs, TRUE));

        $this->log("Postcode $pcid full $fullpcid Area $areaid Location $locid");

        # Create a group there
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);

        # Set it to have a default location.
        $this->group->setPrivate('defaultlocation', $fullpcid);
        assertEquals($fullpcid, $this->group->getPublic()['defaultlocation']['id']);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Test (TV13 1HH)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Suggest a subject to trigger mapping.
        $sugg = $m->suggestSubject($this->groupid, $m->getSubject());
        $atts = $m->getPublic();
        $this->log(var_export($atts, TRUE));
        assertEquals($areaid, $atts['area']['id']);
        assertEquals($pcid, $atts['postcode']['id']);

        }

    public function testPostcode()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POLYGON((179.225 8.525, 179.275 8.525, 179.275 8.575, 179.225 8.575, 179.225 8.525))', 0);

        $ret = $this->call('locations', 'GET', [
            'lng' => 179.226,
            'lat' => 8.526
        ]);
        $this->log("testPostcode " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('TV13 1HH', $ret['location']['name']);

        $ret = $this->call('locations', 'GET', [
            'typeahead' => 'TV13'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals('TV13 1HH', $ret['locations'][0]['name']);

        $ret = $this->call('locations', 'GET', [
            'typeahead' => 'Tuvalu Central',
            'pconly' => FALSE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($areaid, $ret['locations'][0]['id']);
    }

    public function testWithinBox()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))', 0);
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)', 0);
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)', 0);

        $swlng = 179;
        $swlat = 8;
        $nelng = 180;
        $nelat = 9;

        $ret = $this->call('locations', 'GET', [
            'swlat' => $swlat,
            'swlng' => $swlng,
            'nelat' => $nelat,
            'nelng' => $nelng
        ]);
        assertEquals(0, $ret['ret']);
        $this->log("locations " . var_export($ret, TRUE));
        assertGreaterThan(0, count($ret['locations']));

        #$this->log(var_export($ret, TRUE));
        # Again as we'll have created a geometry.
        $this->log("And again");
        $ret = $this->call('locations', 'GET', [
            'swlat' => $swlat,
            'swlng' => $swlng,
            'nelat' => $nelat,
            'nelng' => $nelng
        ]);
        assertEquals(0, $ret['ret']);
        assertGreaterThan(0, count($ret['locations']));

        }

    public function testPatch()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid2 = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.22 8.53, 179.22 8.54, 179.21 8.54, 179.21 8.53))');
        $lid1 = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)',0);
        $this->log("Created location $lid1");

        $ret = $this->call('locations', 'GET', [
            'swlng' => 179.2,
            'swlat' => 8.5,
            'nelng' => 179.3,
            'nelat' => 8.6
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(179.215, $ret['locations'][0]['lng']);
        assertEquals(8.535, $ret['locations'][0]['lat']);

        # Not logged in
        $ret = $this->call('locations', 'PATCH', [
            'id' => $lid2,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        assertTrue($this->user->login('testpw'));

        # Member only
        $ret = $this->call('locations', 'PATCH', [
            'id' => $lid2,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        assertEquals(2, $ret['ret']);

        # Mod
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'PATCH', [
            'id' => $lid2,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))',
            'name' => 'Tuvalu Central2'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('locations', 'GET', [
            'swlng' => 179.2,
            'swlat' => 8.5,
            'nelng' => 179.3,
            'nelat' => 8.6
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);

        # The centre cannot hold, but things should not fall apart.
        assertEquals(179.2125, $ret['locations'][0]['lng']);
        assertEquals(8.535, $ret['locations'][0]['lat']);
        assertEquals('Tuvalu Central2', $ret['locations'][0]['name']);
    }

    public function testPut()
    {
        # Create a fake postcode which should end up being mapped to our area.
        $l = new Location($this->dbhr, $this->dbhm);
        $lid1 = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);
        $this->log("Postcode id $lid1");

        # Not logged in
        $ret = $this->call('locations', 'PUT', [
            'name' => 'Tuvalu Central',
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        assertTrue($this->user->login('testpw'));

        # Member only
        $ret = $this->call('locations', 'PUT', [
            'name' => 'Tuvalu Central',
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        assertEquals(2, $ret['ret']);

        # Mod
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'PUT', [
            'name' => 'Tuvalu Central',
            'osmparentsonly' => 0,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $areaid = $ret['id'];

        $l = new Location($this->dbhr, $this->dbhm, $lid1);
        assertEquals($areaid, $l->getPrivate('areaid'));

        }

    public function findMyStreet()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('W12 7DP');

        $ret = $this->call('locations', 'GET', [
            'findmystreet' => $lid
        ]);

        assertEquals(0, $ret['ret']);
        assertGreaterThan(0, count($ret['streets']));

        }
}

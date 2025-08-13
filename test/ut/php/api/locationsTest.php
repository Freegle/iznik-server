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
class locationsAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM messages_history WHERE fromaddr = 'test@test.com';");

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE '??%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'TV1%';");
        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;

                # Use lng, lat order for geometry because the OSM data uses that.
                $dbhm->preExec("INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, ST_GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))', {$this->dbhr->SRID()}));",
                    [
                        $swlat,
                        $swlng,
                        $nelat,
                        $nelng
                    ]);
            }
        }

        list($this->group, $this->groupid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);

        list($this->user, $this->uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $this->assertEquals(1, $this->user->addMembership($this->groupid));
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
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('OFFER: Test (Tuvalu High Street)', $m->getSubject());
        $atts = $m->getPublic(FALSE);
        $this->assertEquals('OFFER: Test (Tuvalu High Street)', $atts['suggestedsubject']);

        # Now block that subject from this group.

        # Shouldn't be able to do this as a member
        $this->assertTrue($this->user->login('testpw'));
        $ret = $this->call('locations', 'POST', [
            'id' => $lid1,
            'groupid' => $this->groupid,
            'messageid' => $id,
            'action' => 'Exclude',
            'byname' => TRUE
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'POST', [
            'id' => $lid1,
            'groupid' => $this->groupid,
            'messageid' => $id,
            'action' => 'Exclude',
            'byname' => TRUE,
            'dup' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);

        # Get the message back - should have suggested the other one this time.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('OFFER: Test (Tuvalu High Street)', $m->getSubject());
        $atts = $m->getPublic(FALSE);
        $this->assertEquals('OFFER: Test (Tuvalu Hugh Street)', $atts['suggestedsubject']);

        }

    public function testAreaAndPostcode() {
        $l = new Location($this->dbhr, $this->dbhm);

        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $locs = $l->withinBox(8.4, 179, 8.6, 180);
        $this->log("Locs in box " . var_export($locs, TRUE));

        $this->log("Postcode $pcid full $fullpcid Area $areaid Location $locid");

        # Create a group there
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);

        # Set it to have a default location.
        $this->group->setPrivate('defaultlocation', $fullpcid);
        $this->assertEquals($fullpcid, $this->group->getPublic()['defaultlocation']['id']);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Test (TV13 1HH)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        $this->assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Suggest a subject to trigger mapping.
        $sugg = $m->suggestSubject($this->groupid, $m->getSubject());
        $atts = $m->getPublic();
        $this->log(var_export($atts, TRUE));
        $this->assertEquals($areaid, $atts['area']['id']);
        $this->assertEquals($pcid, $atts['postcode']['id']);

        }

    public function testPostcode()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POLYGON((179.225 8.525, 179.275 8.525, 179.275 8.575, 179.225 8.575, 179.225 8.525))');

        $ret = $this->call('locations', 'GET', [
            'lng' => 179.226,
            'lat' => 8.526
        ]);
        $this->log("testPostcode " . var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('TV13 1HH', $ret['location']['name']);

        $ret = $this->call('locations', 'GET', [
            'typeahead' => 'TV13'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('TV13 1HH', $ret['locations'][0]['name']);

        $ret = $this->call('locations', 'GET', [
            'typeahead' => 'Tuvalu Central',
            'pconly' => FALSE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($areaid, $ret['locations'][0]['id']);

        # Test groups near.
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('polyofficial', 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))');

        $ret = $this->call('locations', 'GET', [
            'typeahead' => 'TV13 1HH',
            'groupsnear' => TRUE,
            'groupcount' => TRUE
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals('TV13 1HH', $ret['locations'][0]['name']);
        $this->assertEquals(1, count($ret['locations'][0]['groupsnear']));
        $this->assertEquals(0, $ret['locations'][0]['groupsnear'][0]['postcount']);
    }

    public function testWithinBox()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

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
        $this->assertEquals(0, $ret['ret']);
        $this->log("locations " . var_export($ret, TRUE));
        $this->assertGreaterThan(0, count($ret['locations']));

        #$this->log(var_export($ret, TRUE));
        # Again as we'll have created a geometry.
        $this->log("And again");
        $ret = $this->call('locations', 'GET', [
            'swlat' => $swlat,
            'swlng' => $swlng,
            'nelat' => $nelat,
            'nelng' => $nelng
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertGreaterThan(0, count($ret['locations']));

        }

    public function testPatch()
    {
        $l = new Location($this->dbhr, $this->dbhm);

        # Make sure the relevant Postgres table exists.
        $l->copyLocationsToPostgresql();

        $lid2 = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.22 8.53, 179.22 8.54, 179.21 8.54, 179.21 8.53))');
        $lid1 = $l->create(NULL, 'TV13 1AA', 'Postcode', 'POINT(179.2167 8.53333)');
        $this->log("Created location $lid1");

        $ret = $this->call('locations', 'GET', [
            'swlng' => 179.2,
            'swlat' => 8.5,
            'nelng' => 179.3,
            'nelat' => 8.6
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(179.215, $ret['locations'][0]['lng']);
        $this->assertEquals(8.535, $ret['locations'][0]['lat']);

        # Not logged in
        $ret = $this->call('locations', 'PATCH', [
            'id' => $lid2,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $this->assertTrue($this->user->login('testpw'));

        # Member only
        $ret = $this->call('locations', 'PATCH', [
            'id' => $lid2,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Mod
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'PATCH', [
            'id' => $lid2,
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))',
            'name' => 'Tuvalu Central2'
        ]);
        $this->assertEquals(0, $ret['ret']);

        $ret = $this->call('locations', 'GET', [
            'swlng' => 179.2,
            'swlat' => 8.5,
            'nelng' => 179.3,
            'nelat' => 8.6
        ]);
        $this->log(var_export($ret, TRUE));
        $this->assertEquals(0, $ret['ret']);

        # The centre cannot hold, but things should not fall apart.
        $this->assertEquals(179.2125, $ret['locations'][0]['lng']);
        $this->assertEquals(8.535, $ret['locations'][0]['lat']);
        $this->assertEquals('Tuvalu Central2', $ret['locations'][0]['name']);
    }

    public function testPut()
    {
        # Create a fake postcode which should end up being mapped to our area.
        $l = new Location($this->dbhr, $this->dbhm);
        $lid1 = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)');
        $this->log("Postcode id $lid1");

        # Not logged in
        $ret = $this->call('locations', 'PUT', [
            'name' => 'Tuvalu Central',
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))'
        ]);
        $this->assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MEMBER, $this->groupid);
        $this->assertTrue($this->user->login('testpw'));

        # Member only
        $ret = $this->call('locations', 'PUT', [
            'name' => 'Tuvalu Central',
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))',
            'dup' => 1
        ]);
        $this->assertEquals(2, $ret['ret']);

        # Mod
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'PUT', [
            'name' => 'Tuvalu Central',
            'polygon' => 'POLYGON((179.205 8.53, 179.22 8.53, 179.22 8.54, 179.205 8.54, 179.205 8.53))',
            'dup' => 2
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $areaid = $ret['id'];

        $l->copyLocationsToPostgresql();
        $l->remapPostcodes();

        $l = new Location($this->dbhr, $this->dbhm, $lid1);
        $this->assertEquals($areaid, $l->getPrivate('areaid'));
    }

    public function findMyStreet()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('W12 7DP');

        $ret = $this->call('locations', 'GET', [
            'findmystreet' => $lid
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertGreaterThan(0, count($ret['streets']));
    }

    public function testCopyLocationsToPostgresql() {
        $l = new Location($this->dbhr, $this->dbhm);

        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $this->assertGreaterThan(0, $l->copyLocationsToPostgresql());
        $this->assertGreaterThan(0, $l->remapPostcodes());
    }

//    public function testEH() {
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//        $_SESSION['id'] = 35909200;
//        $ret = $this->call('locations', 'PATCH', [
//            'id' => 1859090,
//            'modtools' => TRUE,
//            'name' => "Lewisham",
//            'polygon' => "POLYGON((-0.010475500000000084 51.45525100000438,-0.010719301644712688 51.454809171198306,-0.010865 51.455231,-0.019265 51.459698,-0.022577 51.461679,-0.024963 51.46376,-0.03075599495787174 51.46780389354294,-0.03192901611328126 51.47234849795365,-0.02085685729980469 51.471359416711145,-0.01452622842524676 51.472431927983514,-0.012531280517578127 51.46983565499583,-0.007553100585937501 51.47168911392899,-0.0022315979003906254 51.467590018653155,0.000593 51.459532,0.000961 51.458734,-0.006703 51.455951,-0.010086 51.455271,-0.010475500000000084 51.45525100000438))",
//            'typeahead' => 'NP26 4AD'
//        ]);
//        $this->assertEquals(0, $ret['ret']);
//    }
}

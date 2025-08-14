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
class locationTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'TV13%';");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE '??%';");
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

        $grids = $dbhr->preQuery("SELECT * FROM locations_grids WHERE swlng >= 179.1 AND swlng <= 179.3;");
        foreach ($grids as $grid) {
            $sql = "SELECT id FROM locations_grids WHERE MBRTouches (ST_GeomFromText('POLYGON(({$grid['swlng']} {$grid['swlat']}, {$grid['swlng']} {$grid['nelat']}, {$grid['nelng']} {$grid['nelat']}, {$grid['nelng']} {$grid['swlat']}, {$grid['swlng']} {$grid['swlat']}))', {$this->dbhr->SRID()}), box);";
            $touches = $dbhr->preQuery($sql);
            foreach ($touches as $touch) {
                $sql = "INSERT IGNORE INTO locations_grids_touches (gridid, touches) VALUES (?, ?);";
                $rc = $dbhm->preExec($sql, [ $grid['id'], $touch['id'] ]);
            }
        }
    }

    public function testBasic() {
        list($l, $id) = $this->createTestLocation(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        $this->assertNotNull($id);
        $this->assertEquals($id, $l->findByName('Tuvalu High Street'));
        $l = new Location($this->dbhr, $this->dbhm, $id);
        $atts = $l->getPublic();
        $this->log("Created loc " . var_export($atts, TRUE));
        $gridid = $atts['gridid'];
        $grid = $l->getGrid();
        $this->log("Grid " . var_export($grid, TRUE));
        $this->assertEquals($gridid, $grid['id']);
        $this->assertEquals(8.5, $grid['swlat']);
        $this->assertEquals(179.2, $grid['swlng']);

        $this->assertEquals(1, $l->delete());

        }

    public function testParents() {
        $l = new Location($this->dbhr, $this->dbhm);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $this->log("Postcode id $pcid");
        $this->assertNotNull($pcid);

        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        $this->log("Area id $areaid");
        $this->assertNotNull($areaid);

        $id = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        $this->log("Loc id $id");
        $l = new Location($this->dbhr, $this->dbhm, $id);
        $atts = $l->getPublic();

        # No area as not a postcode.
        $this->assertNull($atts['areaid']);

        $id2 = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $this->log("Full postcode id $id");
        $l = new Location($this->dbhr, $this->dbhm, $id2);
        $atts = $l->getPublic();
        $this->assertEquals($areaid, $atts['areaid']);
        $this->assertEquals($pcid, $atts['postcodeid']);
    }

    public function testParentsOverlap() {
        $clat = 8.53;
        $clng = 179.25;

        $sw['lat'] = $clat - 0.1;
        $sw['lng'] = $clng - 0.1;
        $ne['lat'] = $clat + 0.05;
        $ne['lng'] = $clng + 0.05;

        $box1 = "POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))";

        $sw['lat'] = $clat - 0.05;
        $sw['lng'] = $clng - 0.05;
        $ne['lat'] = $clat + 0.05;
        $ne['lng'] = $clng + 0.05;

        $box2 = "POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))";

        $l = new Location($this->dbhr, $this->dbhm);
        $pcid = $l->create(NULL, 'TV13 1AA', 'Postcode', "POINT($clng $clat)");
        $this->log("Postcode id $pcid");
        $this->assertNotNull($pcid);

        $areaid1 = $l->create(NULL, 'Tuvalu Central1', 'Polygon', $box1);
        $this->log("Area id $areaid1");
        $this->assertNotNull($areaid1);

        $areaid2 = $l->create(NULL, 'Tuvalu Central2', 'Polygon', $box2);
        $this->log("Area id $areaid2");
        $this->assertNotNull($areaid2);

        # Postcode should be in area 2, because it contains the postcode and is smaller than area 1.
        $l->copyLocationsToPostgresql();
        $l->remapPostcodes();

        $l = new Location($this->dbhr, $this->dbhm, $pcid);
        $this->assertEquals($areaid2, $l->getPrivate('areaid'));

        # Edit area2 so that it no longer includes the postcode.
        $sw['lat'] = $clat + 0.01;
        $sw['lng'] = $clng + 0.01;
        $ne['lat'] = $clat + 0.1;
        $ne['lng'] = $clng + 0.1;

        $box3 = "POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))";

        $l = new Location($this->dbhr, $this->dbhm, $areaid2);
        $l->setGeometry($box3);

        $l->copyLocationsToPostgresql();
        $l->remapPostcodes();

        # Change to a non-overlapping area for coverage.
        $sw['lat'] = $clat + 0.5;
        $sw['lng'] = $clng + 0.5;
        $ne['lat'] = $clat + 0.6;
        $ne['lng'] = $clng + 0.6;

        $box3 = "POLYGON(({$sw['lng']} {$sw['lat']}, {$sw['lng']} {$ne['lat']}, {$ne['lng']} {$ne['lat']}, {$ne['lng']} {$sw['lat']}, {$sw['lng']} {$sw['lat']}))";

        $l = new Location($this->dbhr, $this->dbhm, $areaid2);
        $l->setGeometry($box3);
    }

    public function testInvent() {
        # Create an area with a point which should really be a polygon.
        $l = new Location($this->dbhr, $this->dbhm);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POINT(179.2167 8.53333)');
        $this->log("Postcode id $pcid");
        $this->assertNotNull($pcid);

        $id1 = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2162 8.53283)');
        $l->setPrivate('areaid', $pcid);
        $this->assertNotNull($id1);
        $id2 = $l->create(NULL, 'TV13 2HH', 'Postcode', 'POINT(179.2162 8.53383)');
        $l->setPrivate('areaid', $pcid);
        $this->assertNotNull($id2);
        $id3 = $l->create(NULL, 'TV13 3HH', 'Postcode', 'POINT(179.2172 8.53383)');
        $l->setPrivate('areaid', $pcid);
        $this->assertNotNull($id3);
        $id4 = $l->create(NULL, 'TV13 4HH', 'Postcode', 'POINT(179.2162 8.53283)');
        $l->setPrivate('areaid', $pcid);
        $this->assertNotNull($id4);

        # Call withinBox.  This will invent a small polygon around the point.
        $this->log("$pcid, $id1, $id2, $id3");
        $locs = $l->withinBox(8.4, 179.1, 8.7, 179.4);
        $this->log(var_export($locs, TRUE));
        $poly = 'POLYGON((179.2162 8.53283, 179.2162 8.53383, 179.2172 8.53383, 179.2172 8.53283, 179.2162 8.53283))';
        $this->assertEquals($poly, $locs[0]['polygon']);

        # Change the geometry to something which isn't a point or a polygon.  We'll invent a polygon.  We need to
        # mock this as the convex hull function relies on a PHP extension which is a faff to install.
        $mock = $this->getMockBuilder('Freegle\Iznik\Location')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, FALSE])
            ->setMethods(array('convexHull'))
            ->getMock();
        $mock->method('convexHull')->willReturn(\geoPHP::load($poly));

        $l = new Location($this->dbhr, $this->dbhm, $pcid);
        $l->setGeometry('LINESTRING(179.2162 8.53283, 179.2162 8.53383)');
        $locs = $mock->withinBox(8.4, 179.1, 8.7, 179.4);
        $this->log(var_export($locs, TRUE));
        $this->assertEquals('POLYGON ((179.2162 8.53283, 179.2162 8.53383, 179.2172 8.53383, 179.2172 8.53283, 179.2162 8.53283))', $locs[0]['polygon']);
    }

    public function testError() {
        $dbconfig = array (
            'host' => SQLHOST,
            'user' => SQLUSER,
            'pass' => SQLPASSWORD,
            'database' => SQLDB
        );

        $l = new Location($this->dbhr, $this->dbhm);
        $mock = $this->getMockBuilder('Freegle\Iznik\LoggedPDO')
            ->setConstructorArgs([
                "mysql:host={$dbconfig['host']};dbname={$dbconfig['database']};charset=utf8",
                $dbconfig['user'], $dbconfig['pass'], array(), TRUE
            ])
            ->setMethods(array('preExec'))
            ->getMock();
        $mock->method('preExec')->willThrowException(new \Exception());
        $l->setDbhm($mock);

        $id = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        $this->assertNull($id);

        }

    public function testSearch() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $this->log("Created group $gid");

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.4);

        list($l, $id) = $this->createTestLocation(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $l = new Location($this->dbhr, $this->dbhm, $id);

        $res = $l->search("Tuvalu", $gid);
        $this->log(var_export($res, TRUE));
        $this->assertEquals(1, count($res));
        $this->assertEquals($id, $res[0]['id']);

        # Find something which matches a word.
        $res = $l->search("high", $gid);
        $this->assertEquals(1, count($res));
        $this->assertEquals($id, $res[0]['id']);

        # Fail to find something which doesn't match a word.
        $res = $l->search("stre", $gid);
        $this->assertEquals(0, count($res));

        $res = $l->search("high street", $gid);
        $this->assertEquals(1, count($res));
        $this->assertEquals($id, $res[0]['id']);

        # Make sure that exact matches trump prefix matches
        $id2 = $l->create(NULL, 'Tuvalu High', 'Road', 'POINT(179.2167 8.53333)');

        $res = $l->search("Tuvalu high", $gid, 1);
        $this->assertEquals(1, count($res));
        $this->assertEquals($id2, $res[0]['id']);

        # Find one where the valid location is contained within our search term
        $res = $l->search("in Tuvalu high street area", $gid, 1);
        $this->assertEquals(1, count($res));
        $this->assertEquals($id, $res[0]['id']);

        $this->assertEquals(1, $l->delete());

        }

    public function testClosestPostcode() {
        $l = new Location($this->dbhr, $this->dbhm);

        if (!$l->findByName('PR3 2NE')) {
            $pcid = $l->create(NULL, 'PR3 2NE', 'Postcode', 'POINT(-2.64225600682264 53.8521694004918)');
        }

        $loc = $l->closestPostcode(53.856556299999994, -2.6401651999999998);
        $this->assertEquals("PR3 2NE", $loc['name']);

        if (!$l->findByName('RM9 6SR')) {
            $pcid = $l->create(NULL, 'RM9 6SR', 'Postcode', 'POINT(0.14700179589836 51.531097253523)');
        }

        $loc = $l->closestPostcode(51.530687199999996, 0.146932);
        $this->assertEquals("RM9 6SR", $loc['name']);

        }

    public function testGroupsNear() {
        list($g, $gid) = $this->createTestGroup('testgroup', Group::GROUP_REUSE);
        $this->log("Created group $gid");

        $g->setPrivate('lng', 179.15);
        $g->setPrivate('lat', 8.4);
        $g->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');

        list($l, $id) = $this->createTestLocation(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $groups = $l->groupsNear(50);
        $this->log("Found groups near " . var_export($groups, TRUE));
        $this->assertTrue(in_array($gid, $groups));

        # Shouldn't find unlisted groups
        $g->setPrivate('listable', 0);
        $groups = $l->groupsNear(50);
        $this->log("Shouldn't find groups near " . var_export($groups, TRUE));
        $this->assertFalse(in_array($gid, $groups));

    }
}


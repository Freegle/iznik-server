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
class IsochroneTest extends IznikTestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->dbhm->preExec("DELETE FROM isochrones;");
        $this->dbhm->preExec("DELETE FROM users_approxlocs;");
    }

    public function testBasic() {
        $i = new Isochrone($this->dbhr, $this->dbhm);
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        $id = $i->create($uid, Isochrone::WALK, Isochrone::DEFAULT_TIME, NULL, $lid);
        $this->assertEquals($id, $i->getPublic()['id']);
        $i = new Isochrone($this->dbhr, $this->dbhm, $id);
        $this->assertEquals($id, $i->getPublic()['id']);
        $this->assertNotNull($i->getPublic()['polygon']);

        $isochrones = $i->list($uid);
        $this->assertEquals(1, count($isochrones));
        $this->assertEquals($id, $isochrones[0]['id']);
    }

    public function testEnsureIsochroneContainingActiveUsers() {
        # Set up a central location
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        # Get the lat/lng of the central location
        $lat = $l->getPrivate('lat');
        $lng = $l->getPrivate('lng');

        # Create test users very close to the central location
        # Place them extremely close (within meters) so they'll be captured by even small isochrones
        $users = [];
        $userCount = 10;

        for ($j = 0; $j < $userCount; $j++) {
            $u = User::get($this->dbhr, $this->dbhm);
            $uid = $u->create(NULL, NULL, "Test User $j");

            # Place users very close to center - within ~100 meters
            $offsetLat = ($j - $userCount / 2) * 0.0001;
            $offsetLng = ($j - $userCount / 2) * 0.0001;
            $userLat = $lat + $offsetLat;
            $userLng = $lng + $offsetLng;

            # Set lastaccess to recent
            $this->dbhm->preExec("UPDATE users SET lastaccess = NOW() WHERE id = ?;", [$uid]);

            # Add to users_approxlocs
            $this->dbhm->preExec("INSERT INTO users_approxlocs (userid, lat, lng, position, timestamp) VALUES (?, ?, ?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$this->dbhr->SRID()}), NOW());", [
                $uid,
                $userLat,
                $userLng,
                $userLng,
                $userLat
            ]);

            $users[] = $uid;
        }

        # Test basic functionality - function should return [isochrone ID, minutes]
        $i = new Isochrone($this->dbhr, $this->dbhm);
        list($isochroneid, $minutes) = $i->ensureIsochroneContainingActiveUsers($lid, Isochrone::WALK, 5, 90, 10, 60);
        $this->assertNotNull($isochroneid);
        $this->assertGreaterThanOrEqual(10, $minutes);
        $this->assertLessThanOrEqual(60, $minutes);

        # Verify the isochrone was created
        $isochrone = $this->dbhr->preQuery("SELECT * FROM isochrones WHERE id = ?;", [$isochroneid]);
        $this->assertEquals(1, count($isochrone));
        $this->assertEquals($minutes, $isochrone[0]['minutes']);

        # Test with very high target - should hit the max limit
        list($isochroneid2, $minutes2) = $i->ensureIsochroneContainingActiveUsers($lid, Isochrone::WALK, 1000, 90, 10, 30);
        $this->assertNotNull($isochroneid2);
        $this->assertLessThanOrEqual(30, $minutes2);

        $isochrone2 = $this->dbhr->preQuery("SELECT * FROM isochrones WHERE id = ?;", [$isochroneid2]);
        $this->assertEquals(1, count($isochrone2));
        $this->assertEquals($minutes2, $isochrone2[0]['minutes']);

        # Test with different initial minutes
        list($isochroneid3, $minutes3) = $i->ensureIsochroneContainingActiveUsers($lid, Isochrone::CYCLE, 5, 90, 20, 60);
        $this->assertNotNull($isochroneid3);
        $this->assertGreaterThanOrEqual(20, $minutes3);

        $isochrone3 = $this->dbhr->preQuery("SELECT * FROM isochrones WHERE id = ?;", [$isochroneid3]);
        $this->assertEquals(1, count($isochrone3));
        $this->assertEquals(Isochrone::CYCLE, $isochrone3[0]['transport']);
        $this->assertEquals($minutes3, $isochrone3[0]['minutes']);

        # Test that old users (inactive) are not counted
        # Add an inactive user at the central location (only if location has valid coordinates)
        if ($lat && $lng) {
            $uOld = User::get($this->dbhr, $this->dbhm);
            $uidOld = $uOld->create(NULL, NULL, "Old User");
            $this->dbhm->preExec("UPDATE users SET lastaccess = DATE_SUB(NOW(), INTERVAL 100 DAY) WHERE id = ?;", [$uidOld]);
            $this->dbhm->preExec("INSERT INTO users_approxlocs (userid, lat, lng, position, timestamp) VALUES (?, ?, ?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), {$this->dbhr->SRID()}), DATE_SUB(NOW(), INTERVAL 100 DAY));", [
                $uidOld,
                $lat,
                $lng,
                $lng,
                $lat
            ]);

            # This should still only count the active users, not the old one (using default 90 day window)
            list($isochroneid4, $minutes4) = $i->ensureIsochroneContainingActiveUsers($lid, Isochrone::WALK, 5, 90, 10, 60);
            $this->assertNotNull($isochroneid4);
        }
    }

    public function testFindExistingIsochrone() {
        $i = new Isochrone($this->dbhr, $this->dbhm);

        # Create a location
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        # Create an isochrone
        $isochroneid = $i->ensureIsochroneExists($lid, 10, Isochrone::WALK);
        $this->assertNotNull($isochroneid);

        # Try to find it - should return the same one
        $isochroneid2 = $i->ensureIsochroneExists($lid, 10, Isochrone::WALK);
        $this->assertEquals($isochroneid, $isochroneid2);

        # Different transport should create new one
        $isochroneid3 = $i->ensureIsochroneExists($lid, 10, Isochrone::CYCLE);
        $this->assertNotEquals($isochroneid, $isochroneid3);
    }

    public function testInsertIsochrone() {
        # Test that isochrone insertion works with geometry simplification
        $i = new Isochrone($this->dbhr, $this->dbhm);

        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName('EH3 6SS');
        $this->assertNotNull($lid);

        # Create an isochrone - this will exercise the insertIsochrone private method
        $isochroneid = $i->ensureIsochroneExists($lid, 15, Isochrone::WALK);
        $this->assertNotNull($isochroneid);

        # Verify it was created in the database
        $result = $this->dbhr->preQuery("SELECT * FROM isochrones WHERE id = ?;", [$isochroneid]);
        $this->assertEquals(1, count($result));
        $this->assertEquals($lid, $result[0]['locationid']);
        $this->assertEquals(Isochrone::WALK, $result[0]['transport']);
        $this->assertEquals(15, $result[0]['minutes']);
        $this->assertNotNull($result[0]['polygon']);
    }

    public function testGetTransportModes() {
        $i = new Isochrone($this->dbhr, $this->dbhm);

        # Use reflection to test private methods
        $reflection = new \ReflectionClass($i);

        # Test Mapbox transport modes
        $mapboxMethod = $reflection->getMethod('getMapboxTransportMode');
        $mapboxMethod->setAccessible(TRUE);

        $this->assertEquals('walking', $mapboxMethod->invoke($i, Isochrone::WALK));
        $this->assertEquals('cycling', $mapboxMethod->invoke($i, Isochrone::CYCLE));
        $this->assertEquals('driving', $mapboxMethod->invoke($i, Isochrone::DRIVE));
        $this->assertEquals('driving', $mapboxMethod->invoke($i, 'unknown'));

        # Test ORS transport modes
        $orsMethod = $reflection->getMethod('getORSTransportMode');
        $orsMethod->setAccessible(TRUE);

        $this->assertEquals('foot-walking', $orsMethod->invoke($i, Isochrone::WALK));
        $this->assertEquals('cycling-regular', $orsMethod->invoke($i, Isochrone::CYCLE));
        $this->assertEquals('driving-car', $orsMethod->invoke($i, Isochrone::DRIVE));
        $this->assertEquals('driving-car', $orsMethod->invoke($i, 'unknown'));
    }

    public function testValidateCurlResult() {
        $i = new Isochrone($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($i);
        $method = $reflection->getMethod('validateCurlResult');
        $method->setAccessible(TRUE);

        # Test successful result
        $result = [
            'response' => '{"test": "data"}',
            'httpCode' => 200,
            'error' => ''
        ];
        $validated = $method->invoke($i, $result);
        $this->assertTrue($validated['success']);
        $this->assertEquals('{"test": "data"}', $validated['data']);

        # Test with cURL error
        $result = [
            'response' => '',
            'httpCode' => 0,
            'error' => 'Connection timeout'
        ];
        $validated = $method->invoke($i, $result);
        $this->assertFalse($validated['success']);
        $this->assertStringContainsString('cURL error', $validated['error']);

        # Test with HTTP error
        $result = [
            'response' => 'Not Found',
            'httpCode' => 404,
            'error' => ''
        ];
        $validated = $method->invoke($i, $result);
        $this->assertFalse($validated['success']);
        $this->assertStringContainsString('HTTP 404', $validated['error']);
    }

    public function testIsValidORSResponse() {
        $i = new Isochrone($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($i);
        $method = $reflection->getMethod('isValidORSResponse');
        $method->setAccessible(TRUE);

        # Test valid response
        $response = ['features' => []];
        $this->assertTrue($method->invoke($i, $response));

        # Test null response
        $this->assertFalse($method->invoke($i, NULL));

        # Test response with error
        $response = ['error' => 'Something went wrong'];
        $this->assertFalse($method->invoke($i, $response));
    }

    public function testConvertGeometryToWkt() {
        $i = new Isochrone($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($i);
        $method = $reflection->getMethod('convertGeometryToWkt');
        $method->setAccessible(TRUE);

        # Test with null geometry
        $result = $method->invoke($i, NULL);
        $this->assertNull($result);

        # Test with valid GeoJSON
        $geojson = [
            'type' => 'Point',
            'coordinates' => [0, 0]
        ];
        $result = $method->invoke($i, $geojson);
        $this->assertNotNull($result);
        $this->assertStringContainsString('POINT', $result);
    }
}


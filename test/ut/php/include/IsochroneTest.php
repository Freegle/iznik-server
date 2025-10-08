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
}


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
class POITest extends IznikTestCase {

    public function testConstructorConvertsToRadians() {
        $poi = new POI(45.0, 90.0);

        // 45 degrees should be pi/4 radians.
        $this->assertEqualsWithDelta(deg2rad(45.0), $poi->getLatitude(), 0.0001);
        $this->assertEqualsWithDelta(deg2rad(90.0), $poi->getLongitude(), 0.0001);
    }

    public function testGetLatitude() {
        $poi = new POI(51.5074, -0.1278); // London
        $expected = deg2rad(51.5074);
        $this->assertEqualsWithDelta($expected, $poi->getLatitude(), 0.0001);
    }

    public function testGetLongitude() {
        $poi = new POI(51.5074, -0.1278); // London
        $expected = deg2rad(-0.1278);
        $this->assertEqualsWithDelta($expected, $poi->getLongitude(), 0.0001);
    }

    public function testDistanceToSamePoint() {
        $poi1 = new POI(51.5074, -0.1278);
        $poi2 = new POI(51.5074, -0.1278);

        $distance = $poi1->getDistanceInMetersTo($poi2);
        $this->assertEqualsWithDelta(0, $distance, 0.01);
    }

    public function testDistanceLondonToEdinburgh() {
        // London to Edinburgh is approximately 534 km.
        $london = new POI(51.5074, -0.1278);
        $edinburgh = new POI(55.9533, -3.1883);

        $distance = $london->getDistanceInMetersTo($edinburgh);

        // Allow 5km tolerance for the approximation.
        $this->assertEqualsWithDelta(534000, $distance, 5000);
    }

    public function testDistanceLondonToParis() {
        // London to Paris is approximately 344 km.
        $london = new POI(51.5074, -0.1278);
        $paris = new POI(48.8566, 2.3522);

        $distance = $london->getDistanceInMetersTo($paris);

        // Allow 5km tolerance.
        $this->assertEqualsWithDelta(344000, $distance, 5000);
    }

    public function testDistanceIsSymmetric() {
        $poi1 = new POI(51.5074, -0.1278);
        $poi2 = new POI(48.8566, 2.3522);

        $distance1 = $poi1->getDistanceInMetersTo($poi2);
        $distance2 = $poi2->getDistanceInMetersTo($poi1);

        $this->assertEqualsWithDelta($distance1, $distance2, 0.01);
    }

    public function testDistanceAtEquator() {
        // 1 degree of longitude at the equator is approximately 111 km.
        $poi1 = new POI(0, 0);
        $poi2 = new POI(0, 1);

        $distance = $poi1->getDistanceInMetersTo($poi2);

        $this->assertEqualsWithDelta(111000, $distance, 1000);
    }

    public function testDistanceNorthPole() {
        // Near poles, longitude differences result in smaller distances.
        $poi1 = new POI(89, 0);
        $poi2 = new POI(89, 180);

        $distance = $poi1->getDistanceInMetersTo($poi2);

        // Should be roughly 2 degrees of latitude in distance (about 222 km).
        $this->assertGreaterThan(200000, $distance);
        $this->assertLessThan(250000, $distance);
    }

    public function testDistanceWithNegativeCoordinates() {
        // Sydney to Wellington.
        $sydney = new POI(-33.8688, 151.2093);
        $wellington = new POI(-41.2865, 174.7762);

        $distance = $sydney->getDistanceInMetersTo($wellington);

        // Approximately 2200 km.
        $this->assertEqualsWithDelta(2200000, $distance, 50000);
    }

    public function testDistanceShortDistance() {
        // Two points about 1 km apart.
        $poi1 = new POI(51.5074, -0.1278);
        $poi2 = new POI(51.5164, -0.1278); // About 1km north.

        $distance = $poi1->getDistanceInMetersTo($poi2);

        // Should be close to 1000m.
        $this->assertEqualsWithDelta(1000, $distance, 50);
    }
}

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
class RepairCafeWalesTest extends IznikTestCase {
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testExtractPostcode() {
        $rcw = new RepairCafeWales($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($rcw);
        $method = $reflection->getMethod('extractPostcode');
        $method->setAccessible(TRUE);

        # Test valid postcode
        $location = "Some Place, CF10 1AB, Wales";
        $result = $method->invoke($rcw, $location);
        $this->assertEquals('CF10 1AB', $result);

        # Test no postcode
        $location = "Some Place Without Postcode";
        $result = $method->invoke($rcw, $location);
        $this->assertNull($result);
    }

    public function testFormatDateTime() {
        $rcw = new RepairCafeWales($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($rcw);
        $method = $reflection->getMethod('formatDateTime');
        $method->setAccessible(TRUE);

        # Create a DateTime object
        $dt = new \DateTime('2025-01-15 14:30:00', new \DateTimeZone('UTC'));

        $result = $method->invoke($rcw, $dt);

        # Should replace +0000 with Z
        $this->assertStringEndsWith('Z', $result);
        $this->assertStringNotContainsString('+0000', $result);
    }

    public function testEventStillExists() {
        $rcw = new RepairCafeWales($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($rcw);
        $method = $reflection->getMethod('eventStillExists');
        $method->setAccessible(TRUE);

        $externalsSeen = [
            'event123' => TRUE,
            'event456' => TRUE
        ];

        # Test existing event
        $result = $method->invoke($rcw, 'event123', $externalsSeen);
        $this->assertTrue($result);

        # Test non-existing event
        $result = $method->invoke($rcw, 'event789', $externalsSeen);
        $this->assertFalse($result);
    }

    public function testHasEventChanged() {
        $rcw = new RepairCafeWales($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($rcw);
        $method = $reflection->getMethod('hasEventChanged');
        $method->setAccessible(TRUE);

        # Create a test event
        $e = new CommunityEvent($this->dbhr, $this->dbhm);
        $eid = $e->create(
            NULL,
            'Original Title',
            'Original Location',
            NULL,
            NULL,
            NULL,
            'http://example.com',
            'Original Description'
        );

        # Test no change
        $result = $method->invoke($rcw, $e, 'Original Title', 'Original Description', 'Original Location');
        $this->assertFalse($result);

        # Test with changed title
        $result = $method->invoke($rcw, $e, 'New Title', 'Original Description', 'Original Location');
        $this->assertTrue($result);

        # Test with changed description
        $result = $method->invoke($rcw, $e, 'Original Title', 'New Description', 'Original Location');
        $this->assertTrue($result);

        # Test with changed location
        $result = $method->invoke($rcw, $e, 'Original Title', 'Original Description', 'New Location');
        $this->assertTrue($result);

        # Clean up
        $e->setPrivate('deleted', 1);
    }

    public function testFindExistingEvent() {
        $rcw = new RepairCafeWales($this->dbhr, $this->dbhm);

        # Use reflection to test private method
        $reflection = new \ReflectionClass($rcw);
        $method = $reflection->getMethod('findExistingEvent');
        $method->setAccessible(TRUE);

        # Create a test event with external ID
        $e = new CommunityEvent($this->dbhr, $this->dbhm);
        $externalId = 'test-repair-cafe-' . time();
        $eid = $e->create(
            NULL,
            'Test Event',
            'Test Location',
            NULL,
            NULL,
            NULL,
            'http://example.com',
            'Test Description',
            NULL,
            $externalId
        );

        # Test finding existing event
        $result = $method->invoke($rcw, $externalId);
        $this->assertNotNull($result);
        $this->assertEquals($eid, $result['id']);

        # Test finding non-existing event
        $result = $method->invoke($rcw, 'non-existent-id');
        $this->assertNull($result);

        # Clean up
        $e->setPrivate('deleted', 1);
    }
}

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
class imageTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testNullParams() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $w = $i->width();
        $h = $i->height();

        $i->scale(NULL, NULL);

        $this->assertEquals($w, $i->width());
        $this->assertEquals($h, $i->height());

        $i->scale($w+1, NULL);

        // Rounds up.
        $this->assertEquals($w + 2, $i->width());
        $this->assertEquals($h + 2, $i->height());

        $i->scale(NULL, $h+1);
        $this->assertEquals($w + 2, $i->width());
        $this->assertEquals($h + 1, $i->height());

        }

    public function testWidthHeight() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $this->assertGreaterThan(0, $i->width());
        $this->assertGreaterThan(0, $i->height());
    }

    public function testGetData() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        // Get data at default quality.
        $output = $i->getData();
        $this->assertNotNull($output);
        $this->assertGreaterThan(0, strlen($output));

        // Should be valid JPEG.
        $info = @getimagesizefromstring($output);
        $this->assertNotFalse($info);
        $this->assertEquals(IMAGETYPE_JPEG, $info[2]);
    }

    public function testGetDataWithQuality() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        // Get data at low quality.
        $lowQuality = $i->getData(10);
        $this->assertNotNull($lowQuality);

        // Get data at high quality.
        $highQuality = $i->getData(100);
        $this->assertNotNull($highQuality);

        // High quality should typically be larger (or equal for simple images).
        $this->assertGreaterThanOrEqual(strlen($lowQuality), strlen($highQuality));
    }

    public function testGetDataPNG() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $output = $i->getDataPNG();
        $this->assertNotNull($output);
        $this->assertGreaterThan(0, strlen($output));

        // Should be valid PNG.
        $info = @getimagesizefromstring($output);
        $this->assertNotFalse($info);
        $this->assertEquals(IMAGETYPE_PNG, $info[2]);
    }

    public function testRotate() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $origWidth = $i->width();
        $origHeight = $i->height();

        // Rotate 90 degrees.
        $i->rotate(90);

        // For 90 degrees, width and height should swap.
        $this->assertEquals($origHeight, $i->width());
        $this->assertEquals($origWidth, $i->height());
    }

    public function testRotate180() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $origWidth = $i->width();
        $origHeight = $i->height();

        // Rotate 180 degrees.
        $i->rotate(180);

        // For 180 degrees, dimensions should stay the same.
        $this->assertEquals($origWidth, $i->width());
        $this->assertEquals($origHeight, $i->height());
    }

    public function testCircle() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $radius = 50;
        $i->circle($radius);

        // After circle, dimensions should be radius x radius.
        $this->assertEquals($radius, $i->width());
        $this->assertEquals($radius, $i->height());
    }

    public function testScaleWidth() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $origWidth = $i->width();
        $origHeight = $i->height();

        // Scale to specific width.
        $newWidth = 100;
        $i->scale($newWidth, NULL);

        // Width should be close to the target (may be rounded to even).
        $this->assertLessThanOrEqual($newWidth + 2, $i->width());
        $this->assertGreaterThanOrEqual($newWidth, $i->width());
    }

    public function testInvalidImage() {
        // Test with invalid image data.
        $i = new Image('not valid image data');

        // Should not throw, but getData should return NULL.
        $output = $i->getData();
        $this->assertNull($output);
    }

    public function testFillTransparent() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        // Should not throw.
        $i->fillTransparent();

        // Image should still be valid.
        $this->assertGreaterThan(0, $i->width());
        $this->assertGreaterThan(0, $i->height());
    }
}


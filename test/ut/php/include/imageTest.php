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

    public function testDuotone() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $origWidth = $i->width();
        $origHeight = $i->height();

        // Apply duotone with red to blue gradient.
        $i->duotone(255, 0, 0, 0, 0, 255);

        // Dimensions should not change.
        $this->assertEquals($origWidth, $i->width());
        $this->assertEquals($origHeight, $i->height());

        // Image should still be valid.
        $output = $i->getData();
        $this->assertNotNull($output);
        $this->assertGreaterThan(0, strlen($output));

        // Check that pixels are within the red-blue gradient.
        // Sample a few pixels to verify they're in the expected color range.
        $testImg = imagecreatefromstring($output);
        $this->assertNotFalse($testImg);

        // Sample center pixel.
        $rgb = imagecolorat($testImg, intval($origWidth / 2), intval($origHeight / 2));
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        // Green should be near zero (since our gradient is red to blue).
        $this->assertLessThan(30, $g);

        // Red and blue should complement each other (r + b should be ~255).
        $this->assertGreaterThan(200, $r + $b);
    }

    public function testDuotoneGreen() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $origWidth = $i->width();
        $origHeight = $i->height();

        // Apply standard green duotone.
        $i->duotoneGreen();

        // Dimensions should not change.
        $this->assertEquals($origWidth, $i->width());
        $this->assertEquals($origHeight, $i->height());

        // Image should still be valid.
        $output = $i->getData();
        $this->assertNotNull($output);
        $this->assertGreaterThan(0, strlen($output));

        // Check that pixels are within the green-white gradient.
        $testImg = imagecreatefromstring($output);
        $this->assertNotFalse($testImg);

        // Sample center pixel.
        $rgb = imagecolorat($testImg, intval($origWidth / 2), intval($origHeight / 2));
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        // For green duotone (dark green #0D3311 to white #FFFFFF):
        // - Green component should always be >= red component (since dark green has more G than R).
        // - Blue should be similar to red (both scale from low to 255).
        $this->assertGreaterThanOrEqual($r, $g);
    }

    public function testDuotoneWithInvalidImage() {
        // Test with invalid image data.
        $i = new Image('not valid image data');

        // Should not throw.
        $i->duotone(255, 0, 0, 0, 0, 255);

        // getData should still return NULL.
        $output = $i->getData();
        $this->assertNull($output);
    }

    public function testDuotoneBlackToWhite() {
        // Create a simple test image with known colors.
        $testImg = imagecreatetruecolor(10, 10);
        $black = imagecolorallocate($testImg, 0, 0, 0);
        $white = imagecolorallocate($testImg, 255, 255, 255);
        $gray = imagecolorallocate($testImg, 128, 128, 128);

        // Fill with specific colors.
        imagefilledrectangle($testImg, 0, 0, 3, 9, $black);
        imagefilledrectangle($testImg, 4, 0, 6, 9, $gray);
        imagefilledrectangle($testImg, 7, 0, 9, 9, $white);

        // Get image data.
        ob_start();
        imagejpeg($testImg, NULL, 100);
        $data = ob_get_contents();
        ob_end_clean();

        $i = new Image($data);

        // Apply duotone: black maps to red, white maps to blue.
        $i->duotone(255, 0, 0, 0, 0, 255);

        $output = $i->getData(100);
        $resultImg = imagecreatefromstring($output);

        // Check black area (should now be red).
        $rgb = imagecolorat($resultImg, 1, 5);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(50, $g);
        $this->assertLessThan(50, $b);

        // Check white area (should now be blue).
        $rgb = imagecolorat($resultImg, 8, 5);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $this->assertLessThan(50, $r);
        $this->assertLessThan(50, $g);
        $this->assertGreaterThan(200, $b);

        // Check gray area (should be purple-ish - mix of red and blue).
        $rgb = imagecolorat($resultImg, 5, 5);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $this->assertGreaterThan(80, $r);
        $this->assertLessThan(180, $r);
        $this->assertLessThan(50, $g);
        $this->assertGreaterThan(80, $b);
        $this->assertLessThan(180, $b);
    }
}


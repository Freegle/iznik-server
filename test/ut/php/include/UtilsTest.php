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
class UtilsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    public function testCheckFiles() {
        $dir = Utils::tmpdir();
        touch("$dir/1");
        touch("$dir/2");
        touch("$dir/3");

        $this->assertEquals(3, Utils::checkFiles($dir, 2, 1, 1, 1));
    }

    public function testSafeDate() {
        $this->assertEquals('2020-07-20 12:33:00', Utils::safeDate('2020-07-20 12:33:00'));
    }

    public function testMedian() {
        $this->assertEquals(2, Utils::calculate_median([1, 2, 3]));
        $this->assertEquals(2, Utils::calculate_median([1, 2, 2, 3]));
    }

    public function testlockScript() {
        $lockh = Utils::lockScript('ut');
        $this->assertNotNull($lockh);
        Utils::unlockScript($lockh);
    }

    /**
     * @dataProvider UTF8provider
     */
    public function testFilterResult($type, $val) {
        #error_log("Args " . var_export($args, TRUE));

        $ret = [
            $type => $val
        ];

        error_log("$type => $val");
        Utils::filterResult($ret, NULL);
        $enc = json_encode($ret, JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->assertNotNull($enc);
        error_log("Enc $enc");
        $dec = json_decode($enc, TRUE );
        $this->assertNotNull($dec);
        $this->assertTrue(array_key_exists($type, $dec));
    }

    public function UTF8provider() : \Generator {
        foreach ([
            [ 'Valid ASCII' , "a" ],
            [ 'Valid 2 Octet Sequence' , "\xc3\xb1" ],
            [ 'Invalid 2 Octet Sequence' , "\xc3\x28" ],
            [ 'Invalid Sequence Identifier' , "\xa0\xa1" ],
            [ 'Valid 3 Octet Sequence' , "\xe2\x82\xa1" ],
            [ 'Invalid 3 Octet Sequence (in 2nd Octet)' , "\xe2\x28\xa1" ],
            [ 'Invalid 3 Octet Sequence (in 3rd Octet)' , "\xe2\x82\x28" ],
            [ 'Valid 4 Octet Sequence' , "\xf0\x90\x8c\xbc" ],
            [ 'Invalid 4 Octet Sequence (in 2nd Octet)' , "\xf0\x28\x8c\xbc" ],
            [ 'Invalid 4 Octet Sequence (in 3rd Octet)' , "\xf0\x90\x28\xbc" ],
            [ 'Invalid 4 Octet Sequence (in 4th Octet)' , "\xf0\x28\x8c\x28" ],
            [ 'Valid 5 Octet Sequence (but not Unicode!)' , "\xf8\xa1\xa1\xa1\xa1" ],
            [ 'Valid 6 Octet Sequence (but not Unicode!)' , "\xfc\xa1\xa1\xa1\xa1\xa1" ],
        ] as $res) {
            yield $res;
        };
    }

    public function testDecodeEmojisNull() {
        $this->assertNull(Utils::decodeEmojis(NULL));
    }

    public function testDecodeEmojisEmptyString() {
        $this->assertEquals('', Utils::decodeEmojis(''));
    }

    public function testDecodeEmojisNoEmojis() {
        $input = 'Hello, this is a test message.';
        $this->assertEquals($input, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisSingleEmoji() {
        // \u1f600\u should decode to 😀
        $input = 'Hello \\u1f600\\u world';
        $expected = 'Hello 😀 world';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisMultipleEmojis() {
        // Multiple emojis in the same string.
        $input = '\\u1f600\\u test \\u2764\\u';
        $expected = '😀 test ❤';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisCompoundEmojiWithSkinTone() {
        // 👍🏻 = 1f44d-1f3fb (thumbs up with light skin tone)
        $input = 'Good job \\u1f44d-1f3fb\\u';
        $expected = 'Good job 👍🏻';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisFlagEmoji() {
        // 🇬🇧 = 1f1ec-1f1e7 (UK flag)
        $input = 'From \\u1f1ec-1f1e7\\u';
        $expected = 'From 🇬🇧';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisHeartWithVariationSelector() {
        // ❤️ = 2764-fe0f (red heart with variation selector)
        $input = 'Love \\u2764-fe0f\\u';
        $expected = 'Love ❤️';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisHighCodePoint() {
        // 🧡 = 1f9e1 (orange heart - outside BMP, this was the bug)
        $input = 'Orange \\u1f9e1\\u heart';
        $expected = 'Orange 🧡 heart';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisAdjacentEmojis() {
        $input = '\\u1f600\\u\\u2764\\u';
        $expected = '😀❤';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }

    public function testDecodeEmojisPreservesExistingUnicode() {
        // Already-decoded emojis or other Unicode should be preserved.
        $input = 'Hello 😀 and café \\u2764\\u';
        $expected = 'Hello 😀 and café ❤';
        $this->assertEquals($expected, Utils::decodeEmojis($input));
    }
}


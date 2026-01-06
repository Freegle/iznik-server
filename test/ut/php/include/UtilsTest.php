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

    public function testCodeToCountryValid() {
        $this->assertEquals('United Kingdom', Utils::code_to_country('GB'));
        $this->assertEquals('United States of America', Utils::code_to_country('US'));
        $this->assertEquals('France, French Republic', Utils::code_to_country('FR'));
    }

    public function testCodeToCountryLowerCase() {
        // Should handle lowercase codes.
        $this->assertEquals('United Kingdom', Utils::code_to_country('gb'));
        $this->assertEquals('Germany', Utils::code_to_country('de'));
    }

    public function testCodeToCountryInvalid() {
        // Invalid code should return the code itself.
        $this->assertEquals('XX', Utils::code_to_country('XX'));
        $this->assertEquals('ZZ', Utils::code_to_country('ZZ'));
    }

    public function testPresWithValue() {
        $arr = ['key' => 'value', 'empty' => ''];
        $this->assertEquals('value', Utils::pres('key', $arr));
    }

    public function testPresMissingKey() {
        $arr = ['key' => 'value'];
        $this->assertFalse(Utils::pres('missing', $arr));
    }

    public function testPresEmptyValue() {
        $arr = ['empty' => ''];
        $this->assertFalse(Utils::pres('empty', $arr));
    }

    public function testPresNullArray() {
        $this->assertFalse(Utils::pres('key', NULL));
    }

    public function testPresdefWithValue() {
        $arr = ['key' => 'value'];
        $this->assertEquals('value', Utils::presdef('key', $arr, 'default'));
    }

    public function testPresdefMissingKey() {
        $arr = ['key' => 'value'];
        $this->assertEquals('default', Utils::presdef('missing', $arr, 'default'));
    }

    public function testPresintWithValue() {
        $arr = ['num' => '42', 'float' => '3.14'];
        $this->assertEquals(42, Utils::presint('num', $arr, 0));
        $this->assertEquals(3, Utils::presint('float', $arr, 0));
    }

    public function testPresintMissingKey() {
        $arr = ['key' => 'value'];
        $this->assertEquals(99, Utils::presint('missing', $arr, 99));
    }

    public function testPresfloatWithValue() {
        $arr = ['float' => '3.14'];
        $this->assertEqualsWithDelta(3.14, Utils::presfloat('float', $arr, 0), 0.001);
    }

    public function testPresfloatMissingKey() {
        $arr = ['key' => 'value'];
        $this->assertEqualsWithDelta(1.5, Utils::presfloat('missing', $arr, 1.5), 0.001);
    }

    public function testPresboolTrue() {
        $arr = ['bool' => 'true', 'one' => '1', 'yes' => 'yes'];
        $this->assertTrue(Utils::presbool('bool', $arr, FALSE));
        $this->assertTrue(Utils::presbool('one', $arr, FALSE));
        $this->assertTrue(Utils::presbool('yes', $arr, FALSE));
    }

    public function testPresboolFalse() {
        $arr = ['bool' => 'false', 'zero' => '0', 'no' => 'no'];
        $this->assertFalse(Utils::presbool('bool', $arr, TRUE));
        $this->assertFalse(Utils::presbool('zero', $arr, TRUE));
        $this->assertFalse(Utils::presbool('no', $arr, TRUE));
    }

    public function testPresboolDefault() {
        $arr = [];
        $this->assertTrue(Utils::presbool('missing', $arr, TRUE));
        $this->assertFalse(Utils::presbool('missing', $arr, FALSE));
    }

    public function testISODate() {
        $result = Utils::ISODate('2025-01-01 12:00:00');
        $this->assertStringContainsString('2025-01-01', $result);
        $this->assertStringContainsString('T12:00:00', $result);
    }

    public function testISODateNull() {
        $this->assertNull(Utils::ISODate(NULL));
    }

    public function testRandstrLength() {
        $result = Utils::randstr(20);
        $this->assertEquals(20, strlen($result));

        $result = Utils::randstr(5);
        $this->assertEquals(5, strlen($result));
    }

    public function testRandstrDefaultLength() {
        $result = Utils::randstr();
        $this->assertEquals(10, strlen($result));
    }

    public function testCanonWordSimple() {
        $this->assertEquals('abc', Utils::canonWord('ABC'));
        $this->assertEquals('abc', Utils::canonWord('abc'));
    }

    public function testCanonWordSortsLongWords() {
        // Words > 3 chars are sorted alphabetically.
        $this->assertEquals('abcd', Utils::canonWord('dcba'));
        $this->assertEquals('ehllo', Utils::canonWord('hello'));
    }

    public function testCanonWordStripsNonAlphanumeric() {
        // Non-alphanumeric stripped, then sorted (length > 3).
        $this->assertEquals('123abc', Utils::canonWord('abc-123!'));
    }

    public function testCanonSentence() {
        $result = Utils::canonSentence('Hello World');
        $this->assertContains('ehllo', $result);
        $this->assertContains('dlorw', $result);
    }

    public function testWordsInCommonIdentical() {
        $percent = Utils::wordsInCommon('hello world', 'hello world');
        $this->assertEquals(100, $percent);
    }

    public function testWordsInCommonNoMatch() {
        $percent = Utils::wordsInCommon('hello world', 'foo bar');
        $this->assertEquals(0, $percent);
    }

    public function testWordsInCommonPartial() {
        $percent = Utils::wordsInCommon('hello world', 'hello foo');
        $this->assertGreaterThan(0, $percent);
        $this->assertLessThan(100, $percent);
    }

    public function testBlurNoBlur() {
        list($lat, $lng) = Utils::blur(51.5074, -0.1278, NULL);
        $this->assertEqualsWithDelta(51.5074, $lat, 0.0001);
        $this->assertEqualsWithDelta(-0.1278, $lng, 0.0001);
    }

    public function testBlurWithDistance() {
        list($lat, $lng) = Utils::blur(51.5074, -0.1278, 1000);
        // Should be different from original.
        $this->assertNotEquals(51.5074, $lat);
    }

    public function testGetBoxPoly() {
        $result = Utils::getBoxPoly(51.0, -0.5, 52.0, 0.5);
        $this->assertStringContainsString('POLYGON', $result);
        $this->assertStringContainsString('51', $result);
        $this->assertStringContainsString('52', $result);
    }

    public function testLevensteinSubstringContainsExact() {
        $this->assertTrue(Utils::levensteinSubstringContains('hello', 'say hello world'));
    }

    public function testLevensteinSubstringContainsFuzzy() {
        // 'helo' is 1 edit from 'hello'.
        $this->assertTrue(Utils::levensteinSubstringContains('helo', 'say hello world', 1));
    }

    public function testLevensteinSubstringContainsTooFar() {
        // 'hxxx' is too far from 'hello'.
        $this->assertFalse(Utils::levensteinSubstringContains('hxxx', 'say hello world', 1));
    }

    public function testLevensteinSubstringContainsCaseInsensitive() {
        $this->assertTrue(Utils::levensteinSubstringContains('HELLO', 'say hello world', 0, TRUE));
    }

    public function testLevensteinSubstringContainsNeedleTooLong() {
        $this->assertFalse(Utils::levensteinSubstringContains('verylongstring', 'short'));
    }
}


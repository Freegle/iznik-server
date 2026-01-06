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
class DonationsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testGetExcludedPayersConditionDefault() {
        // Test that the function returns a valid SQL condition.
        $condition = Donations::getExcludedPayersCondition();

        // Should contain the default field 'payer'.
        $this->assertStringContainsString('payer', $condition);

        // Should be wrapped in parentheses.
        $this->assertStringStartsWith('(', $condition);
        $this->assertStringEndsWith(')', $condition);

        // Should contain != for exclusion.
        $this->assertStringContainsString('!=', $condition);
    }

    public function testGetExcludedPayersConditionCustomField() {
        // Test with a custom field name.
        $condition = Donations::getExcludedPayersCondition('email_address');

        // Should contain the custom field name.
        $this->assertStringContainsString('email_address', $condition);

        // Should NOT contain the default 'payer'.
        $this->assertStringNotContainsString('payer !=', $condition);
    }

    public function testIsExcludedPayerDefaultExcluded() {
        // The default excluded email should return TRUE.
        $this->assertTrue(Donations::isExcludedPayer('ppgfukpay@paypalgivingfund.org'));
    }

    public function testIsExcludedPayerNormalEmail() {
        // Normal email addresses should not be excluded.
        $this->assertFalse(Donations::isExcludedPayer('user@example.com'));
        $this->assertFalse(Donations::isExcludedPayer('donor@gmail.com'));
        $this->assertFalse(Donations::isExcludedPayer('test@test.com'));
    }

    public function testIsExcludedPayerCaseSensitivity() {
        // Check case sensitivity - should be case sensitive.
        $this->assertFalse(Donations::isExcludedPayer('PPGFUKPAY@paypalgivingfund.org'));
        $this->assertFalse(Donations::isExcludedPayer('PPGFUKPay@PayPalGivingFund.org'));
    }

    public function testIsExcludedPayerEmptyString() {
        // Empty string should not be excluded.
        $this->assertFalse(Donations::isExcludedPayer(''));
    }

    public function testIsExcludedPayerWhitespace() {
        // Email with extra whitespace should not match.
        $this->assertFalse(Donations::isExcludedPayer(' ppgfukpay@paypalgivingfund.org '));
    }

    public function testGetExcludedPayersConditionSQLValid() {
        // The condition should be usable in a SQL query.
        $condition = Donations::getExcludedPayersCondition('email');

        // Should produce valid SQL syntax with AND for multiple exclusions.
        // At minimum it should have the basic structure.
        $this->assertMatchesRegularExpression('/^\([^)]+\)$/', $condition);
    }
}

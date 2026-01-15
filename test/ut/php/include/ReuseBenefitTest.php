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
class ReuseBenefitTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp(): void {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        // Clear cache before each test.
        ReuseBenefit::clearCache();
    }

    protected function tearDown(): void {
        ReuseBenefit::clearCache();
        parent::tearDown();
    }

    public function testFallbackCPIData() {
        // Without a database connection, should use fallback data.
        $cpiData = ReuseBenefit::getCPIData(NULL);
        $this->assertIsArray($cpiData);
        $this->assertArrayHasKey(2011, $cpiData);
        $this->assertArrayHasKey(2024, $cpiData);
        $this->assertEquals(93.4, $cpiData[2011]);
    }

    public function testGetCPIForBaseYear() {
        $cpi = ReuseBenefit::getCPI(2011);
        $this->assertEquals(93.4, $cpi);
    }

    public function testGetCPIForRecentYear() {
        $cpi = ReuseBenefit::getCPI(2024);
        $this->assertEquals(133.9, $cpi);
    }

    public function testGetCPIForFutureYear() {
        // Future year should return the latest available.
        $cpi = ReuseBenefit::getCPI(2030);
        $latestYear = ReuseBenefit::getLatestCPIYear();
        $latestCPI = ReuseBenefit::getCPI($latestYear);
        $this->assertEquals($latestCPI, $cpi);
    }

    public function testGetCPIForPastYear() {
        // Year before data starts should return the earliest available.
        $cpi = ReuseBenefit::getCPI(2000);
        $this->assertEquals(93.4, $cpi); // 2011 is the earliest.
    }

    public function testGetInflationMultiplier() {
        // Multiplier from 2011 to 2024.
        $multiplier = ReuseBenefit::getInflationMultiplier(2024);
        $expected = 133.9 / 93.4;
        $this->assertEqualsWithDelta($expected, $multiplier, 0.001);
    }

    public function testGetInflationMultiplierSameYear() {
        // Multiplier from 2011 to 2011 should be 1.0.
        $multiplier = ReuseBenefit::getInflationMultiplier(2011);
        $this->assertEqualsWithDelta(1.0, $multiplier, 0.001);
    }

    public function testGetBenefitPerTonne() {
        // In 2011, should be the base value.
        $benefit = ReuseBenefit::getBenefitPerTonne(2011);
        $this->assertEquals(711, $benefit);
    }

    public function testGetBenefitPerTonneInflationAdjusted() {
        // In 2024, should be adjusted for inflation.
        $benefit = ReuseBenefit::getBenefitPerTonne(2024);
        $expectedMultiplier = 133.9 / 93.4;
        $expected = round(711 * $expectedMultiplier);
        $this->assertEquals($expected, $benefit);
    }

    public function testCalculateBenefit() {
        // 10 tonnes at 2011 prices.
        $benefit = ReuseBenefit::calculateBenefit(10, 2011);
        $this->assertEquals(7110, $benefit); // 10 * 711
    }

    public function testCalculateBenefitZeroWeight() {
        $benefit = ReuseBenefit::calculateBenefit(0, 2024);
        $this->assertEquals(0, $benefit);
    }

    public function testCalculateBenefitFractionalWeight() {
        // 0.5 tonnes at 2011 prices.
        $benefit = ReuseBenefit::calculateBenefit(0.5, 2011);
        $this->assertEquals(355.5, $benefit); // 0.5 * 711
    }

    public function testCalculateCO2() {
        // 10 tonnes.
        $co2 = ReuseBenefit::calculateCO2(10);
        $this->assertEquals(5.1, $co2); // 10 * 0.51
    }

    public function testCalculateCO2ZeroWeight() {
        $co2 = ReuseBenefit::calculateCO2(0);
        $this->assertEquals(0, $co2);
    }

    public function testCalculateCO2FractionalWeight() {
        // 0.5 tonnes.
        $co2 = ReuseBenefit::calculateCO2(0.5);
        $this->assertEquals(0.255, $co2); // 0.5 * 0.51
    }

    public function testGetLatestCPIYear() {
        $latestYear = ReuseBenefit::getLatestCPIYear();
        $this->assertEquals(2024, $latestYear);
    }

    public function testCaching() {
        // First call should populate cache.
        $data1 = ReuseBenefit::getCPIData(NULL);
        // Second call should return cached data.
        $data2 = ReuseBenefit::getCPIData(NULL);
        $this->assertEquals($data1, $data2);
    }

    public function testClearCache() {
        // Populate cache.
        ReuseBenefit::getCPIData(NULL);
        // Clear and repopulate.
        ReuseBenefit::clearCache();
        $data = ReuseBenefit::getCPIData(NULL);
        $this->assertIsArray($data);
    }

    public function testGetCPIDataFromDatabase() {
        // Store CPI data in config table.
        $testData = [
            'data' => [
                '2011' => 93.4,
                '2020' => 108.7,
                '2025' => 140.0, // Test data not in fallback.
            ],
            'source' => 'test',
            'updated' => date('Y-m-d H:i:s')
        ];

        $this->dbhm->preExec("DELETE FROM config WHERE `key` = ?", [ReuseBenefit::CONFIG_KEY]);
        $this->dbhm->preExec("INSERT INTO config (`key`, value) VALUES (?, ?)",
            [ReuseBenefit::CONFIG_KEY, json_encode($testData)]);

        ReuseBenefit::clearCache();
        $cpiData = ReuseBenefit::getCPIData($this->dbhr);

        $this->assertArrayHasKey(2025, $cpiData);
        $this->assertEquals(140.0, $cpiData[2025]);

        // Clean up.
        $this->dbhm->preExec("DELETE FROM config WHERE `key` = ?", [ReuseBenefit::CONFIG_KEY]);
    }

    public function testGetCPIDataFromDatabaseWithInvalidJSON() {
        // Store invalid JSON in config table.
        $this->dbhm->preExec("DELETE FROM config WHERE `key` = ?", [ReuseBenefit::CONFIG_KEY]);
        $this->dbhm->preExec("INSERT INTO config (`key`, value) VALUES (?, ?)",
            [ReuseBenefit::CONFIG_KEY, 'not valid json']);

        ReuseBenefit::clearCache();
        $cpiData = ReuseBenefit::getCPIData($this->dbhr);

        // Should fall back to hardcoded data.
        $this->assertArrayHasKey(2011, $cpiData);
        $this->assertEquals(93.4, $cpiData[2011]);

        // Clean up.
        $this->dbhm->preExec("DELETE FROM config WHERE `key` = ?", [ReuseBenefit::CONFIG_KEY]);
    }

    public function testGetBenefitPerTonneWithDatabase() {
        // Test that database integration works for benefit calculation.
        ReuseBenefit::clearCache();
        $benefit = ReuseBenefit::getBenefitPerTonne(2024, $this->dbhr);
        $this->assertGreaterThan(711, $benefit); // Should be inflation adjusted.
    }
}

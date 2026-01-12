<?php

namespace Freegle\Iznik;

/**
 * Reuse benefit calculation with inflation adjustment.
 *
 * The base benefit of reuse value (£711 per tonne) comes from the WRAP
 * "Benefits of Reuse" tool, originally published in 2011.
 * https://www.wrap.ngo/resources/tool/benefits-of-reuse-tool
 *
 * To express this value in current prices, we up-rate by UK CPI inflation.
 * CPI data source: ONS Consumer Price Index (2015=100)
 * https://www.ons.gov.uk/economy/inflationandpriceindices/timeseries/d7bt/mm23
 *
 * CPI data is fetched monthly by iznik-batch and stored in the config table.
 * The hardcoded values serve as a fallback if the config table is empty.
 *
 * The CO2 impact (0.51 tCO2eq per tonne) is not adjusted for inflation as it
 * represents physical quantities, not monetary values.
 */
class ReuseBenefit
{
    /**
     * Config table key for CPI data (matches iznik-batch CPIService).
     */
    const CONFIG_KEY = 'cpi_annual_data';

    /**
     * Hardcoded fallback UK CPI Annual Averages (2015=100).
     * Source: ONS MM23 dataset, series D7BT.
     * Last updated: January 2025.
     *
     * These values are used when config table is empty or database unavailable.
     * The iznik-batch scheduled task updates the config table monthly.
     */
    const FALLBACK_CPI_DATA = [
        2011 => 93.4,
        2012 => 96.1,
        2013 => 98.5,
        2014 => 100.0,
        2015 => 100.0,
        2016 => 100.7,
        2017 => 103.4,
        2018 => 105.9,
        2019 => 107.8,
        2020 => 108.7,
        2021 => 111.6,
        2022 => 121.7,
        2023 => 130.5,
        2024 => 133.9,
    ];

    /** Base year for the WRAP benefits of reuse value. */
    const BASE_YEAR = 2011;

    /** Base benefit of reuse value in GBP per tonne (2011 prices). */
    const BASE_BENEFIT_PER_TONNE = 711;

    /** CO2 impact per tonne (tCO2eq) - not inflation adjusted. */
    const CO2_PER_TONNE = 0.51;

    /** Cached CPI data from config table. */
    private static $cachedCPIData = NULL;

    /**
     * Get CPI data from config table or fallback.
     *
     * @param LoggedPDO|null $dbhr Database handle (optional - uses fallback if not provided)
     * @return array Associative array of year => CPI value
     */
    public static function getCPIData($dbhr = NULL)
    {
        // Return cached data if available.
        if (self::$cachedCPIData !== NULL) {
            return self::$cachedCPIData;
        }

        // Try to read from config table if database handle provided.
        if ($dbhr) {
            try {
                $rows = $dbhr->preQuery(
                    "SELECT value FROM config WHERE `key` = ?",
                    [self::CONFIG_KEY]
                );

                if (count($rows) > 0 && !empty($rows[0]['value'])) {
                    $decoded = json_decode($rows[0]['value'], TRUE);
                    if (isset($decoded['data']) && is_array($decoded['data'])) {
                        // Convert string keys to integers.
                        $data = [];
                        foreach ($decoded['data'] as $year => $value) {
                            $data[(int)$year] = (float)$value;
                        }
                        self::$cachedCPIData = $data;
                        return $data;
                    }
                }
            } catch (\Exception $e) {
                // Fall through to use fallback data.
                error_log("ReuseBenefit: Failed to read CPI data from config: " . $e->getMessage());
            }
        }

        // Use fallback data.
        self::$cachedCPIData = self::FALLBACK_CPI_DATA;
        return self::FALLBACK_CPI_DATA;
    }

    /**
     * Clear cached CPI data (useful for testing).
     */
    public static function clearCache()
    {
        self::$cachedCPIData = NULL;
    }

    /**
     * Get the CPI value for a given year.
     * Returns the latest available year if the requested year is in the future.
     * Returns the earliest available year if the requested year is before 2011.
     *
     * @param int $year Target year
     * @param LoggedPDO|null $dbhr Database handle (optional)
     * @return float CPI value for the year
     */
    public static function getCPI($year, $dbhr = NULL)
    {
        $cpiData = self::getCPIData($dbhr);
        $years = array_keys($cpiData);
        $minYear = min($years);
        $maxYear = max($years);

        if ($year < $minYear) {
            return $cpiData[$minYear];
        }
        if ($year > $maxYear) {
            return $cpiData[$maxYear];
        }
        return $cpiData[$year];
    }

    /**
     * Calculate the inflation multiplier from the base year to the target year.
     *
     * @param int|null $targetYear Target year (defaults to current year)
     * @param LoggedPDO|null $dbhr Database handle (optional)
     * @return float Inflation multiplier
     */
    public static function getInflationMultiplier($targetYear = NULL, $dbhr = NULL)
    {
        $year = $targetYear ?? (int)date('Y');
        $baseCPI = self::getCPI(self::BASE_YEAR, $dbhr);
        $targetCPI = self::getCPI($year, $dbhr);
        return $targetCPI / $baseCPI;
    }

    /**
     * Get the inflation-adjusted benefit per tonne in GBP.
     * By default, adjusts to current year prices.
     *
     * @param int|null $targetYear Target year (defaults to current year)
     * @param LoggedPDO|null $dbhr Database handle (optional)
     * @return int Benefit per tonne in GBP (rounded)
     */
    public static function getBenefitPerTonne($targetYear = NULL, $dbhr = NULL)
    {
        $multiplier = self::getInflationMultiplier($targetYear, $dbhr);
        return round(self::BASE_BENEFIT_PER_TONNE * $multiplier);
    }

    /**
     * Calculate the total benefit in GBP for a given weight in tonnes.
     * Weight should be in tonnes (not kg).
     *
     * @param float $weightInTonnes Weight in tonnes
     * @param int|null $targetYear Target year (defaults to current year)
     * @param LoggedPDO|null $dbhr Database handle (optional)
     * @return float Total benefit in GBP
     */
    public static function calculateBenefit($weightInTonnes, $targetYear = NULL, $dbhr = NULL)
    {
        return $weightInTonnes * self::getBenefitPerTonne($targetYear, $dbhr);
    }

    /**
     * Calculate the CO2 saved for a given weight in tonnes.
     * Returns tCO2eq. Weight should be in tonnes.
     *
     * @param float $weightInTonnes Weight in tonnes
     * @return float CO2 saved in tCO2eq
     */
    public static function calculateCO2($weightInTonnes)
    {
        return $weightInTonnes * self::CO2_PER_TONNE;
    }

    /**
     * Get the latest year for which we have CPI data.
     *
     * @param LoggedPDO|null $dbhr Database handle (optional)
     * @return int Latest year
     */
    public static function getLatestCPIYear($dbhr = NULL)
    {
        $cpiData = self::getCPIData($dbhr);
        return max(array_keys($cpiData));
    }
}

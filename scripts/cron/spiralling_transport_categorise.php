#!/usr/bin/env php
<?php
/**
 * Download and process transport data for UK postcodes
 *
 * This script:
 * 1. Downloads the ONS Postcode Directory (ONSPD)
 * 2. Extracts and parses postcode data with Rural-Urban Classification
 * 3. Populates transport_postcode_classification table (online, no downtime)
 * 4. Populates transport_duration_model with UK travel speed data
 * 5. Populates transport_mode_probabilities with NTS data
 *
 * Run monthly to keep data current (ONSPD updated quarterly)
 *
 * The script uses INSERT ... ON DUPLICATE KEY UPDATE to keep tables
 * live during import, then removes stale entries at the end.
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

class TransportCategoriser {
    private $dbhr;
    private $dbhm;
    private $downloadDir;
    private $importStartTime;
    private $onspd_url = 'https://www.arcgis.com/sharing/rest/content/items/265778cd85754b7e97f404a1c63aea04/data';

    public function __construct($dbhr, $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->downloadDir = sys_get_temp_dir() . '/transport_data';

        // Record import start time for cleanup later
        $this->importStartTime = date('Y-m-d H:i:s');

        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0755, true);
        }
    }

    public function run() {
        echo "Transport Categorisation Script\n";
        echo "===============================\n";
        echo "Import started at: {$this->importStartTime}\n\n";

        // Step 1: Download ONSPD
        echo "Step 1: Downloading ONSPD data...\n";
        $zipFile = $this->downloadONSPD();
        if (!$zipFile) {
            echo "ERROR: Failed to download ONSPD\n";
            return false;
        }

        // Step 2: Extract ONSPD
        echo "\nStep 2: Extracting ONSPD archive...\n";
        $csvFile = $this->extractONSPD($zipFile);
        if (!$csvFile) {
            echo "ERROR: Failed to extract ONSPD\n";
            return false;
        }

        // Step 3: Import postcode data
        echo "\nStep 3: Importing postcode classifications (online)...\n";
        $this->importPostcodeClassifications($csvFile);

        // Step 4: Populate duration model
        echo "\nStep 4: Populating transport duration model (online)...\n";
        $this->populateDurationModel();

        // Step 5: Populate mode probabilities
        echo "\nStep 5: Populating transport mode probabilities (online)...\n";
        $this->populateModeProbabilities();

        // Step 6: Clean up
        echo "\nStep 6: Cleaning up temporary files...\n";
        $this->cleanup();

        echo "\n✓ Transport categorisation complete!\n";
        return true;
    }

    /**
     * Download ONSPD from ONS
     */
    private function downloadONSPD() {
        echo "  Downloading from ONS ArcGIS Portal...\n";
        echo "  URL: " . $this->onspd_url . "\n";

        $zipFile = $this->downloadDir . '/onspd.zip';

        // Use curl for better progress tracking
        $ch = curl_init($this->onspd_url);
        $fp = fopen($zipFile, 'w');

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // 1 hour timeout
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) {
            if ($download_size > 0) {
                $percent = round(($downloaded / $download_size) * 100, 1);
                echo "\r  Progress: {$percent}% (" . $this->formatBytes($downloaded) . " / " . $this->formatBytes($download_size) . ")";
            }
        });

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);
        fclose($fp);

        if (!$result || $httpCode != 200) {
            echo "\n  ERROR: HTTP {$httpCode}\n";
            return false;
        }

        $size = filesize($zipFile);
        echo "\n  ✓ Downloaded: " . $this->formatBytes($size) . "\n";

        return $zipFile;
    }

    /**
     * Extract ONSPD zip file
     */
    private function extractONSPD($zipFile) {
        if (!file_exists($zipFile)) {
            echo "  ERROR: Zip file not found: {$zipFile}\n";
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            echo "  ERROR: Failed to open zip file\n";
            return false;
        }

        echo "  Archive contains " . $zip->numFiles . " files\n";

        // Find the main ONSPD CSV file (usually Data/ONSPD_*.csv)
        $csvFile = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Look for the main data file (not Code History or Documents)
            if (preg_match('/Data\/ONSPD_[A-Z]{3}_\d{4}_UK\.csv$/i', $filename)) {
                echo "  Found main data file: {$filename}\n";

                $extractPath = $this->downloadDir . '/onspd_data.csv';
                $zip->extractTo($this->downloadDir, $filename);

                // Move to expected location
                rename($this->downloadDir . '/' . $filename, $extractPath);

                $csvFile = $extractPath;
                break;
            }
        }

        $zip->close();

        if (!$csvFile || !file_exists($csvFile)) {
            echo "  ERROR: Could not find main ONSPD CSV file in archive\n";
            return false;
        }

        $size = filesize($csvFile);
        echo "  ✓ Extracted: " . $this->formatBytes($size) . "\n";

        return $csvFile;
    }

    /**
     * Import postcode classifications from ONSPD CSV (online, no downtime)
     */
    private function importPostcodeClassifications($csvFile) {
        echo "  Using INSERT ... ON DUPLICATE KEY UPDATE (tables stay live)\n";
        echo "  Reading CSV file...\n";

        $fp = fopen($csvFile, 'r');
        if (!$fp) {
            echo "  ERROR: Could not open CSV file\n";
            return false;
        }

        // Read header
        $header = fgetcsv($fp);
        if (!$header) {
            echo "  ERROR: Could not read CSV header\n";
            fclose($fp);
            return false;
        }

        // Find column indices
        $columns = array_flip($header);
        $pcodeIdx = $columns['pcd'] ?? $columns['pcds'] ?? null;
        $ru11Idx = $columns['ru11ind'] ?? null;
        $rgnIdx = $columns['rgn'] ?? $columns['gor'] ?? null;
        $latIdx = $columns['lat'] ?? null;
        $lngIdx = $columns['long'] ?? $columns['lng'] ?? null;

        if ($pcodeIdx === null) {
            echo "  ERROR: Could not find postcode column in CSV\n";
            echo "  Available columns: " . implode(', ', $header) . "\n";
            fclose($fp);
            return false;
        }

        echo "  Column mapping:\n";
        echo "    Postcode: column {$pcodeIdx} (" . $header[$pcodeIdx] . ")\n";
        echo "    RU11IND: column " . ($ru11Idx ?? 'NOT FOUND') . "\n";
        echo "    Region: column " . ($rgnIdx ?? 'NOT FOUND') . "\n";
        echo "    Lat: column " . ($latIdx ?? 'NOT FOUND') . "\n";
        echo "    Lng: column " . ($lngIdx ?? 'NOT FOUND') . "\n";

        // Region name mapping
        $regionNames = [
            'E12000001' => 'North East',
            'E12000002' => 'North West',
            'E12000003' => 'Yorkshire and The Humber',
            'E12000004' => 'East Midlands',
            'E12000005' => 'West Midlands',
            'E12000006' => 'East of England',
            'E12000007' => 'London',
            'E12000008' => 'South East',
            'E12000009' => 'South West',
            'W92000004' => 'Wales',
            'S92000003' => 'Scotland',
            'N92000002' => 'Northern Ireland'
        ];

        $count = 0;
        $inserted = 0;
        $updated = 0;
        $errors = 0;

        echo "  Processing postcodes...\n";

        while (($row = fgetcsv($fp)) !== false) {
            $count++;

            // Progress indicator every 1000 postcodes
            if ($count % 1000 == 0) {
                $mem = $this->formatBytes(memory_get_usage());
                echo "\r  Processed: " . number_format($count) . " postcodes (inserted/updated: " . number_format($inserted + $updated) . ", memory: {$mem})";
            }

            try {
                $postcode = $row[$pcodeIdx] ?? null;
                if (!$postcode || trim($postcode) == '') {
                    continue;
                }

                $postcodeNoSpace = str_replace(' ', '', $postcode);
                $ruCategory = ($ru11Idx !== null && isset($row[$ru11Idx])) ? $row[$ru11Idx] : null;
                $regionCode = ($rgnIdx !== null && isset($row[$rgnIdx])) ? $row[$rgnIdx] : null;
                $regionName = isset($regionNames[$regionCode]) ? $regionNames[$regionCode] : null;
                $lat = ($latIdx !== null && isset($row[$latIdx])) ? $row[$latIdx] : null;
                $lng = ($lngIdx !== null && isset($row[$lngIdx])) ? $row[$lngIdx] : null;

                // Only import if we have RU category (England/Wales)
                if ($ruCategory && $ruCategory != '' && $regionCode && $regionCode != '') {
                    $rc = $this->dbhm->preExec("
                        INSERT INTO transport_postcode_classification
                        (postcode, postcode_space, ru_category, region_code, region_name, lat, lng, last_seen)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            postcode_space = VALUES(postcode_space),
                            ru_category = VALUES(ru_category),
                            region_code = VALUES(region_code),
                            region_name = VALUES(region_name),
                            lat = VALUES(lat),
                            lng = VALUES(lng),
                            last_seen = VALUES(last_seen)
                    ", [
                        $postcodeNoSpace,
                        $postcode,
                        $ruCategory,
                        $regionCode,
                        $regionName,
                        $lat,
                        $lng,
                        $this->importStartTime
                    ]);

                    if ($rc == 1) {
                        $inserted++;
                    } elseif ($rc == 2) {
                        $updated++;
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors < 10) {
                    echo "\n  Warning: Error on row {$count}: " . $e->getMessage() . "\n";
                }
            }
        }

        fclose($fp);

        echo "\n  ✓ Import complete:\n";
        echo "    Total rows processed: " . number_format($count) . "\n";
        echo "    New postcodes inserted: " . number_format($inserted) . "\n";
        echo "    Existing postcodes updated: " . number_format($updated) . "\n";
        echo "    Errors: " . number_format($errors) . "\n";

        // Show sample of imported data
        $sample = $this->dbhr->preQuery("
            SELECT postcode_space, ru_category, region_name
            FROM transport_postcode_classification
            WHERE last_seen = ?
            LIMIT 5
        ", [$this->importStartTime]);

        echo "    Sample data:\n";
        foreach ($sample as $row) {
            echo "      {$row['postcode_space']} -> {$row['ru_category']} ({$row['region_name']})\n";
        }

        // Clean up stale entries
        echo "  Removing stale postcodes (not in current ONSPD)...\n";
        $result = $this->dbhm->preExec("
            DELETE FROM transport_postcode_classification
            WHERE last_seen < ?
        ", [$this->importStartTime]);
        echo "    Removed " . number_format($result) . " stale postcodes\n";

        // Show final count
        $counts = $this->dbhr->preQuery("SELECT COUNT(*) AS count FROM transport_postcode_classification");
        $total = $counts[0]['count'];
        echo "  ✓ Final postcode count: " . number_format($total) . "\n";

        return true;
    }

    /**
     * Populate transport duration model with UK speed data (online)
     */
    private function populateDurationModel() {
        echo "  Using INSERT ... ON DUPLICATE KEY UPDATE (table stays live)\n";

        // Based on our research:
        // - NTS 2024: Walk 18min, Cycle 24min, Drive 22min average
        // - Urban drive speed: 16.3 mph (DfT 2023)
        // - Rural drive speed: 33.4 mph (DfT 2023)
        // - Walk speed: 3 mph standard
        // - Cycle: 10 mph urban, 12.6 mph rural

        $ruDescriptions = [
            'A1' => 'Urban: Major Conurbation',
            'B1' => 'Urban: Minor Conurbation',
            'C1' => 'Urban: City and Town',
            'C2' => 'Urban: City and Town (Sparse)',
            'D1' => 'Rural: Town and Fringe',
            'D2' => 'Rural: Town and Fringe (Sparse)',
            'E1' => 'Rural: Village',
            'E2' => 'Rural: Village (Sparse)',
            'F1' => 'Rural: Hamlets and Isolated Dwellings',
            'F2' => 'Rural: Hamlets and Isolated Dwellings (Sparse)'
        ];

        $regions = [
            'E12000001' => 'North East',
            'E12000002' => 'North West',
            'E12000003' => 'Yorkshire and The Humber',
            'E12000004' => 'East Midlands',
            'E12000005' => 'West Midlands',
            'E12000006' => 'East of England',
            'E12000007' => 'London',
            'E12000008' => 'South East',
            'E12000009' => 'South West',
            'W92000004' => 'Wales',
            'S92000003' => 'Scotland',
            'N92000002' => 'Northern Ireland'
        ];

        $count = 0;

        foreach ($ruDescriptions as $ruCat => $description) {
            // Determine if urban or rural
            $isUrban = in_array($ruCat, ['A1', 'B1', 'C1', 'C2']);
            $isSparse = in_array($ruCat, ['C2', 'D2', 'E2', 'F2']);

            // Set speeds based on area type
            if ($ruCat == 'A1') {
                // Major conurbation (e.g., London)
                $walkSpeed = 3.0;
                $cycleSpeed = 9.0;  // Slower due to traffic
                $driveSpeed = 12.0;  // Very slow (London average)
                $timeAdj = 1.0;
                $avgWalk = 0.6;
                $avgCycle = 2.1;
                $avgDrive = 5.2;
            } elseif ($ruCat == 'B1') {
                // Minor conurbation
                $walkSpeed = 3.0;
                $cycleSpeed = 10.0;
                $driveSpeed = 15.0;
                $timeAdj = 1.0;
                $avgWalk = 0.7;
                $avgCycle = 2.3;
                $avgDrive = 5.8;
            } elseif ($ruCat == 'C1' || $ruCat == 'C2') {
                // Urban city/town
                $walkSpeed = 3.1;
                $cycleSpeed = 10.5;
                $driveSpeed = 16.3;  // DfT urban average
                $timeAdj = $isSparse ? 1.10 : 1.0;
                $avgWalk = 0.7;
                $avgCycle = 2.7;
                $avgDrive = 6.5;
            } elseif ($ruCat == 'D1' || $ruCat == 'D2') {
                // Rural town/fringe
                $walkSpeed = 3.5;
                $cycleSpeed = 11.5;
                $driveSpeed = 25.0;
                $timeAdj = $isSparse ? 1.25 : 1.20;
                $avgWalk = 0.8;
                $avgCycle = 3.2;
                $avgDrive = 8.0;
            } elseif ($ruCat == 'E1' || $ruCat == 'E2') {
                // Rural village
                $walkSpeed = 3.5;
                $cycleSpeed = 12.0;
                $driveSpeed = 30.0;
                $timeAdj = $isSparse ? 1.35 : 1.30;
                $avgWalk = 1.0;
                $avgCycle = 3.8;
                $avgDrive = 10.0;
            } else {
                // Rural hamlets/isolated (F1, F2)
                $walkSpeed = 3.5;
                $cycleSpeed = 12.6;
                $driveSpeed = 33.4;  // DfT rural average
                $timeAdj = $isSparse ? 1.40 : 1.33;
                $avgWalk = 1.2;
                $avgCycle = 4.2;
                $avgDrive = 12.0;
            }

            // Insert for each region
            foreach ($regions as $regionCode => $regionName) {
                // Regional adjustments for London
                if ($regionCode == 'E12000007') {
                    $driveSpeed = min($driveSpeed, 14.0);  // London is slower
                }

                $this->dbhm->preExec("
                    INSERT INTO transport_duration_model
                    (ru_category, region_code, ru_description,
                     walk_speed_mph, cycle_speed_mph, drive_speed_mph,
                     walk_base_mins, cycle_base_mins, drive_base_mins,
                     time_adjustment_factor,
                     avg_walk_distance_miles, avg_cycle_distance_miles, avg_drive_distance_miles,
                     data_source, last_seen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        ru_description = VALUES(ru_description),
                        walk_speed_mph = VALUES(walk_speed_mph),
                        cycle_speed_mph = VALUES(cycle_speed_mph),
                        drive_speed_mph = VALUES(drive_speed_mph),
                        walk_base_mins = VALUES(walk_base_mins),
                        cycle_base_mins = VALUES(cycle_base_mins),
                        drive_base_mins = VALUES(drive_base_mins),
                        time_adjustment_factor = VALUES(time_adjustment_factor),
                        avg_walk_distance_miles = VALUES(avg_walk_distance_miles),
                        avg_cycle_distance_miles = VALUES(avg_cycle_distance_miles),
                        avg_drive_distance_miles = VALUES(avg_drive_distance_miles),
                        data_source = VALUES(data_source),
                        last_seen = VALUES(last_seen)
                ", [
                    $ruCat,
                    $regionCode,
                    $description,
                    $walkSpeed,
                    $cycleSpeed,
                    $driveSpeed,
                    18,  // NTS 2024 average walk trip
                    24,  // NTS 2024 average cycle trip
                    22,  // NTS 2024 average drive trip
                    $timeAdj,
                    $avgWalk,
                    $avgCycle,
                    $avgDrive,
                    'NTS2024 + DfT2023',
                    $this->importStartTime
                ]);

                $count++;
            }
        }

        echo "  ✓ Inserted/updated {$count} duration models\n";

        // Clean up stale entries
        echo "  Removing stale entries...\n";
        $result = $this->dbhm->preExec("
            DELETE FROM transport_duration_model
            WHERE last_seen < ?
        ", [$this->importStartTime]);
        echo "    Removed " . number_format($result) . " stale entries\n";

        // Show sample
        $sample = $this->dbhr->preQuery("
            SELECT ru_category, region_code, ru_description,
                   walk_speed_mph, cycle_speed_mph, drive_speed_mph
            FROM transport_duration_model
            WHERE region_code = ?
            ORDER BY ru_category
            LIMIT 3
        ", ['E12000007']);

        echo "    Sample (London):\n";
        foreach ($sample as $row) {
            echo "      {$row['ru_category']} ({$row['ru_description']}): Walk {$row['walk_speed_mph']}mph, Cycle {$row['cycle_speed_mph']}mph, Drive {$row['drive_speed_mph']}mph\n";
        }

        return true;
    }

    /**
     * Populate mode probabilities from NTS data (online)
     */
    private function populateModeProbabilities() {
        echo "  Using INSERT ... ON DUPLICATE KEY UPDATE (table stays live)\n";

        // Based on NTS 2021 data:
        // Urban conurbations: Walk 28%, Cycle 2%, Car 51%
        // Rural areas: Walk 15%, Cycle 1%, Car 75%

        $ruCategories = ['A1', 'B1', 'C1', 'C2', 'D1', 'D2', 'E1', 'E2', 'F1', 'F2'];
        $regions = [
            'E12000001', 'E12000002', 'E12000003', 'E12000004', 'E12000005',
            'E12000006', 'E12000007', 'E12000008', 'E12000009',
            'W92000004', 'S92000003', 'N92000002'
        ];

        $count = 0;

        foreach ($ruCategories as $ruCat) {
            // Set mode shares based on area type
            $isUrban = in_array($ruCat, ['A1', 'B1', 'C1', 'C2']);

            if ($ruCat == 'A1') {
                // Major conurbation
                $walk = 30.0;
                $cycle = 3.0;
                $drive = 48.0;
                $bus = 10.0;
                $rail = 6.0;
                $avgTrips = 950;
            } elseif ($ruCat == 'B1') {
                // Minor conurbation
                $walk = 28.0;
                $cycle = 2.5;
                $drive = 51.0;
                $bus = 9.0;
                $rail = 5.0;
                $avgTrips = 930;
            } elseif ($ruCat == 'C1' || $ruCat == 'C2') {
                // Urban city/town
                $walk = 25.0;
                $cycle = 2.0;
                $drive = 58.0;
                $bus = 7.0;
                $rail = 4.0;
                $avgTrips = 900;
            } elseif ($ruCat == 'D1' || $ruCat == 'D2') {
                // Rural town/fringe
                $walk = 18.0;
                $cycle = 1.5;
                $drive = 70.0;
                $bus = 3.0;
                $rail = 2.0;
                $avgTrips = 850;
            } elseif ($ruCat == 'E1' || $ruCat == 'E2') {
                // Rural village
                $walk = 15.0;
                $cycle = 1.2;
                $drive = 74.0;
                $bus = 2.0;
                $rail = 1.0;
                $avgTrips = 820;
            } else {
                // Rural hamlets/isolated
                $walk = 12.0;
                $cycle = 1.0;
                $drive = 78.0;
                $bus = 1.0;
                $rail = 0.5;
                $avgTrips = 800;
            }

            $other = 100.0 - $walk - $cycle - $drive - $bus - $rail;

            foreach ($regions as $regionCode) {
                // Regional adjustments
                $walkAdj = $walk;
                $cycleAdj = $cycle;
                $driveAdj = $drive;
                $busAdj = $bus;
                $railAdj = $rail;

                if ($regionCode == 'E12000007') {
                    // London: more public transport
                    $railAdj += 5;
                    $busAdj += 5;
                    $driveAdj -= 10;
                }

                $otherAdj = 100.0 - $walkAdj - $cycleAdj - $driveAdj - $busAdj - $railAdj;

                $this->dbhm->preExec("
                    INSERT INTO transport_mode_probabilities
                    (ru_category, region_code, walk_pct, cycle_pct, drive_pct, bus_pct, rail_pct, other_pct, avg_trips_per_year, data_source, last_seen)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        walk_pct = VALUES(walk_pct),
                        cycle_pct = VALUES(cycle_pct),
                        drive_pct = VALUES(drive_pct),
                        bus_pct = VALUES(bus_pct),
                        rail_pct = VALUES(rail_pct),
                        other_pct = VALUES(other_pct),
                        avg_trips_per_year = VALUES(avg_trips_per_year),
                        data_source = VALUES(data_source),
                        last_seen = VALUES(last_seen)
                ", [
                    $ruCat,
                    $regionCode,
                    $walkAdj,
                    $cycleAdj,
                    $driveAdj,
                    $busAdj,
                    $railAdj,
                    $otherAdj,
                    $avgTrips,
                    'NTS2021',
                    $this->importStartTime
                ]);

                $count++;
            }
        }

        echo "  ✓ Inserted/updated {$count} mode probability models\n";

        // Clean up stale entries
        echo "  Removing stale entries...\n";
        $result = $this->dbhm->preExec("
            DELETE FROM transport_mode_probabilities
            WHERE last_seen < ?
        ", [$this->importStartTime]);
        echo "    Removed " . number_format($result) . " stale entries\n";

        // Show sample
        $sample = $this->dbhr->preQuery("
            SELECT ru_category, region_code, walk_pct, cycle_pct, drive_pct
            FROM transport_mode_probabilities
            WHERE region_code = ?
            ORDER BY ru_category
            LIMIT 3
        ", ['E12000009']);

        echo "    Sample (South West):\n";
        foreach ($sample as $row) {
            echo "      {$row['ru_category']}: Walk {$row['walk_pct']}%, Cycle {$row['cycle_pct']}%, Drive {$row['drive_pct']}%\n";
        }

        return true;
    }

    /**
     * Clean up temporary files
     */
    private function cleanup() {
        $files = [
            $this->downloadDir . '/onspd.zip',
            $this->downloadDir . '/onspd_data.csv'
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
                echo "  Removed: " . basename($file) . "\n";
            }
        }

        // Remove Data directory if it exists
        $dataDir = $this->downloadDir . '/Data';
        if (is_dir($dataDir)) {
            $this->removeDirectory($dataDir);
            echo "  Removed: Data directory\n";
        }

        echo "  ✓ Cleanup complete\n";
    }

    /**
     * Recursively remove directory
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// Run the script
$categoriser = new TransportCategoriser($dbhr, $dbhm);
$categoriser->run();

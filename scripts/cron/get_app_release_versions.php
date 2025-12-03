<?php

// bulk3 scripts/cron/get_app_release_versions.php
// This script fetches the latest app version numbers from the app stores - doing in the apps themselves runs into CORS

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

echo "get_app_release_versions\r\n";

define('GEEKSALERTS_ADDR', 'geek-alerts@ilovefreegle.org');

$debug = "\r\n\r\n";

try {
    $headers = 'From: geeks@ilovefreegle.org';

    $FD_iOS_currentVersionReleaseDate = false;
    $FD_iOS_version = false;
    $MT_iOS_currentVersionReleaseDate = false;
    $MT_iOS_version = false;
    $FD_Android_currentVersionReleaseDate = false;
    $FD_Android_version = false;
    $MT_Android_currentVersionReleaseDate = false;
    $MT_Android_version = false;

    // iOS
    //        const FD_LOOKUP = 'https://itunes.apple.com/gb/lookup?bundleId=org.ilovefreegle.iphone'
    //        const MT_LOOKUP = 'https://itunes.apple.com/lookup?bundleId=org.ilovefreegle.modtools'

    // IOS FREEGLE
    $url = "https://itunes.apple.com/gb/lookup?bundleId=org.ilovefreegle.iphone";
    $data = file_get_contents($url);
    $iOSfreegle = json_decode($data);
    //echo print_r($iOSfreegle, TRUE);
    if ($iOSfreegle->resultCount == 1) {
        $FD_iOS_currentVersionReleaseDate = $iOSfreegle->results[0]->currentVersionReleaseDate;
        $FD_iOS_version = $iOSfreegle->results[0]->version;
        echo "iOS FD:     " . $FD_iOS_version . ": " . $FD_iOS_currentVersionReleaseDate . "\r\n";
    }

    // IOS MODTOOLS
    $url = "https://itunes.apple.com/lookup?bundleId=org.ilovefreegle.modtools";
    $data = file_get_contents($url);
    $iOSmodtools = json_decode($data);
    //echo print_r($iOSmodtools, TRUE);
    if ($iOSmodtools->resultCount == 1) {
        $MT_iOS_currentVersionReleaseDate = $iOSmodtools->results[0]->currentVersionReleaseDate;
        $MT_iOS_version = $iOSmodtools->results[0]->version;
        echo "iOS MT:     " . $MT_iOS_version . ": " . $MT_iOS_currentVersionReleaseDate . "\r\n";
    }


    // Android
    //        const FD_LOOKUP = 'https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en'
    //        const MT_LOOKUP = 'https://play.google.com/store/apps/details?id=org.ilovefreegle.modtools&hl=en'
    // Updated 2025: Parse AF_initDataCallback('ds:5') for version and date info

    // ANDROID FREEGLE
    $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en";
    $data = file_get_contents($url);
    $savefilenamefr = false;

    // Extract the AF_initDataCallback for 'ds:5' which contains app version info
    if (preg_match('/AF_initDataCallback\(\{key: \'ds:5\'.*?}\);/s', $data, $ds5Match)) {
        $ds5Data = $ds5Match[0];

        // Extract version number (format: x.x.x)
        if (preg_match('/\d+\.\d+\.\d+/', $ds5Data, $verMatch)) {
            $FD_Android_version = $verMatch[0];
        }

        // Extract release date - get the most recent date (second match)
        if (preg_match_all('/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d+, \d{4}/', $ds5Data, $dateMatches)) {
            // The last date match is usually the current version release date
            $FD_Android_currentVersionReleaseDate = end($dateMatches[0]);
        }

        if ($FD_Android_version && $FD_Android_currentVersionReleaseDate) {
            echo "Android FD: " . $FD_Android_version . ": " . $FD_Android_currentVersionReleaseDate . "\r\n";
        }
    }

    if (!$FD_Android_version) {
        $savefilenamefr = "/tmp/get_app_android_fr.htm";
        $savefile = fopen($savefilenamefr, "w");
        fwrite($savefile, $data);
        fclose($savefile);
    }

    // ANDROID MODTOOLS
    $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.modtools&hl=en";
    $data = file_get_contents($url);
    $savefilenamemt = false;

    // Extract the AF_initDataCallback for 'ds:5' which contains app version info
    if (preg_match('/AF_initDataCallback\(\{key: \'ds:5\'.*?}\);/s', $data, $ds5Match)) {
        $ds5Data = $ds5Match[0];

        // Extract version number (format: x.x.x)
        if (preg_match('/\d+\.\d+\.\d+/', $ds5Data, $verMatch)) {
            $MT_Android_version = $verMatch[0];
        }

        // Extract release date - get the most recent date (second match)
        if (preg_match_all('/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d+, \d{4}/', $ds5Data, $dateMatches)) {
            // The last date match is usually the current version release date
            $MT_Android_currentVersionReleaseDate = end($dateMatches[0]);
        }

        if ($MT_Android_version && $MT_Android_currentVersionReleaseDate) {
            echo "Android MT: " . $MT_Android_version . ": " . $MT_Android_currentVersionReleaseDate . "\r\n";
        }
    }

    if (!$MT_Android_version) {
        $savefilenamemt = "/tmp/get_app_android_mt.htm";
        $savefile = fopen($savefilenamemt, "w");
        fwrite($savefile, $data);
        fclose($savefile);
    }

    $subject = 'get_app_release_versions: ';
    $gotIOS_FD = $FD_iOS_version !== false;
    $gotIOS_MT = $MT_iOS_version !== false;
    $gotAndroid_FD = $FD_Android_version !== false;
    $gotAndroid_MT = $MT_Android_version !== false;

    // Check if FD Android app is older than 2 weeks
    $FD_Android_tooOld = false;
    if ($gotAndroid_FD && $FD_Android_currentVersionReleaseDate) {
        $releaseTimestamp = strtotime($FD_Android_currentVersionReleaseDate);
        $twoWeeksAgo = strtotime('-2 weeks');
        if ($releaseTimestamp < $twoWeeksAgo) {
            $FD_Android_tooOld = true;
            $daysSinceRelease = floor((time() - $releaseTimestamp) / 86400);
            echo "WARNING: Android FD app is $daysSinceRelease days old (released $FD_Android_currentVersionReleaseDate)\r\n";
        }
    }

    if ($gotIOS_FD) {
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_fd_version_ios_latest', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$FD_iOS_version, $FD_iOS_version]
        );
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_fd_version_ios_date', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$FD_iOS_currentVersionReleaseDate, $FD_iOS_currentVersionReleaseDate]
        );
    }
    if ($gotIOS_MT) {
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_mt_version_ios_latest', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$MT_iOS_version, $MT_iOS_version]
        );
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_mt_version_ios_date', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$MT_iOS_currentVersionReleaseDate, $MT_iOS_currentVersionReleaseDate]
        );
    }
    if ($gotAndroid_FD) {
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_fd_version_android_latest', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$FD_Android_version, $FD_Android_version]
        );
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_fd_version_android_date', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$FD_Android_currentVersionReleaseDate, $FD_Android_currentVersionReleaseDate]
        );
    }
    if ($gotAndroid_MT) {
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_mt_version_android_latest', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$MT_Android_version, $MT_Android_version]
        );
        $dbhm->preExec(
            "INSERT INTO config (`key`, value) VALUES ('app_mt_version_android_date', ?) ON DUPLICATE KEY UPDATE value = ?;",
            [$MT_Android_currentVersionReleaseDate, $MT_Android_currentVersionReleaseDate]
        );
    }
} catch (\Exception $e) {
    \Sentry\captureException($e);

    echo $e->getMessage();
    error_log("Failed with " . $e->getMessage());
    $sent = mail(GEEKSALERTS_ADDR, "get_app_release_versions EXCEPTION", $e->getMessage(), $headers);
    echo "Mail sent to geeks: " . $sent . "\r\n";
}
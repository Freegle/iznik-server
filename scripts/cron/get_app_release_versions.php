<?php

// bulk3 scripts/cron/get_app_release_versions.php
// This script fetches the latest app version numbers from the app stores - doing in the apps themselves runs into CORS

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

echo "get_app_release_versions\r\n";

define('GEEKSALERTS_ADDR','geek-alerts@ilovefreegle.org');

$debug = "\r\n\r\n";

try{
  $headers = 'From: geeks@ilovefreegle.org';

  $FD_iOS_currentVersionReleaseDate = FALSE;
  $FD_iOS_version = FALSE;
  $MT_iOS_currentVersionReleaseDate = FALSE;
  $MT_iOS_version = FALSE;
  $FD_Android_currentVersionReleaseDate = FALSE;
  $FD_Android_version = FALSE;
  $MT_Android_currentVersionReleaseDate = FALSE;
  $MT_Android_version = FALSE;

  // iOS
  //        const FD_LOOKUP = 'https://itunes.apple.com/gb/lookup?bundleId=org.ilovefreegle.iphone'
  //        const MT_LOOKUP = 'https://itunes.apple.com/lookup?bundleId=org.ilovefreegle.modtools'

  // IOS FREEGLE
  $url = "https://itunes.apple.com/gb/lookup?bundleId=org.ilovefreegle.iphone";
  $data = file_get_contents($url);
  $iOSfreegle = json_decode($data);
  //echo print_r($iOSfreegle, TRUE);
  if( $iOSfreegle->resultCount==1){
    $FD_iOS_currentVersionReleaseDate = $iOSfreegle->results[0]->currentVersionReleaseDate;
    $FD_iOS_version = $iOSfreegle->results[0]->version;
    echo "iOS FD:     ".$FD_iOS_version.": ".$FD_iOS_currentVersionReleaseDate."\r\n";
  }

  // IOS MODTOOLS
  $url = "https://itunes.apple.com/lookup?bundleId=org.ilovefreegle.modtools";
  $data = file_get_contents($url);
  $iOSmodtools = json_decode($data);
  //echo print_r($iOSmodtools, TRUE);
  if( $iOSmodtools->resultCount==1){
    $MT_iOS_currentVersionReleaseDate = $iOSmodtools->results[0]->currentVersionReleaseDate;
    $MT_iOS_version = $iOSmodtools->results[0]->version;
    echo "iOS MT:     ".$MT_iOS_version.": ".$MT_iOS_currentVersionReleaseDate."\r\n";
  }


  // Android
  //        const FD_LOOKUP = 'https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en'
  //        const MT_LOOKUP = 'https://play.google.com/store/apps/details?id=org.ilovefreegle.modtools&hl=en'
  // Updated 2025: Parse AF_initDataCallback('ds:5') for version and date info

  // ANDROID FREEGLE
  $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en";
  $data = file_get_contents($url);
  $savefilenamefr = FALSE;

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
      echo "Android FD: ".$FD_Android_version.": ".$FD_Android_currentVersionReleaseDate."\r\n";
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
  $savefilenamemt = FALSE;

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
      echo "Android MT: ".$MT_Android_version.": ".$MT_Android_currentVersionReleaseDate."\r\n";
    }
  }

  if (!$MT_Android_version) {
    $savefilenamemt = "/tmp/get_app_android_mt.htm";
    $savefile = fopen($savefilenamemt, "w");
    fwrite($savefile, $data);
    fclose($savefile);
  }

  // ANDROID FREEGLE
  /*
  $lookfor = '<span class="htlgb">';  // 2nd = June 4, 2021, 8th = 2.0.77
  $lookforlen = strlen($lookfor);
  
  $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en";
  $data = file_get_contents($url);
  $savefilenamefr = FALSE;

  $spancount = substr_count($data,$lookfor);
  //echo "Android FD spancount: ".$spancount."\r\n";
  $debug .= "Android FD spancount:".$spancount."\r\n";
  if( $spancount==22){
    $pos = strpos($data,$lookfor)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $closepos = strpos($data,'<',$pos);
    $FD_Android_currentVersionReleaseDate = substr($data,$pos,$closepos-$pos);
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $closepos = strpos($data,'<',$pos);
    $FD_Android_version = substr($data,$pos,$closepos-$pos);
    echo "Android FD: ".$FD_Android_currentVersionReleaseDate.": ".$FD_Android_version."\r\n";
  } else {
    $savefilenamefr = "/tmp/get_app_android_fr.htm";
    $savefile = fopen($savefilenamefr, "w");
    fwrite($savefile, $data);
    fclose($savefile);
  }

  // ANDROID MODTOOLS
  $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.modtools&hl=en";
  $data = file_get_contents($url);
  $savefilenamemt = FALSE;

  $spancount = substr_count($data,$lookfor);
  //echo "Android MT spancount: ".$spancount."\r\n";
  $debug .= "Android MT spancount:".$spancount."\r\n";
  if( $spancount==22){
    $pos = strpos($data,$lookfor)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $closepos = strpos($data,'<',$pos);
    $MT_Android_currentVersionReleaseDate = substr($data,$pos,$closepos-$pos);
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $pos = strpos($data,$lookfor,$pos)+$lookforlen;
    $closepos = strpos($data,'<',$pos);
    $MT_Android_version = substr($data,$pos,$closepos-$pos);
    echo "Android MT: ".$MT_Android_currentVersionReleaseDate.": ".$MT_Android_version."\r\n";
  } else {
    $savefilenamemt = "/tmp/get_app_android_mt.htm";
    $savefile = fopen($savefilenamemt, "w");
    fwrite($savefile, $data);
    fclose($savefile);
  }
  */

  $subject = 'get_app_release_versions: ';
  $gotIOS_FD = $FD_iOS_version !== FALSE;
  $gotIOS_MT = $MT_iOS_version !== FALSE;
  $gotAndroid_FD = $FD_Android_version !== FALSE;
  $gotAndroid_MT = $MT_Android_version !== FALSE;

  // Check if FD Android app is older than 2 weeks
  $FD_Android_tooOld = FALSE;
  if ($gotAndroid_FD && $FD_Android_currentVersionReleaseDate) {
    $releaseTimestamp = strtotime($FD_Android_currentVersionReleaseDate);
    $twoWeeksAgo = strtotime('-2 weeks');
    if ($releaseTimestamp < $twoWeeksAgo) {
      $FD_Android_tooOld = TRUE;
      $daysSinceRelease = floor((time() - $releaseTimestamp) / 86400);
      echo "WARNING: Android FD app is $daysSinceRelease days old (released $FD_Android_currentVersionReleaseDate)\r\n";
    }
  }

  if( $gotIOS_FD){
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_fd_version_ios_latest', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $FD_iOS_version, $FD_iOS_version ]);
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_fd_version_ios_date', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $FD_iOS_currentVersionReleaseDate, $FD_iOS_currentVersionReleaseDate ]);
  }
  if( $gotIOS_MT){
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_mt_version_ios_latest', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $MT_iOS_version, $MT_iOS_version ]);
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_mt_version_ios_date', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $MT_iOS_currentVersionReleaseDate, $MT_iOS_currentVersionReleaseDate ]);
  }
  if( $gotAndroid_FD){
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_fd_version_android_latest', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $FD_Android_version, $FD_Android_version ]);
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_fd_version_android_date', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $FD_Android_currentVersionReleaseDate, $FD_Android_currentVersionReleaseDate ]);
  }
  if( $gotAndroid_MT){
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_mt_version_android_latest', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $MT_Android_version, $MT_Android_version ]);
    $dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('app_mt_version_android_date', ?) ON DUPLICATE KEY UPDATE value = ?;", [ $MT_Android_currentVersionReleaseDate, $MT_Android_currentVersionReleaseDate ]);
  }

  if( $gotIOS_FD && $gotIOS_MT && $gotAndroid_FD && $gotAndroid_MT && !$FD_Android_tooOld){
    $subject .= "OK";
  } else {
    $subject .= "FAIL";
  }
  $report = "iOS\r\n\r\n";
  $report .= "FD: ".$FD_iOS_version.": ".$FD_iOS_currentVersionReleaseDate."\r\n";
  $report .= "MT: ".$MT_iOS_version.": ".$MT_iOS_currentVersionReleaseDate."\r\n";
  $report .= "\r\nAndroid \r\n\r\n";
  $report .= "FD: ".$FD_Android_version.": ".$FD_Android_currentVersionReleaseDate;
  if ($FD_Android_tooOld) {
    $daysSinceRelease = floor((time() - strtotime($FD_Android_currentVersionReleaseDate)) / 86400);
    $report .= " [WARNING: $daysSinceRelease days old - exceeds 2 week limit]";
  }
  $report .= "\r\n";
  $report .= "MT: ".$MT_Android_version.": ".$MT_Android_currentVersionReleaseDate."\r\n";
  if( $savefilenamefr) $report .= "Received file written to: ".$savefilenamefr."\r\n";
  if( $savefilenamemt) $report .= "Received file written to: ".$savefilenamemt."\r\n";

  $report .= $debug;


  $sent = mail(GEEKSALERTS_ADDR, $subject, $report,$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";

} catch (\Exception $e) {
  \Sentry\captureException($e);

  echo $e->getMessage();
  error_log("Failed with " . $e->getMessage());
  $sent = mail(GEEKSALERTS_ADDR, "get_app_release_versions EXCEPTION", $e->getMessage(),$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";
}
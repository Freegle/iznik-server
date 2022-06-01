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
  //echo print_r($iOSfreegle, true);
  if( $iOSfreegle->resultCount==1){
    $FD_iOS_currentVersionReleaseDate = $iOSfreegle->results[0]->currentVersionReleaseDate;
    $FD_iOS_version = $iOSfreegle->results[0]->version;
    echo "iOS FD:     ".$FD_iOS_version.": ".$FD_iOS_currentVersionReleaseDate."\r\n";
  }

  // IOS MODTOOLS
  $url = "https://itunes.apple.com/lookup?bundleId=org.ilovefreegle.modtools";
  $data = file_get_contents($url);
  $iOSmodtools = json_decode($data);
  //echo print_r($iOSmodtools, true);
  if( $iOSmodtools->resultCount==1){
    $MT_iOS_currentVersionReleaseDate = $iOSmodtools->results[0]->currentVersionReleaseDate;
    $MT_iOS_version = $iOSmodtools->results[0]->version;
    echo "iOS MT:     ".$MT_iOS_version.": ".$MT_iOS_currentVersionReleaseDate."\r\n";
  }


  // Android
  //        const FD_LOOKUP = 'https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en'
  //        const MT_LOOKUP = 'https://play.google.com/store/apps/details?id=org.ilovefreegle.modtools&hl=en'

  $lookfor = '[null,null,[]],null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,null,[[["';
  $lookforlen = strlen($lookfor);

  $lookfor2 = ']]]],[["';
  $lookfor2len = strlen($lookfor2);

  // '[[["2.0.102"]],[[[30,"11"]],[[[22,"5.1"]]]],[["May 15, 2022"]]]'
  // '[[["0.3.76"]],[[[30,"11"]],[[[22,"5.1"]]]],[["May 14, 2022"]]]'
  
  // ANDROID FREEGLE
  $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en";
  $data = file_get_contents($url);
  $savefilenamefr = false;

  $pos = strpos($data,$lookfor);
  if( $pos!==FALSE){
    $pos += $lookforlen;
    $endpos = strpos($data,'"',$pos);
    if( $endpos!==FALSE){
      $FD_Android_version = substr($data,$pos,$endpos-$pos);
      //echo "Android FD: ".$FD_Android_version."\r\n";
      $pos = strpos($data,$lookfor2,$pos);
      if( $pos!==FALSE){
        $pos += $lookfor2len;
        $endpos = strpos($data,'"',$pos);
        if( $endpos!==FALSE){
          $FD_Android_currentVersionReleaseDate = substr($data,$pos,$endpos-$pos);
          echo "Android FD: ".$FD_Android_version.": ".$FD_Android_currentVersionReleaseDate."\r\n";
        }
      }
    }
  } else{
    $savefilenamefr = "/tmp/get_app_android_fr.htm";
    $savefile = fopen($savefilenamefr, "w");
    fwrite($savefile, $data);
    fclose($savefile);
  }

  // ANDROID MODTOOLS
  $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.modtools&hl=en";
  $data = file_get_contents($url);
  $savefilenamefr = false;

  $pos = strpos($data,$lookfor);
  if( $pos!==FALSE){
    $pos += $lookforlen;
    $endpos = strpos($data,'"',$pos);
    if( $endpos!==FALSE){
      $MT_Android_version = substr($data,$pos,$endpos-$pos);
      //echo "Android MT: ".$MT_Android_version."\r\n";
      $pos = strpos($data,$lookfor2,$pos);
      if( $pos!==FALSE){
        $pos += $lookfor2len;
        $endpos = strpos($data,'"',$pos);
        if( $endpos!==FALSE){
          $MT_Android_currentVersionReleaseDate = substr($data,$pos,$endpos-$pos);
          echo "Android MT: ".$MT_Android_version.": ".$MT_Android_currentVersionReleaseDate."\r\n";
        }
      }
    }
  } else{
    $savefilenamefr = "/tmp/get_app_android_mt.htm";
    $savefile = fopen($savefilenamefr, "w");
    fwrite($savefile, $data);
    fclose($savefile);
  }

  // ANDROID FREEGLE
  /*
  $lookfor = '<span class="htlgb">';  // 2nd = June 4, 2021, 8th = 2.0.77
  $lookforlen = strlen($lookfor);
  
  $url = "https://play.google.com/store/apps/details?id=org.ilovefreegle.direct&hl=en";
  $data = file_get_contents($url);
  $savefilenamefr = false;

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
  $savefilenamemt = false;

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
  $gotIOS_FD = $FD_iOS_version !== false;
  $gotIOS_MT = $MT_iOS_version !== false;
  $gotAndroid_FD = $FD_Android_version !== false;
  $gotAndroid_MT = $MT_Android_version !== false;
  
  if( $gotIOS_FD){
    $dbhm->preExec("UPDATE config SET value = ? WHERE `key` = 'app_fd_version_ios_latest';", [ $FD_iOS_version ]);
  }
  if( $gotIOS_MT){
    $dbhm->preExec("UPDATE config SET value = ? WHERE `key` = 'app_mt_version_ios_latest';", [ $MT_iOS_version ]);
  }
  if( $gotAndroid_FD){
    $dbhm->preExec("UPDATE config SET value = ? WHERE `key` = 'app_fd_version_android_latest';", [ $FD_Android_version ]);
  }
  if( $gotAndroid_MT){
    $dbhm->preExec("UPDATE config SET value = ? WHERE `key` = 'app_mt_version_android_latest';", [ $MT_Android_version ]);
  }
  
  if( $gotIOS_FD && $gotIOS_MT && $gotAndroid_FD && $gotAndroid_MT){
    $subject .= "OK";
  } else {
    $subject .= "FAIL";
  }
  $report = "iOS\r\n\r\n";
  $report .= "FD: ".$FD_iOS_version.": ".$FD_iOS_currentVersionReleaseDate."\r\n";
  $report .= "MT: ".$MT_iOS_version.": ".$MT_iOS_currentVersionReleaseDate."\r\n";
  $report .= "\r\nAndroid \r\n\r\n";
  $report .= "FD: ".$FD_Android_version.": ".$FD_Android_currentVersionReleaseDate."\r\n";
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
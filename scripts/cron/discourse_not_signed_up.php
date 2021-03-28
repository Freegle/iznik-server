<?php

// NOT DONE YET
// BUT USE
// SELECT DISTINCT users.* FROM users INNER JOIN memberships ON users.id = memberships.userid INNER JOIN groups ON groups.id = memberships.groupid WHERE memberships.role IN ('Owner', 'Moderator') AND groups.type = 'Freegle' AND `lastaccess` > '2020-09-01 00:00:00' 

// hub/archive scripts/cron/discourse_checkusers.php
//
// 2021-03-28 Start

namespace Freegle\Iznik;
use \Datetime;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

echo "discourse_not_signed_up\r\n";

// https://docs.discourse.org/
// DISCOURSE_SECRET
// DISCOURSE_APIKEY
// DISCOURSE_API
$api_username = 'system';

define('GEEKSALERTS_ADDR','geek-alerts@ilovefreegle.org');

//////////////////////////////////////////////////////////////////////////
// GET ALL USERS
//  https://meta.discourse.org/t/how-do-i-get-a-list-of-all-users-from-the-api/24261/11
//  It is possible to get users in chunks, but we just get them all ie first 1000 to override default limit of 20
//  https://discourse.ilovefreegle.org/groups/trust_level_0/members.json?limit=50&offset=50

function GetAllUsers(){
  global $api_username;
  $q = "?limit=1000&offset=0";
  $url = 'https://discourse.ilovefreegle.org/groups/trust_level_0/members.json'.$q;

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );
  curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Api-Key: '.DISCOURSE_APIKEY,
    'Api-Username: '.$api_username
  ));


  $result = curl_exec( $ch );

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new \Exception('curl_errno: GetAllUsers');
  }
  curl_close( $ch );

  //echo "<pre>".htmlspecialchars($result)."</pre>";
  //  {"errors":["The requested URL or resource could not be found."],"error_type":"not_found"}
  $allusers = json_decode($result);
  //echo print_r($allusers)."\r\n\r\n";
  if (property_exists($allusers, 'errors')){
    echo print_r($allusers)."\r\n\r\n";
    throw new \Exception('GetAllUsers error '.$allusers->errors[0]);
  }
  return $allusers->members;
}

//////////////////////////////////////////////////////////////////////////
// GET USER
// https://discourse.ilovefreegle.org/admin/users/{id}/{username}

function GetUser($id,$username){
  global $api_username;
  $url = 'https://discourse.ilovefreegle.org/admin/users/'.$id.'/'.$username.'.json';

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );
  curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Api-Key: '.DISCOURSE_APIKEY,
    'Api-Username: '.$api_username
  ));

  $result = curl_exec( $ch );

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new \Exception('curl_errno: GetUser '.$id." - ".$id);
  }
  curl_close( $ch );

  //echo "<pre>".htmlspecialchars($result)."</pre>";
  //  {"errors":["The requested URL or resource could not be found."],"error_type":"not_found"}
  $fulluser = json_decode($result);
  //echo print_r($fulluser)."\r\n\r\n";
  if (property_exists($fulluser, 'errors')){
    echo print_r($fulluser)."\r\n\r\n";
    throw new \Exception('GetUser error '.$fulluser->errors[0]);
  }
  return $fulluser;
}

//////////////////////////////////////////////////////////////////////////
// GET USER EMAIL
// https://discourse.ilovefreegle.org//users/[username]/emails.json

function GetUserEmail($username){
  global $api_username;
  $url = 'https://discourse.ilovefreegle.org/users/'.$username.'/emails.json';

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );
  curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Api-Key: '.DISCOURSE_APIKEY,
    'Api-Username: '.$api_username
  ));

  $result = curl_exec( $ch );

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new \Exception('curl_errno: GetUserEmail '.$username);
  }
  curl_close( $ch );

  //echo "<pre>".htmlspecialchars($result)."</pre>";
  //  {"errors":["The requested URL or resource could not be found."],"error_type":"not_found"}
  $useremails = json_decode($result);
  //echo print_r($useremails)."\r\n\r\n";
  if (property_exists($useremails, 'errors')){
    echo print_r($useremails)."\r\n\r\n";
    throw new \Exception('GetUserEmail error '.$useremails->errors[0]);
  }
  return $useremails->email;
}

//  https://discourse.ilovefreegle.org/admin/users/list/active.json
//  "last_emailed_at":"2019-10-26T07:14:45.040Z"
//  "last_emailed_age":19775.883742965,

//////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////
$headers = 'From: geeks@ilovefreegle.org';
try{

  // Get all users from Discourse
  $allDusers = GetAllUsers();
  echo "allDusers: ".count($allDusers)."\r\n";
  //echo print_r($allDusers[0])."\r\n\r\n";

  // Look for active mods in MT, checking to see if user on Discourse
  $now = new \DateTime();
  $count = 0;
  $report = 'Total Discourse users: '.count($allDusers)."\r\n";

  // id, fullname, systemrole, lastaccess
  $sql = "SELECT DISTINCT users.* FROM users INNER JOIN memberships ON users.id = memberships.userid ".
  "INNER JOIN groups ON groups.id = memberships.groupid ".
  "WHERE memberships.role IN ('Owner', 'Moderator') AND groups.type = 'Freegle' AND `lastaccess` > DATE_SUB(now(), INTERVAL 6 MONTH) ".
  "ORDER BY users.id";
  $allactivemods = $dbhr->preQuery($sql);
  $report .= 'Total active mods: '.count($allactivemods)." (in last 6 months)\r\n";
  $report .= "\r\nList of these volunteers not on Discourse:\r\n";

  foreach ($allDusers as $duser) {
    $duser->external_id = false;
  }

  foreach ($allDusers as $duser) {
    usleep(250000);
    $count++;

    //echo "duser: ".$duser->id." ".$duser->username."\r\n";
   
    // Get external_id from Discourse ie MT user id - and last_emailed_at 
    $duser->external_id = false;
    $fulluser = GetUser($duser->id,$duser->username);
    if (property_exists($fulluser, 'single_sign_on_record')){
      if( is_object ($fulluser->single_sign_on_record)){
        $duser->external_id  = $fulluser->single_sign_on_record->external_id;
        //echo "external_id: ".$duser->external_id."\r\n";
      } else {
      echo $duser->id."single_sign_on_record NOT OBJECT"."\r\n";
      }
    } else {
      echo $duser->id."NO EXTERNALID"."\r\n";
    }
    //if( $count>10) break;
  }

  $count = 0;
  $notondiscourse = 0;
  foreach ($allactivemods as $activemod) {
    $count++;
    //echo "CHECKING: ".$activemod['id']." ".$activemod['fullname']."\r\n";
    $found = false;
    foreach ($allDusers as $duser) {
      if( $duser->external_id==$activemod['id']){
        $found = true;
        break;
      }
    }
    if( !$found) {
      $report .= "* ".$activemod['id'].": ".$activemod['fullname']." - ".$activemod['lastaccess']."\r\n";
      $notondiscourse++;
    }
    //if( $count>10) break;
  }


  $report .= "\r\n";
  $report .= "notondiscourse: $notondiscourse\r\n";

  echo $report;
  echo "\r\n";

  $subject = 'Discourse: all active volunteers on here';
  if( $notondiscourse>0) $subject = 'Discourse: '.$notondiscourse.' volunteers not signed up';

  /*$mailedcentralmods = false;
  $subject = 'Discourse checkuser OK';
  if( $notmod || $notuser){
    $subject = 'Discourse checkuser USERS TO CHECK';
    $sent = mail(CENTRALMODS_ADDR, $subject, $report,$headers);
    echo "Mail sent to centralmods: ".$sent."\r\n";
    $report = "Mail sent to centralmods: ".$sent."\r\n".$report;
    $mailedcentralmods = true;
  }

  if( !$mailedcentralmods && (date('w')==6)){
    $sent = mail(CENTRALMODS_ADDR, $subject, $report,$headers);
    echo "Mail sent to centralmods: ".$sent."\r\n";
  }*/
  
  //$report = wordwrap($report, 70, "\r\n");
  $sent = mail(GEEKSALERTS_ADDR, $subject, $report,$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";
} 
catch (\Exception $e) {
  echo $e->getMessage();
  error_log("Failed with " . $e->getMessage());
  $sent = mail(GEEKSALERTS_ADDR, "Discourse not_signed_up EXCEPTION", $e->getMessage(),$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";
}
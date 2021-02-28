<?php

// hub/archive scripts/cron/discourse_checkusers.php
//
// 2019-12-13 Use header auth not query params
// 2019-12-13 Add bounce reporting
// 2021-02-28 Fix DateTime namespace

namespace Freegle\Iznik;
use \Datetime;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

echo "checkusers\r\n";

global $dbhr, $dbhm;

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
  $allusers = GetAllUsers();
  echo "allusers: ".count($allusers)."\r\n";

  // Check all users are mods in MT, compiling report as we go
  $now = new \DateTime();
  $count = 0;
  $report = 'Total Discourse users: '.count($allusers)."\r\n";
  $ismod = 0;
  $notuser = 0;
  $notmod = 0;
  $system = 0;
  $everposted = 0;
  $everseen = 0;
  $postedinlastweek = 0;
  $seeninlastweek = 0;
  $evermailed = 0;
  $mailedinlastweek = 0;
  $anybounces = 0;
  $anybouncers = '';
  $bouncestopped = 0;
  $bouncestoppers = '';
  foreach ($allusers as $user) {
    usleep(250000);
    $count++;
    //echo "user: ".print_r($user)."\r\n";

    if( $user->username=='freeglegeeks') {
      $system++;
      continue;  // Ignore system user
    }

    // Check for activity
    //echo $count." last_posted_at: ".$user->last_posted_at.". last_seen_at:".$user->last_seen_at."\r\n";
    if( $user->last_posted_at){ //2019-10-11T18:54:53.405Z
      $everposted++;
      $lastposted = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $user->last_posted_at);
      //echo "lastposted: ".print_r($lastposted)."\r\n";
      $interval = $lastposted->diff($now);
      //echo "interval days: ".$interval->days."\r\n";
      if( $interval->days<8) $postedinlastweek++;
    }
    if( $user->last_seen_at){
      $everseen++;
      $lastseen = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $user->last_seen_at);
      //echo "lastposted: ".print_r($lastposted)."\r\n";
      $interval = $lastseen->diff($now);
      //echo "interval days: ".$interval->days."\r\n";
      if( $interval->days<8) $seeninlastweek++;
    }

    // Get external_id from Discourse ie MT user id - and last_emailed_at 
    $external_id = false;
    $fulluser = GetUser($user->id,$user->username);
    //echo "fulluser: ".print_r($fulluser)."\r\n";
    if( $fulluser->bounce_score>=400) {
      $bouncestopped++;
      $bouncestoppers .= $user->username.'-'.$fulluser->bounce_score.', ';
    } else if( $fulluser->bounce_score>0) {
      $anybounces++;
      $anybouncers .= $user->username.'-'.$fulluser->bounce_score.', ';
    }
    if (property_exists($fulluser, 'single_sign_on_record')){
      $external_id  = $fulluser->single_sign_on_record->external_id;
    }
    if( $fulluser->last_emailed_at){
      $evermailed++;
      $lastmailed = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $fulluser->last_emailed_at);
      //echo "lastmailed: ".print_r($lastmailed)."\r\n";
      $interval = $lastmailed->diff($now);
      //echo "interval days: ".$interval->days."\r\n";
      if( $interval->days<8) $mailedinlastweek++;
    }

    echo $count." external_id: ".$external_id."\r\n";
    if( $external_id){
      $u = new User($dbhr, $dbhm, $external_id);
      if( $u){
        if ($u->isModerator()) {  // Is mod: OK
          //echo "IS MOD\r\n";
          $ismod++;
        } else {  // Not a mod: report
          usleep(250000);
          // Get email from Discourse
          $useremail = GetUserEmail($user->username);
          echo "NOT A MOD\r\n";
          $notmod++;
          $report .= 'Not a mod. MT id: '.$external_id.', Discourse username: '.$user->username.', email: '.$useremail."\r\n";
        }
      } else {
        // No entry in MT at all
        usleep(250000);
        $useremail = GetUserEmail($user->username);
        echo "NOT EVEN A USER\r\n";
        $notuser++;
        $report .= 'Not a MT user: Discourse username: '.$user->username.', email: '.$useremail."\r\n";
      }
    }
    //if( $count>50) break;
  }

  $report .= "\r\n";
  $report .= "ismod: $ismod\r\n";
  $report .= "notmod: $notmod\r\n";
  $report .= "notuser: $notuser\r\n";
  $report .= "system: $system\r\n";
  $report .= "\r\n";
  $report .= "everposted: $everposted\r\n";
  $report .= "postedinlastweek: $postedinlastweek\r\n";
  $report .= "\r\n";
  $report .= "everseen: $everseen\r\n";
  $report .= "seeninlastweek: $seeninlastweek\r\n";
  $report .= "\r\n";
  $report .= "evermailed: $evermailed\r\n";
  $report .= "mailedinlastweek: $mailedinlastweek\r\n";
  $report .= "\r\n";
  $report .= "anybounces: $anybounces ($anybouncers)\r\n";
  $report .= "bouncestopped: $bouncestopped ($bouncestoppers)\r\n";
  if( $bouncestopped>0){
    $report .= "Check users with stopped mails here:  https://discourse.ilovefreegle.org/admin/logs/staff_action_logs - action 'revoke email'\r\n";
  }

  echo $report;
  echo "\r\n";

  $mailedcentralmods = false;
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
  }
  
  //$report = wordwrap($report, 70, "\r\n");
  $sent = mail(GEEKSALERTS_ADDR, $subject, $report,$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";

} catch (\Exception $e) {
  echo $e->getMessage();
  error_log("Failed with " . $e->getMessage());
  $sent = mail(GEEKSALERTS_ADDR, "Discourse checkuser EXCEPTION", $e->getMessage(),$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";
}
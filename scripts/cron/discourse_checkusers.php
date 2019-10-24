<?php

// hub/archive scripts/cron/discourse_checkusers.php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/session/Session.php');

echo "checkusers\r\n";

global $dbhr, $dbhm;

// https://docs.discourse.org/
$api_key = 'cc7c91cc03943b7b302dcf06536fd248e3b44bbbce929bcccb60a968defc57e3';
$api_username = 'system';
$sso_secret = 'gottabebetterthanyahoo';


//////////////////////////////////////////////////////////////////////////
// GET ALL USERS
//  https://meta.discourse.org/t/how-do-i-get-a-list-of-all-users-from-the-api/24261/11
//  It is possible to get users in chunks, but we just get them all ie first 1000 to override default limit of 20
//  https://discourse.ilovefreegle.org/groups/trust_level_0/members.json?limit=50&offset=50

function GetAllUsers(){
  global $api_key,$api_username,$sso_secret;
  $q = "?api_key=$api_key&api_username=$api_username";
  $q .= "&limit=1000&offset=0";
  $url = 'https://discourse.ilovefreegle.org/groups/trust_level_0/members.json'.$q;

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );

  $result = curl_exec( $ch );

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new Exception('curl_errno: GetAllUsers ');
  }
  curl_close( $ch );

  //echo "<pre>".htmlspecialchars($result)."</pre>";
  //  {"errors":["The requested URL or resource could not be found."],"error_type":"not_found"}
  $allusers = json_decode($result);
  //echo print_r($allusers)."\r\n\r\n";
  if (property_exists($allusers, 'errors')){
    echo print_r($allusers)."\r\n\r\n";
    return null;
  }
  return $allusers->members;
}

//////////////////////////////////////////////////////////////////////////
// GET USER
// https://discourse.ilovefreegle.org/admin/users/{id}/{username}

function GetUser($id,$username){
  global $api_key,$api_username,$sso_secret;
  $q = "?api_key=$api_key&api_username=$api_username";
  $url = 'https://discourse.ilovefreegle.org/admin/users/'.$id.'/'.$username.'.json'.$q;

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );

  $result = curl_exec( $ch );

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new Exception('curl_errno: GetAllUsers ');
  }
  curl_close( $ch );

  //echo "<pre>".htmlspecialchars($result)."</pre>";
  //  {"errors":["The requested URL or resource could not be found."],"error_type":"not_found"}
  $fulluser = json_decode($result);
  //echo print_r($fulluser)."\r\n\r\n";
  if (property_exists($fulluser, 'errors')){
    echo print_r($fulluser)."\r\n\r\n";
    return null;
  }
  else{
    if (property_exists($fulluser, 'single_sign_on_record')){
      return $fulluser->single_sign_on_record->external_id;
    }
  }
  return false;
}

//////////////////////////////////////////////////////////////////////////
// GET USER EMAIL
// https://discourse.ilovefreegle.org//users/[username]/emails.json

function GetUserEmail($username){
  global $api_key,$api_username,$sso_secret;
  $q = "?api_key=$api_key&api_username=$api_username";
  $url = 'https://discourse.ilovefreegle.org/users/'.$username.'/emails.json'.$q;

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );

  $result = curl_exec( $ch );

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new Exception('curl_errno: GetUserEmail '.$username);
  }
  curl_close( $ch );

  //echo "<pre>".htmlspecialchars($result)."</pre>";
  //  {"errors":["The requested URL or resource could not be found."],"error_type":"not_found"}
  $useremails = json_decode($result);
  //echo print_r($useremails)."\r\n\r\n";
  if (property_exists($useremails, 'errors')){
    echo print_r($useremails)."\r\n\r\n";
    return null;
  }
  return $useremails->email;
}



//////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////
try{

  // Get all users from Discourse
  $allusers = GetAllUsers();
  echo "allusers: ".count($allusers)."\r\n";

  // Check all users are mods in MT, compiling report as we go
  $now = new DateTime();
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

    // Get external id from Discourse ie MT user id
    $external_id = GetUser($user->id,$user->username);
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
    //if( $ismod>50) break;
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

  echo $report;
  echo "\r\n";

  $mailedcentralmods = false;
  $headers = 'From: geeks@ilovefreegle.org';
  $subject = 'Discourse checkuser OK';
  if( $notmod || $notuser){
    $subject = 'Discourse checkuser USERS TO CHECK';
    //$sent = mail('cc+centralmods@phdcc.com', $subject, $report,$headers);
    $sent = mail('centralmods@ilovefreegle.org', $subject, $report,$headers);
    echo "Mail sent to centralmods: ".$sent."\r\n";
    $report = "Mail sent to centralmods: ".$sent."\r\n".$report;
    $mailedcentralmods = true;
  }

  if( !$mailedcentralmods && (date('w')==6)){
    //$sent = mail('cc+centralmods@phdcc.com', $subject, $report,$headers);
    $sent = mail('centralmods@ilovefreegle.org', $subject, $report,$headers);
    echo "Mail sent to centralmods: ".$sent."\r\n";
  }
  
  //$report = wordwrap($report, 70, "\r\n");
  //$sent = mail('cc+discoursecheckusers@phdcc.com', $subject, $report,$headers);
  $sent = mail('geek-alerts@ilovefreegle.org', $subject, $report,$headers);
  echo "Mail sent to geeks: ".$sent."\r\n";

} catch (Exception $e) {
  echo $e->getMessage();
  error_log("Failed with " . $e->getMessage());
}
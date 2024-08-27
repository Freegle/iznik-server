<?php

// hub/archive scripts/cron/discourse_checkusers.php
//
// Check for groups that are no represented by an active mod in Discourse
//  active = non-backup and lastaccess in last 6 months
// Also look for mods that have a TN email address as their preferred email
// Mail geeks daily and centralmods once a week

// NOT DONE YET
// BUT USE
// SELECT DISTINCT users.* FROM users INNER JOIN memberships ON users.id = memberships.userid INNER JOIN `groups` ON groups.id = memberships.groupid WHERE memberships.role IN ('Owner', 'Moderator') AND groups.type = 'Freegle' AND `lastaccess` > '2020-09-01 00:00:00' 

// 2021-03-28 Start
// 2022-01-02 Move groups not on Discourse to top of report
// 2022-06-24 Cope with external_id being native login id
// 2022-06-25 Check if mod's preferred mail is TN
// 2022-06-26 Change from native-login-id lookup to get by email instead

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

define('FROM_ADDR','geeks@ilovefreegle.org');
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
  $reportTop = 'Total Discourse users: '.count($allDusers)."\r\n";
  $modswithTNpreferredemails = 0;

  $sql = "SELECT * FROM `groups` WHERE `publish`=1 ORDER BY nameshort ASC";
  $allgroups = $dbhr->preQuery($sql);
  $reportTop .= "Published groups: ".count($allgroups)."\r\n";
  $groups = array();
  foreach ($allgroups as $group) {
    $groups[$group['id']] = false;
  }

  $sql = "SELECT DISTINCT users.*,groups.id as groupid,memberships.settings as settings FROM users INNER JOIN memberships ON users.id = memberships.userid ".
  "INNER JOIN `groups` ON groups.id = memberships.groupid ".
  "WHERE memberships.role IN ('Owner', 'Moderator') AND groups.type = 'Freegle' AND `lastaccess` > DATE_SUB(now(), INTERVAL 6 MONTH) ".
  "ORDER BY users.id";
  $allactivemodsgroups = $dbhr->preQuery($sql);

  // id, fullname, systemrole, lastaccess
  $sql = "SELECT DISTINCT users.* FROM users INNER JOIN memberships ON users.id = memberships.userid ".
  "INNER JOIN `groups` ON groups.id = memberships.groupid ".
  "WHERE memberships.role IN ('Owner', 'Moderator') AND groups.type = 'Freegle' AND `lastaccess` > DATE_SUB(now(), INTERVAL 6 MONTH) ".
  "ORDER BY users.id";
  $allactivemods = $dbhr->preQuery($sql);
  $reportTop .= 'Total active mods: '.count($allactivemods)." (in last 6 months)\r\n";
  
  $reportMid = "\r\nList of these volunteers not on Discourse:\r\n";

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
        echo "external_id: ".$duser->external_id."\r\n";

        // See if this external_id is an active mod:
        $found = false;
        foreach ($allactivemodsgroups as $activemod) {
          if( $duser->external_id==$activemod['id']){
            $found = true;
          }
        }
        if( !$found){
          // If not found (because external_id out of date) [or because inactive]) then lookup Discourse's email
          $demail = GetUserEmail($duser->username);
          $sql = "SELECT * FROM `users_emails` WHERE `email` = ?;";
          $userfromemails = $dbhr->preQuery($sql, [$demail]);
          foreach ($userfromemails as $userfromemail) {
            //echo "userfromemail: ".$userfromemail['userid']."\r\n";
            $duser->external_id = $userfromemail['userid'];
            break;
          }
        }

        /*$sql = "SELECT * FROM `users_logins` WHERE `uid` = ? AND `type` = 'Native';";
        $nativelogins = $dbhr->preQuery($sql, [$duser->external_id]);
        foreach ($nativelogins as $nativelogin) {
          $duser->external_id = $nativelogin['userid'];
        }*/

        $sql = "SELECT * FROM `users_emails` WHERE `userid` = ? AND `preferred` = 1 AND `email` LIKE '%@user.trashnothing.com';";
        $tnemails = $dbhr->preQuery($sql, [$duser->external_id]);
        foreach ($tnemails as $tnemail) {
          echo "TN email: ".$duser->external_id." - ".$tnemail['email']."\r\n";
          $reportTop .= "MOD HAS TN preferred email: ".$duser->external_id." - ".$tnemail['email']."\r\n";
          $modswithTNpreferredemails++;
        }
      } else {
      echo $duser->id."single_sign_on_record NOT OBJECT"."\r\n";
      }
    } else {
      echo $duser->id."NO EXTERNALID"."\r\n";
    }
    echo "duser: ".$duser->id." ".$duser->username." ".$duser->external_id."\r\n";
    if( $count<0) break;
    //if( $count>10) break;
  }

  $count = 0;
  $notondiscourse = 0;
  $lastmodid = 0;
  foreach ($allactivemodsgroups as $activemod) {
    $modfirstseen = $lastmodid != $activemod['id'];
    $lastmodid = $activemod['id'];
    $count++;
    $found = false;
    foreach ($allDusers as $duser) {
      if( $duser->external_id==$activemod['id']){
        //echo "FOUND\r\n";
        $found = true;
        break;
      }
    }
    if( $modfirstseen && !$found) {
      $reportMid .= "* ".$activemod['id'].": ".$activemod['fullname']." - ".$activemod['lastaccess']."\r\n";
      $notondiscourse++;
    }
    if( $found) {
      $groupid = $activemod['groupid'];
      // {"showmessages":1,"showmembers":1,"pushnotify":1,"active":1,"showchat":1,"eventsallowed":1,"volunteeringallowed":1,"emailfrequency":24}
      if( isset($activemod['settings'])){
        if( strpos($activemod['settings'],'"active":0')!==false){
          $found = false;
        }
      }
      if( $found){
        $groups[$groupid] = true;
      }
    }
    //if( $count>10) break;
  }

  $reportMid .= "\r\n";
  $reportMid .= "Active volunteers not on discourse: $notondiscourse\r\n\r\n";

  $notrepresentedcount = 0;
  $count = 0;
  foreach ($allgroups as $group) {
    $groupid = $group['id'];
    //echo "CHECK:".$groupid."\r\n";
    if( !$groups[$groupid]){
      $reportTop .= "NOT REPRESENTED ".$groupid." - ".$group['nameshort']."\r\n";
      foreach ($allactivemodsgroups as $activemod) {
        if( $activemod['groupid']==$groupid){
          $reportTop .= "* Moderator: ".$activemod['id']." - ".$activemod['fullname']."\r\n";
        }
      }
 
      $notrepresentedcount++;
    }
    //if( $count>10) break;
  }
  $reportTop .= "\r\n";
  $reportTop .= "Groups without active volunteers on Discourse: $notrepresentedcount\r\n";
  $reportTop .= "Mods with TN preferred emails: $modswithTNpreferredemails\r\n\r\n";

  $report = $reportTop.$reportMid;

  echo $report;
  echo "\r\n";

  $subject = 'Discourse: ';
  if( $notrepresentedcount==0) $subject .= "All groups represented. ";
  if( $notrepresentedcount>0) $subject .= "$notrepresentedcount groups not represented. ";
  if( $notondiscourse>0) $subject .= "$notondiscourse volunteers not signed up. ";
  if( $notrepresentedcount==0 && $notondiscourse==0) $subject .= 'all active volunteers on here';

  $mailedcentralmods = false;
  /*if( $notmod || $notuser){
    $subject = 'Discourse checkuser USERS TO CHECK';
    $sent = mail(CENTRALMODS_ADDR, $subject, $report,$headers);
    echo "Mail sent to centralmods: ".$sent."\r\n";
    $report = "Mail sent to centralmods: ".$sent."\r\n".$report;
    $mailedcentralmods = true;
  }*/

  if( (!$mailedcentralmods && (date('w')==6)) || ($notrepresentedcount>0)){
    //$sent = mail(CENTRALMODS_ADDR, $subject, $report,$headers);
    $message = \Swift_Message::newInstance()
        ->setSubject($subject)
        ->setFrom([FROM_ADDR => 'Geeks'])
        ->setTo([CENTRALMODS_ADDR => 'Volunteer Support'])
        ->setBody($report);
    Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);
    list ($transport, $mailer) = Mail::getMailer();
    $numSent = $mailer->send($message);
    echo "Mail sent to centralmods: ".$numSent."\r\n";
  }
  
  //$report = wordwrap($report, 70, "\r\n");
  //$sent = mail(GEEKSALERTS_ADDR, $subject, $report,$headers);
  if( $notrepresentedcount>0){
    $message = \Swift_Message::newInstance()
        ->setSubject($subject)
        ->setFrom([FROM_ADDR => 'Geeks'])
        ->setTo([GEEKSALERTS_ADDR => 'Geeks Alerts'])
        ->setBody($report);
    Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);
    list ($transport, $mailer) = Mail::getMailer();
    $numSent = $mailer->send($message);
    echo "Mail sent to geeks: ".$numSent."\r\n";
  }
}
catch (\Exception $e) {
  echo $e->getMessage();
  error_log("Failed with " . $e->getMessage());
  //$sent = mail(GEEKSALERTS_ADDR, "Discourse not_signed_up EXCEPTION", $e->getMessage(),$headers);
  $message = \Swift_Message::newInstance()
      ->setSubject('Discourse not_signed_up EXCEPTION')
      ->setFrom([FROM_ADDR => 'Geeks'])
      ->setTo([GEEKSALERTS_ADDR => 'Geeks Alerts'])
      ->setBody($e->getMessage());
  Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);
  list ($transport, $mailer) = Mail::getMailer();
  $numSent = $mailer->send($message);
  echo "Mail sent to geeks: ".$numSent."\r\n";
}
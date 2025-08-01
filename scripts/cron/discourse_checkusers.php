<?php

// bulk3  /var/www/iznik/scripts/cron/discourse_checkusers.php
// mail sent out via bulk2
//
// 2019-12-13 Use header auth not query params
// 2019-12-13 Add bounce reporting
// 2021-02-28 Fix DateTime namespace
// 2022-02-02 Look up altemail for mods
// 2024-09-01 set bio profile appropriately

namespace Freegle\Iznik;
use \Datetime;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

echo "checkusers\r\n";

// https://docs.discourse.org/
// DISCOURSE_SECRET
// DISCOURSE_APIKEY
// DISCOURSE_API
$api_username = 'system';

define('FROM_ADDR','geeks@ilovefreegle.org');
define('GEEKSALERTS_ADDR','geek-alerts@ilovefreegle.org');

define('ANNOUNCEMENTS_ID',7);

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
// GET USER2
// https://discourse.ilovefreegle.org/u/{username}

function GetUser2($id,$username){
  global $api_username;
  $url = 'https://discourse.ilovefreegle.org/u/'.$username.'.json';

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
  $user2 = json_decode($result);
  //echo print_r($user2)."\r\n\r\n";
  if( !$user2){ // Probably 429 Too Many Requests
    echo print_r($result)."\r\n\r\n";
    throw new \Exception('GetUser2 error A ');
  }
  else if (property_exists($user2, 'errors')){
    echo print_r($user2)."\r\n\r\n";
    throw new \Exception('GetUser2 error B '.$user2->errors[0]);
  }
  $user2 = $user2->user;
  //echo print_r($user2)."\r\n\r\n";
  return $user2;
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

//////////////////////////////////////////////////////////////////////////
// SET USER PROFILE FIELDS
// https://discourse.ilovefreegle.org/u/chris_cant.json
//  PUT
//  Form: watched_category_ids[]=7 &repeated

function SetWatchCategory($username,$alreadywatching,$catid){
  global $api_username;
  $url = 'https://discourse.ilovefreegle.org/users/'.$username.'.json';
  //echo "url: $url\r\n";

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );
  curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Api-Key: '.DISCOURSE_APIKEY,
    'Api-Username: '.$api_username
    //'accept: application/json',
    //'content-type: application/json'
  ));
  $data = '';
  foreach( $alreadywatching as $watchid){
    $data .= 'watched_category_ids[]='.$watchid.'&';
  }
  $data .= 'watched_category_ids[]='.$catid;
  //echo "data: $data\r\n";
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); 
  curl_setopt($ch, CURLOPT_POSTFIELDS,$data);

  $result = curl_exec( $ch );
  //echo "result: ".print_r($result)."\r\n";
  //echo htmlspecialchars($result);

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new \Exception('curl_errno: SetWatchCategory'.$username);
  }
  curl_close( $ch );
}

// SET USER BIO
// https://discourse.ilovefreegle.org/u/chris_cant.json
//  PUT
//  Form: bio_raw: <email> is a mod on groups...

function SetBio($username,$bio){
  global $api_username;
  $url = 'https://discourse.ilovefreegle.org/users/'.$username.'.json';
  //echo "url: $url\r\n";

  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
  curl_setopt( $ch, CURLOPT_USERAGENT, 'Freegle' );
  curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Api-Key: '.DISCOURSE_APIKEY,
    'Api-Username: '.$api_username
    //'accept: application/json',
    //'content-type: application/json'
  ));
  $fields = array(
    'bio_raw'=>$bio
  );
  $data = http_build_query($fields);
  //echo "data: $data\r\n";
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); 
  curl_setopt($ch, CURLOPT_POSTFIELDS,$data);

  $result = curl_exec( $ch );
  //echo "result: ".print_r($result)."\r\n";
  //echo htmlspecialchars($result);

  if ( curl_errno( $ch ) !== 0 ) {
    curl_close($ch);
    throw new \Exception('curl_errno: SetBio'.$username);
  }
  curl_close( $ch );
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
  $mailinglistmode = 0;
  $watchingcount = 0;
  $email_digests = 0;
  $notreceivingmail = 0;
  $notonannoucements = 0;
  $bioupdatedcount = 0;
  foreach ($allusers as $user) {
    //echo "user: ".print_r($user)."\r\n";

    if( $user->username=='freeglegeeks') {
      $system++;
      continue;  // Ignore system user
    }

    //if( $user->username!=='Chris_Cant') {
    //  continue;  // Test only with Chris
    //}

    usleep(250000);
    $count++;

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
      $useremail = '';
      $u = new User($dbhr, $dbhm, $external_id);
      if( $u){
        // Get email from Discourse
        $useremail = GetUserEmail($user->username);
        $ismod = $u->isModerator();
        if( !$ismod){
          // May have been merged so look up main account id
          $sql = "SELECT * FROM users_emails where email = ?;";
          $altemails = $dbhr->preQuery($sql, [$useremail]);
          $actualid = 0;
          foreach ($altemails as $altemail) {
            $actualid = $altemail['userid'];
          }
          if( $actualid!=0 && $actualid!=$external_id){
            $u = new User($dbhr, $dbhm, $actualid);
            $ismod = $u->isModerator();
          }        
        }

        if ($ismod) {  // Is mod: OK
          //echo "IS MOD\r\n";
          $ismod++;
        } else {  // Not a mod: report
          echo "NOT A MOD\r\n";
          $notmod++;
          $report .= 'NOT A MOD. MT id: '.$external_id.', Discourse username: '.$user->username.', email: '.$useremail."\r\n";
        }
      } else {
        // No entry in MT at all
        echo "NOT EVEN A USER\r\n";
        $notuser++;
        $report .= 'Not a MT user: Discourse username: '.$user->username.', email: '.$useremail."\r\n";
      }

      // SEE WHAT MAILS THEY ARE GETTING
      // Check for mailing list mode
      usleep(250000);
      $user2 = GetUser2($user->id,$user->username);

      $gettingAnyMails = false;
      //echo print_r($user2->watched_category_ids,true)."\r\n";
      //echo "watched_category_ids: ".count($user2->watched_category_ids)."\r\n";
      //echo "watched_first_post_category_ids: ".count($user2->watched_first_post_category_ids)."\r\n";
      //echo "tracked_category_ids: ".count($user2->tracked_category_ids)."\r\n";
      $watchedGroups = count($user2->watched_category_ids);
      $watchedGroupsFirstPost = count($user2->watched_first_post_category_ids);
      $trackedGroups = count($user2->tracked_category_ids);
      if( ($watchedGroups+$watchedGroupsFirstPost+$trackedGroups)>0) {
        $watchingcount++;
        $gettingAnyMails = true;
      }

      $mlm = false;
      if (property_exists($user2, 'user_option')){
        $mlm2 = $user2->user_option->mailing_list_mode;
        if( is_bool($mlm2) && $mlm2) {
          $mlm = true;
          $mailinglistmode++;
          $gettingAnyMails = true;
        }
        $eg = $user2->user_option->email_digests;
        if( is_bool($eg) && $eg) {
          $email_digests++;
          $gettingAnyMails = true;
        }
      
      } else echo "NO USER OPTIONS\r\n";


      if( !$gettingAnyMails){
        $useremail = GetUserEmail($user->username);
        $report .= 'Not getting any mails: Discourse username: '.$user->username.', email: '.$useremail."\r\n";
        $notreceivingmail++;
      } else if( !$mlm) {

        if( !in_array(ANNOUNCEMENTS_ID,$user2->watched_category_ids) &&
            !in_array(ANNOUNCEMENTS_ID,$user2->watched_first_post_category_ids) &&
            !in_array(ANNOUNCEMENTS_ID,$user2->tracked_category_ids)){
          $notonannoucements++;
          SetWatchCategory($user->username,$user2->watched_category_ids,ANNOUNCEMENTS_ID);
          echo 'Was not on Announcements: Discourse username: '.$user->username."\r\n";
          $report .= 'Was not on Announcements: Discourse username: '.$user->username."\r\n";
        }
      }

      if( $u){
        $bio = $useremail." is a mod on ";

        $memberships = $u->getModGroupsByActivity();
        $grouplist = [];
        foreach ($memberships as $membership) {
            $grouplist[] = $membership['namedisplay'];
        }
        $bio .= substr(implode(', ', $grouplist),0,1000);

        if( $bio != $user2->bio_raw){
          SetBio($user->username,$bio);
          $bioupdatedcount++;
        }
      }
    }
    //if( $count>5) break;
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
  $report .= "\r\n";
  $report .= "mailinglistmode: ($mailinglistmode)\r\n";
  $report .= "watchingcount: ($watchingcount)\r\n";
  $report .= "email_digests: ($email_digests)\r\n";
  $report .= "notonannoucements: ($notonannoucements)";
  if( $notonannoucements>0) $report .= " but hopefully now are";
  $report .= "\r\n";
  $report .= "notreceivingmail: ($notreceivingmail)\r\n";
  $report .= "bioupdatedcount: ($bioupdatedcount)\r\n";

  $report .= "\r\ndiscourse_checkusers.php runs on bulk3 with mail sent via bulk2\r\n";

  echo $report;
  echo "\r\n";

  $mailedcentralmods = false;
  $subject = 'Discourse checkuser OK';
  
  if( $notmod || $notuser){
    $subject = 'Discourse checkuser USERS TO CHECK';
    $message = \Swift_Message::newInstance()
        ->setSubject($subject)
        ->setFrom([FROM_ADDR => 'Geeks'])
        ->setTo([CENTRALMODS_ADDR => 'Volunteer Support'])
        ->setBody($report);
    Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);
    list ($transport, $mailer) = Mail::getMailer();
    $numSent = $mailer->send($message);
    //$sent = mail(CENTRALMODS_ADDR, $subject, $report,$headers);
    echo "Mail sent to centralmods: ".$numSent."\r\n";
    $report = "Mail sent to centralmods: ".$numSent."\r\n".$report;
    $mailedcentralmods = true;
  }

  if( !$mailedcentralmods && (date('w')==6)){
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
  $message = \Swift_Message::newInstance()
      ->setSubject($subject)
      ->setFrom([FROM_ADDR => 'Geeks'])
      ->setTo([GEEKSALERTS_ADDR => 'Geeks Alerts'])
      ->setBody($report);
  Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);
  list ($transport, $mailer) = Mail::getMailer();
  $numSent = $mailer->send($message);
  echo "Mail sent to geeks: ".$numSent."\r\n";

} catch (\Exception $e) {
 \Sentry\captureException($e);
  echo $e->getMessage();
  error_log("Failed with " . $e->getMessage());
  $message = \Swift_Message::newInstance()
      ->setSubject('Discourse checkuser EXCEPTION')
      ->setFrom([FROM_ADDR => 'Geeks'])
      ->setTo([GEEKSALERTS_ADDR => 'Geeks Alerts'])
      ->setBody($e->getMessage());
  Mail::addHeaders($dbhr, $dbhm, $message, Mail::MODMAIL);
  list ($transport, $mailer) = Mail::getMailer();
  $numSent = $mailer->send($message);
  echo "Mail sent to geeks: ".$numSent."\r\n";
}

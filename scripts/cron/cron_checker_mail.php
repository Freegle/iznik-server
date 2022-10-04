<?php

// scripts/cron/cron_checker_mail.php
//
// See also cron_checker_iznik.php
//
// Check for any changes in various cron and crontab files
// Flaw is that it is designed to be run by cron!
//
// When run, eg overnight, it sends an email either indicating OK or FAIL in subject, with details in body of mail.
// - Checks for any mention of crontab in syslog or syslog.1 files
// - Check for changes in crontab files for each user
// - Check for changes in the cron.d, hourly daily, weekly, monthly files, including missing entries.
// - The code creates a "cronlast" sub-directory with further sub-directories.
// - if a file has changed, then the old version is saved in a file such as "cronlast/root-last"
//
// Note: any changes will in effect only be reported ONCE, as the following day the crontab will not have changed.

define('FROM_ADDR','geeks@ilovefreegle.org');
define('GEEKSALERTS_ADDR','geek-alerts@ilovefreegle.org');


function makeDir($dir){
  if (!is_dir($dir)) mkdir($dir,0600);
  if (!is_dir($dir)) throw new Exception("Cannot create $dir");
}

function get_all_lines($file_handle) { 
  while (!feof($file_handle)) {
    yield fgets($file_handle);
  }
}

function grep($path, $find, $andnot){ // Has "crontab" but not "(root) LIST (root)"
  $fh = fopen($path, 'r');
  if( $fh===false) return $path." not found";

  $rv = "";

  foreach (get_all_lines($fh) as $line) {
    if( strpos($line,$find)!==false){
      if( strpos($line,$andnot)===false){
        $rv .= $path.": ".$line; // Has \r\n
      }
    }
  }
  fclose($fh);
  return $rv;
}

function checkFileChanges($srcDir,$lastDir,$checkfilename,$from){
  $msg = null;
  $lastPath = $lastDir.$checkfilename;
  $lastFile = file_exists($lastPath) ? file_get_contents($lastPath) : false;
  $lastFileLen = $lastFile!==false ? strlen($lastFile) : 0;
  if( $lastFile===false || (strlen($from) !== $lastFileLen)){
    $msg = $srcDir.$checkfilename.": CHANGED: FROM length ".$lastFileLen." to ".strlen($from);
    if( $lastFile!==false){
      rename($lastPath,$lastPath."-last");
      $msg .= ". Previous file at ".$lastPath."-last";
    }
    $hLastFile = fopen($lastPath, "w");
    fwrite($hLastFile, $from);
    fclose($hLastFile);
    chmod($lastPath, 0600);
  }
  return $msg;
}

function checkCronFileContents($srcDir,$lastDir){
  makeDir($lastDir);
  $rv = "";
  $filelist = "";
  $crontabsDir = scandir($srcDir);
  foreach ($crontabsDir as $crontabsFile) {
    if( $crontabsFile=="." || $crontabsFile=="..") continue;

    $filelist .= $crontabsFile.",";

    $cronFile = file_get_contents($srcDir.$crontabsFile);
    $msg = checkFileChanges($srcDir,$lastDir,$crontabsFile,$cronFile);
    if( $msg!=null) $rv .= $msg."\r\n";
  }
  $msg = checkFileChanges($srcDir,$lastDir,"_filelist.txt",$filelist);
  if( $msg!=null) $rv .= $msg."\r\n";

  return $rv;
}

echo "__DIR__ ". __DIR__."\r\n";

$report = "";

try{
  $cronlast = __DIR__."/cronlast/";
  $cronlastCronD = $cronlast."cron.d/";
  $cronlastCronDaily = $cronlast."cron.daily/";
  $cronlastCronHourly = $cronlast."cron.hourly/";
  $cronlastCronMonthly = $cronlast."cron.monthly/";
  $cronlastCronWeekly = $cronlast."cron.weekly/";

  // grep "crontab" /var/log/syslog
  $report .= grep('/var/log/syslog', "crontab", "(root) LIST (root)");

  // grep "crontab" /var/log/syslog.1
  $report .= grep('/var/log/syslog.1', "crontab", "(root) LIST (root)");

  // Check actual crontab files in /var/spool/cron/crontabs/
  $report .= checkCronFileContents('/var/spool/cron/crontabs/',$cronlast);

  // Check System crontab files in each of these directories
  //    /etc/cron.d
  $report .= checkCronFileContents('/etc/cron.d/',$cronlastCronD);
  //    /etc/cron.daily
  $report .= checkCronFileContents('/etc/cron.daily/',$cronlastCronDaily);
  //    /etc/cron.hourly
  $report .= checkCronFileContents('/etc/cron.hourly/',$cronlastCronHourly);
  //    /etc/cron.monthly
  $report .= checkCronFileContents('/etc/cron.monthly/',$cronlastCronMonthly);
  //    /etc/cron.weekly
  $report .= checkCronFileContents('/etc/cron.weekly/',$cronlastCronWeekly);

} catch(Exception $ex){
  echo $ex->getMessage()."\r\n";
}

$subject = "cron checker ".gethostname().": ".(strlen($report)===0?"OK":"FAIL");

$statusfile = fopen("/var/lib/cron-check-required", "w"); // Overwrite

if( strlen($report)===0) {
  $report = "No cron changes to report on ".gethostname()."\r\nMail not sent\r\n";
  if( $statusfile!==false){
    fwrite($statusfile, "No changes required\r\n");
    fclose($statusfile);
  }
} else {
  $report .= "\r\n Previous cron files at ".$cronlast."\r\n";
  if( $statusfile!==false){
    fwrite($statusfile, "cron check required\r\n");
    fclose($statusfile);
  }

  $headers = "From:" . FROM_ADDR;
  $sent = mail(GEEKSALERTS_ADDR,$subject,$report,$headers) ;

  if( $sent==false){
    echo "Mail FAILED to send to geeks\r\n";
  } else{
    echo "Mail sent to geeks\r\n";
  }
}

echo "RESULTS:\r\n".$report;


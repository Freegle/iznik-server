<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$users = $dbhr->preQuery("select id, added, userid, email from users_emails where email like '%@%@%' order by userid;");

foreach ($users as $user) {
  $email = $user['email'];

  if (preg_match('/(.*?)\-g(.*?)@/', $email, $matches)) {
    $newemail = $matches[1] . '-g' . $matches[2] . '@user.trashnothing.com';

    if (strpos($newemail, ',') !== FALSE) {
      error_log("Can't match #{$user['userid']} $email");
    } else {
        error_log("$email => $newemail");
        $u = new User($dbhr, $dbhm, $user['userid']);
        $u->removeEmail($email);
        $u->addEmail($newemail);
    }
  } else {
    error_log("Can't match #{$user['userid']} $email");
  }
}

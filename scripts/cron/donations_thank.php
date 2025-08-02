<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# Only thank each user once.  Only thank donations within the last 7 days to avoid weirdly thanking someone for
# an ancient donation.
$excludeCondition = Donations::getExcludedPayersCondition('payer');
$users = $dbhr->preQuery("SELECT DISTINCT users_donations.userid FROM users_donations 
   LEFT OUTER JOIN users_thanks ON users_thanks.userid = users_donations.userid 
   WHERE users_donations.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY) AND 
   users_donations.userid IS NOT NULL AND users_thanks.userid IS NULL AND $excludeCondition;");

foreach ($users as $user) {
    $u = User::get($dbhr, $dbhm, $user['userid']);
    error_log($u->getEmailPreferred());
    $u->thankDonation();
    $dbhm->preExec("INSERT INTO users_thanks (userid) VALUES (?);", [ $user['userid'] ]);
}

Utils::unlockScript($lockh);
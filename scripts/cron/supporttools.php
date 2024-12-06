<?php
# Add/remove Support Tools access based on which teams people are a member of.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$gotit = array_column($dbhr->preQuery("SELECT id FROM users WHERE systemrole IN (?, ?);", [
    User::SYSTEMROLE_SUPPORT,
    User::SYSTEMROLE_ADMIN
]), 'id');

$needits = array_column($dbhr->preQuery("SELECT DISTINCT(userid) FROM teams_members INNER JOIN teams ON teams.id = teams_members.teamid WHERE supporttools = 1;"), 'userid');

foreach ($needits as $needit) {
    if (!in_array($needit, $gotit)) {
        $u = new User($dbhr, $dbhm, $needit);
        error_log("#$needit " . $u->getName() . "(" . $u->getEmailPreferred() . ") needs it, adding");
        $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
    }
}

# Get values in $gotit not in $needits
$removeits = array_diff($gotit, $needits);

foreach ($removeits as $removeit) {
    $u = new User($dbhr, $dbhm, $removeit);
    error_log("#{$removeit} " . $u->getName() . "(" . $u->getEmailPreferred() . ") has it, but shouldn't, removing");
    $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
}
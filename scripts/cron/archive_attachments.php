<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We delete older profile images.  This is because the upload function does an INSERT.
$dups = $dbhr->preQuery("SELECT userid, MAX(id) AS max, COUNT(*) AS count FROM `users_images` GROUP BY userid HAVING count > 1;");
foreach ($dups as $dup) {
    $dbhm->preExec("DELETE FROM users_images WHERE userid = ? AND id < ?;", [
        $dup['userid'],
        $dup['max']
    ]);
}

Utils::unlockScript($lockh);
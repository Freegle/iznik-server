<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

try {
    # Delete old ones which haven't been approved.
    $olds = $dbhr->preQuery("SELECT id FROM admins WHERE complete IS NULL AND pending = 1 AND DATEDIFF(NOW(), created) >= 31;");

    foreach ($olds as $old) {
        $a = new Admin($dbhr, $dbhm, $old['id']);
        $a->delete();
    }

    $a = new Admin($dbhr, $dbhm);

    # Generate copies of suggested ADMINs.
    $suggesteds = $dbhr->preQuery("SELECT * FROM admins WHERE groupid IS NULL AND complete IS NULL");

    foreach ($suggesteds as $suggested) {
        # See if we have any groups who have not got a copy of this ADMIN.
        $groups = $dbhr->preQuery("SELECT * FROM `groups` WHERE type = ? AND publish = 1 AND external IS NULL;", [
            Group::GROUP_FREEGLE
        ]);

        foreach ($groups as $group) {
            $g = new Group($dbhr, $dbhm, $group['id']);

            if ($g->getSetting('autoadmins', 1)) {
                $already = $dbhr->preQuery("SELECT * FROM admins WHERE groupid = ? AND parentid = ?;", [
                    $group['id'],
                    $suggested['id']
                ]);

                if (count($already)) {
                    error_log("Already got {$suggested['id']} for {$group['nameshort']}");
                } else {
                    error_log("Not got {$suggested['id']} for {$group['nameshort']}, create");
                    $a = new Admin($dbhr, $dbhm, $suggested['id']);
                    $a->copyForGroup($group['id']);
                }
            } else {
                error_log("Auto-admins disabled {$suggested['id']} for {$group['nameshort']}");
            }
        }

        $dbhm->preExec("UPDATE admins SET complete = NOW() WHERE id = ?;", [
            $suggested['id']
        ]);
    }

    $a->process(NULL, FALSE, TRUE);

    Utils::unlockScript($lockh);
} catch (\Exception $e) {
    \Sentry\captureException($e);
}
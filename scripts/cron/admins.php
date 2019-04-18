<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/group/Admin.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$a = new Admin($dbhr, $dbhm);

# Generate copies of suggested ADMINs.
$suggesteds = $dbhr->preQuery("SELECT * FROM admins WHERE groupid IS NULL AND complete IS NULL");

foreach ($suggesteds as $suggested) {
    # See if we have any groups who have not got a copy of this ADMIN.
    $groups = $dbhr->preQuery("SELECT * FROM groups WHERE type = ? AND publish = 1;", [
        Group::GROUP_FREEGLE
    ]);

    foreach ($groups as $group) {
        $g = new Group($dbhr, $dbhm, $group['id']);

        if ($g->getSetting('suggestedadmins', 1)) {
            $already = $dbhr->preQuery("SELECT * FROM admins WHERE groupid = ? AND parentid = ?;", [
                $group['id'],
                $suggested['id']
            ]);

            if (count($already)) {
                #error_log("Already got {$suggested['id']} for {$group['nameshort']}");
            } else {
                error_log("Not got {$suggested['id']} for {$group['nameshort']}, create");
                $a = new Admin($dbhr, $dbhm, $suggested['id']);
                $a->copyForGroup($group['id']);
            }
        } else {
            error_log("Disabled {$suggested['id']} for {$group['nameshort']}");
        }
    }

    $dbhm->preExec("UPDATE admins SET complete = NOW() WHERE id = ?;", [
        $suggested['id']
    ]);
}

$a->process();

unlockScript($lockh);
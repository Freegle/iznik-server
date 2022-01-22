<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Update record of which groups are on TN.
#
# Not in a single call as this seems to hit a deadlock.
$groups = $dbhr->preQuery("SELECT id FROM `groups` WHERE ontn = 1;");
foreach ($groups as $group) {
    $dbhm->preExec("UPDATE `groups` SET ontn = 0 WHERE id = ?;", [
        $group['id']
    ]);
}

$tngroups = file_get_contents("https://trashnothing.com/modtools/api/freegle-groups?key=" . TNKEY);
$tngroups = str_replace("{u'", "{'", $tngroups);
$tngroups = str_replace(", u'", ", '", $tngroups);
$tngroups = str_replace("'", '"', $tngroups);
$tngroups = json_decode($tngroups, TRUE);

# Ensure the polyindex is set correctly.  It can get out of step if someone updates the DB manually.
#
# Bad geometries can sometimes be fixed like this:
#
# SELECT ST_AsText(ST_Simplify(St_Buffer(ST_GeomFromText('...'), 0.001), 0.001))
#
$groups = $dbhr->preQuery("SELECT id, nameshort FROM `groups` WHERE ST_IsValid(polyindex) = 0 OR ST_IsValid(ST_GeomFromText(poly, {$dbhr->SRID()})) = 0 OR ST_IsValid(ST_GeomFromText(polyofficial, {$dbhr->SRID()})) = 0 AND type = ?;", [
    Group::GROUP_FREEGLE
]);

foreach ($groups as $group) {
    $headers = 'From: geeks@ilovefreegle.org';
    mail('geek-alerts@ilovefreegle.org', "Bad polygon in group {$group['id']} {$group['nameshort']}", "", $headers);
}

$groups = $dbhr->preQuery("SELECT id, nameshort FROM `groups` ORDER BY id;");

foreach ($groups as $group) {
    $g = $dbhr->preQuery("SELECT ST_AsText(polyindex) AS current, ST_AsText(ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'))) AS geomtext FROM `groups` WHERE id = ?;", [
        $group['id']
    ]);

    if ($g[0]['current'] != $g[0]['geomtext']) {
        $headers = 'From: geeks@ilovefreegle.org';
        mail('geek-alerts@ilovefreegle.org', "Polyindex wrong for group group {$group['id']} {$group['nameshort']}", "", $headers);
        $dbhm->preExec("UPDATE `groups` SET polyindex = ST_GeomFromText(?, {$dbhr->SRID()}) WHERE id = ?;", [
            $g[0]['geomtext'],
            $group['id']
        ]);
    }
}

$g = new Group($dbhr, $dbhm);
foreach ($tngroups as $gname => $tngroup) {
    if ($tngroup['listed']) {
        $gid = $g->findByShortName($gname);
        $dbhm->preExec("UPDATE `groups` SET ontn = 1 WHERE id = ?;", [$gid]);
    }
}

$date = date('Y-m-d', strtotime("yesterday"));
$groups = $dbhr->preQuery("SELECT * FROM `groups`;");
foreach ($groups as $group) {
    error_log($group['nameshort']);
    $s = new Stats($dbhr, $dbhm, $group['id']);
    $s->generate($date);
}

# Find what proportion of overall activity an individual group is responsible for.  We will use this when calculating
# a fundraising target.
$date = date('Y-m-d', strtotime("30 days ago"));
$totalact = $dbhr->preQuery("SELECT SUM(count) AS total FROM stats INNER JOIN `groups` ON stats.groupid = groups.id WHERE stats.type = ? AND groups.type = ? AND publish = 1 AND onhere = 1 AND date >= ?;", [
    Stats::APPROVED_MESSAGE_COUNT,
    Group::GROUP_FREEGLE,
    $date
]);

$target = DONATION_TARGET;
$fundingcalc = 0;

foreach ($totalact as $total) {
    $tot = $total['total'];

    $groups = $dbhr->preQuery("SELECT * FROM `groups` WHERE type = ? AND publish = 1 AND onhere = 1 ORDER BY LOWER(nameshort) ASC;", [
        Group::GROUP_FREEGLE
    ]);

    foreach ($groups as $group) {
        $acts = $dbhr->preQuery("SELECT SUM(count) AS count FROM stats WHERE stats.type = ? AND groupid = ? AND date >= ?;", [
            Stats::APPROVED_MESSAGE_COUNT,
            $group['id'],
            $date
        ]);

        #error_log("#{$group['id']} {$group['nameshort']} pc = $pc from {$acts[0]['count']} vs $tot");
        $pc = 100 * $acts[0]['count'] / $tot;

        $dbhm->preExec("UPDATE `groups` SET activitypercent = ? WHERE id = ?;", [
            $pc,
            $group['id']
        ]);

        # Calculate fundraising target.  Our fair share would be $pc * $target / 100, but we stretch that out a bit
        # because the larger groups are likely to include more affluent people.
        #
        # Round up to £50 for small groups.
        $portion = ceil($pc * $target / 100) * 10;
        $portion = max(50, $portion);
        error_log("{$group['nameshort']} target £$portion");
        $fundingcalc += $portion;

        $dbhm->preExec("UPDATE `groups` SET fundingtarget = ? WHERE id = ?;", [
            $portion,
            $group['id']
        ]);

        # Find when the group was last moderated.
        $sql = "SELECT MAX(approvedat) AS max FROM messages_groups WHERE groupid = ? AND approvedby IS NOT NULL;";
        $maxs = $dbhr->preQuery($sql, [$group['id']]);
        $dbhm->preExec("UPDATE `groups` SET lastmoderated = ? WHERE id = ?;", [
            $maxs[0]['max'],
            $group['id']
        ]);

        # Find the last auto-approved message
        $timeq = $group['lastautoapprove'] ? ("AND timestamp >= '" . date("Y-m-d H:i:s", strtotime($group['lastautoapprove'])) . "' ") : '';
        $logs = $dbhr->preQuery("SELECT MAX(timestamp) AS max FROM logs WHERE groupid = ? $timeq AND logs.type = 'Message' AND logs.subtype = 'Autoapproved';", [
            $group['id']
        ]);
        $dbhm->preExec("UPDATE `groups` SET lastautoapprove = ? WHERE id = ? AND lastautoapprove < ?;", [
            $logs[0]['max'],
            $group['id'],
            $logs[0]['max']
        ]);

        # Count mods who have been logged in within the last 30 days.
        $start = date('Y-m-d', strtotime("30 days ago"));
        $sql = "SELECT COUNT(DISTINCT(users.id)) AS count FROM users INNER JOIN memberships ON memberships.userid = users.id WHERE groupid = ? AND role IN ('Owner', 'Moderator') AND lastaccess > ?;";
        $actives = $dbhr->preQuery($sql, [
            $group['id'],
            $start
        ]);

        $dbhm->preExec("UPDATE `groups` SET activemodcount = ? WHERE id = ?;", [
            $actives[0]['count'],
            $group['id']
        ]);

        # Count owners and mods not active on this group but active on other groups in the last 30 days.
        $start = date('Y-m-d', strtotime("30 days ago"));
        $mods = $dbhr->preQuery("SELECT COUNT(DISTINCT(userid)) AS count FROM memberships WHERE groupid = ? AND role IN ('Owner') AND userid NOT IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid = ? AND arrival > ? AND approvedby IS NOT NULL) AND userid IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid != ? AND arrival > ? AND approvedby IS NOT NULL);", [
            $group['id'],
            $group['id'],
            $start,
            $group['id'],
            $start
        ]);
        $dbhm->preExec("UPDATE `groups` SET backupownersactive = ? WHERE id = ?;", [
            $mods[0]['count'],
            $group['id']
        ]);

        $mods = $dbhr->preQuery("SELECT COUNT(DISTINCT(userid)) AS count FROM memberships WHERE groupid = ? AND role IN ('Moderator') AND userid NOT IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid = ? AND arrival > ? AND approvedby IS NOT NULL) AND userid IN (SELECT DISTINCT(approvedby) FROM messages_groups WHERE groupid != ? AND arrival > ? AND approvedby IS NOT NULL);", [
            $group['id'],
            $group['id'],
            $start,
            $group['id'],
            $start
        ]);
        $dbhm->preExec("UPDATE `groups` SET backupmodsactive = ? WHERE id = ?;", [
            $mods[0]['count'],
            $group['id']
        ]);
    }
}

error_log("\n\nTotal target £$fundingcalc");
# Update outcomes stats
$mysqltime = date("Y-m-01", strtotime("13 months ago"));
$stats = $dbhr->preQuery("SELECT groupid, SUM(count) AS count, CONCAT(YEAR(date), '-', LPAD(MONTH(date), 2, '0')) AS date FROM stats WHERE type = ? AND date > ? GROUP BY groupid, YEAR(date), MONTH(date);", [
    Stats::OUTCOMES,
    $mysqltime
]);

foreach ($stats as $stat) {
    $dbhm->preExec("REPLACE INTO stats_outcomes (groupid, count, date) VALUES (?, ?, ?);", [
        $stat['groupid'],
        $stat['count'],
        $stat['date']
    ]);
}
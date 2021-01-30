<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm, $dbconfig;

$lockh = Utils::lockScript(basename(__FILE__));

# Don't keep user deletion logs indefinitely - this may be useful for a while for diagnosis, but not long term.
error_log("Purge user deletion logs");

try {
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    $total = 0;
    do {
        $sql = "DELETE FROM logs WHERE `type` = '" . Log::TYPE_USER . "' AND `subtype` = '" . Log::SUBTYPE_DELETED . "' AND `timestamp` < '$start' LIMIT 1000;";
        $count = $dbhm->exec($sql);
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
        sleep(1);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete user-deletion logs " . $e->getMessage());
}

# Delete invalid logs with no subtype.
error_log("Purge no subtype logs");

try {
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    $total = 0;
    do {
        $sql = "DELETE FROM logs WHERE (`type` = '" . Log::TYPE_USER . "' OR `type` = '" . Log::TYPE_GROUP . "') AND `subtype` = '' AND `timestamp` < '$start' LIMIT 1000;";
        $count = $dbhm->exec($sql);
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete blank logs " . $e->getMessage());
}

# Delete logs for old bounces.  We get a huge number of logs over time.  This doesn't affect bounce processing
# because we do that from bounces_emails.
try {
    error_log("Logs for old bounces:");
    $start = date('Y-m-d', strtotime("midnight 90 days ago"));
    $total = 0;
    do {
        $sql = "SELECT id FROM logs WHERE `type` = '" . Log::TYPE_USER . "' AND `subtype` = '" . Log::SUBTYPE_BOUNCE . "' AND `timestamp` < '$start' LIMIT 1000;";
        $logs = $dbhm->query($sql);
        $count = 0;

        $sql = "DELETE FROM logs WHERE id IN (0 ";

        foreach ($logs as $log) {
            $sql .= ", " . $log['id'];
            $count++;
            $total++;
        }

        $dbhm->exec($sql . ");");

        error_log("...$total");
        set_time_limit(600);
        usleep(200000);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete non-Freegle logs " . $e->getMessage());
}

# Delete logs for messages which no longer exist.  Typically spam, but we need to keep the logs for 30 days
# as they might relate to mod activity.
try {
    error_log("Logs for messages no longer around:");
    $total = 0;
    $start = date('Y-m-d', strtotime("midnight 30 days ago"));
    $end = date('Y-m-d', strtotime("midnight 60 days ago"));
    $logs = $dbhm->query("SELECT logs.id FROM logs LEFT JOIN messages ON messages.id = logs.msgid WHERE logs.type = 'Message' AND logs.msgid IS NOT NULL AND messages.id IS NULL AND logs.timestamp >= '$end' AND logs.timestamp < '$start';");
    error_log("Found " . count($logs));

    foreach ($logs as $log) {
        $sql = "DELETE FROM logs WHERE id = {$log['id']};";
        $count = $dbhm->exec($sql);
        $total++;

        if ($total % 1000 == 0) {
            error_log("...$total");
        }
    }
} catch (\Exception $e) {
    error_log("Failed to delete bounce emails" . $e->getMessage());
}

# Delete old email bounces.  Any genuinely bouncing emails will result in the user being set as bouncing = 1 fairly
# rapidly.
try {
    error_log("Old bounces:");
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM bounces_emails WHERE `date` < '$start' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete bounce emails" . $e->getMessage());
}

# Don't keep user creation logs indefinitely - the reason we created a user is only really relevant for diagnosis,
error_log("Purge user creation logs");

try {
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    $total = 0;
    do {
        $sql = "DELETE FROM logs WHERE `type` = '" . Log::TYPE_USER . "' AND `subtype` = '" . Log::SUBTYPE_CREATED . "' AND `timestamp` < '$start' LIMIT 1000;";
        $count = $dbhm->exec($sql);
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete user creation logs " . $e->getMessage());
}

error_log("Purge email logs");

try {
    $start = date('Y-m-d', strtotime("25 hours ago"));
    $end = date('Y-m-d');
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM logs_emails WHERE `timestamp` < '$start' OR `timestamp` > '$end' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete email logs logs " . $e->getMessage());
}

error_log("Purge main logs");

try {
    # Non-Freegle groups only keep data for 31 days.
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Non-Freegle logs");
    $groups = $dbhr->preQuery("SELECT id FROM groups WHERE type != 'Freegle';");
    foreach ($groups as $group) {
        $total = 0;
        do {
            $count = $dbhm->exec("DELETE FROM logs WHERE `timestamp` < '$start' AND groupid IS NOT NULL AND groupid = {$group['id']} LIMIT 1000;");
            $total += $count;
            error_log("...$total");
            set_time_limit(600);
        } while ($count > 0);
    }
} catch (\Exception $e) {
    error_log("Failed to delete non-Freegle logs " . $e->getMessage());
}

# In the main logs table we might have logs that can be removed once enough time has elapsed for us using them for PD.
$start = date('Y-m-d', strtotime("midnight 7 days ago"));
$keys = [
    'user' => 'users',
    'byuser' => 'users',
    'msgid' => 'messages',
    'groupid' => 'groups',
    'configid' => 'mod_configs',
    'stdmsgid' => 'mod_stdmsgs',
    'bulkopid' => 'mod_bulkops'
];

//foreach ($keys as $att => $table) {
//    error_log("Logs for $att not in $table");
//    $total = 0;
//    do {
//        $count = $dbhm->exec("DELETE FROM logs WHERE timestamp < '$start' AND $att IS NOT NULL AND $att <> 0 AND $att NOT IN (SELECT id FROM $table) LIMIT 1000;");
//        $total += $count;
//        error_log("...$total");
//    } while ($count > 0);
//}

# Src logs.
$start = date('Y-m-d', strtotime("midnight 30 days ago"));
error_log("Purge src logs before $start");

try {
    error_log("Src logs:");
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM logs_src WHERE `date` < '$start' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete src logs " . $e->getMessage());
}

# JS error logs.
$start = date('Y-m-d', strtotime("midnight 30 days ago"));
error_log("Purge JS error logs before $start");

try {
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM logs_errors WHERE `date` < '$start' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete src logs " . $e->getMessage());
}

$start = date('Y-m-d', strtotime("midnight 1 day ago"));
error_log("Purge detailed logs before $start");

try {
    error_log("Plugin logs:");
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM logs WHERE `timestamp` < '$start' AND TYPE = 'Plugin' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete Plugin logs " . $e->getMessage());
}

# Logs for users who no longer exist.
$start = date('Y-m-d', strtotime("midnight 30 days ago"));
error_log("Purge logs for users who don't exist before $start");

try {
    $total = 0;
    do {
        $logs = $dbhr->preQuery("SELECT logs.id FROM logs LEFT JOIN users ON users.id = logs.user WHERE `timestamp` < '$start' AND logs.user IS NOT NULL AND users.id IS NULL LIMIT 1000;", NULL, FALSE, FALSE);

        foreach ($logs as $log) {
            $dbhm->exec("DELETE FROM logs WHERE id = {$log['id']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
                set_time_limit(600);
            }
        }
    } while (count($logs) > 0);
} catch (\Exception $e) {
    error_log("Failed to delete Plugin logs " . $e->getMessage());
}

$start = date('Y-m-d', strtotime("48 hours ago"));

try {
    error_log("API logs:");
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM logs_api WHERE `date` < '$start' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete API logs " . $e->getMessage());
}

$start = date('Y-m-d', strtotime("4 hours ago"));

try {
    error_log("SQL logs:");
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM logs_sql WHERE `date` < '$start' LIMIT 1000;");
        $total += $count;
        set_time_limit(600);
        error_log("...$total");
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete SQL logs " . $e->getMessage());
}

# No value to this data beyond a certain point, so make sure it doesn't grow forever.
$start = date('Y-m-d', strtotime("midnight 2 years ago"));
error_log("Purge user activity logs entirely before $start");

try {
    $total = 0;
    do {
        $count = $dbhm->exec("DELETE FROM users_active WHERE timestamp < '$start' LIMIT 1000;");
        $total += $count;
        error_log("...$total");
        set_time_limit(600);
    } while ($count > 0);
} catch (\Exception $e) {
    error_log("Failed to delete Plugin logs " . $e->getMessage());
}

error_log("Completed");

Utils::unlockScript($lockh);
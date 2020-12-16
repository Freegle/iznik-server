<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

try {
    $sql = "use information_schema; SELECT * FROM KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'messages' AND table_schema = '" . SQLDB . "';";
    $schema = $dbhr->preQuery($sql);
    $sql = $dbhr->preQuery("use " . SQLDB);

    # Purge info about old admins which have been sent to completion.
    $start = date('Y-m-d', strtotime("midnight 90 days ago"));
    $sql = "SELECT DISTINCT(admins.id) FROM admins INNER JOIN admins_users ON admins_users.adminid = admins.id WHERE complete <= '$start';";
    $admins = $dbhm->query($sql)->fetchAll();
    $total = 0;

    foreach ($admins as $admin) {
        error_log("...admin {$admin['id']}");
        do {
            $any = $dbhr->query("SELECT COUNT(*) AS count FROM admins_users WHERE adminid = {$admin['id']};");
            error_log("...left {$any[0]['count']}");
            $dbhm->preExec("DELETE FROM admins_users WHERE adminid = {$admin['id']} LIMIT 10000;");
        } while ($any[0]['count'] > 0);
    }

    # Purge old users_nearby data - we only need the last 31 days, really, because that's used to avoid duplicates.
    $total = 0;
    do {
        $start = date('Y-m-d', strtotime("midnight 90 days ago"));
        $sql = "SELECT * FROM users_nearby WHERE timestamp <= '$start' LIMIT 1;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->preExec("DELETE FROM users_nearby WHERE userid = {$msg['userid']} AND msgid = {$msg['msgid']};");
            #$dbhm->preExec("DELETE FROM users_nearby WHERE timestamp <= '$start' LIMIT 1000;");

            $total ++;

            if ($total % 100 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    # Purge Yahoo notify messages
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    error_log("Purge Yahoo notify messages before $start");
    $total = 0;

    $m = new Message($dbhr, $dbhm);

    do {
        $sql = "SELECT messages.id FROM messages WHERE fromaddr = 'notify@yahoogroups.com' AND date <= '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            #error_log($msg['id']);
            $m->quickDelete($schema, $msg['id']);
            $total++;

            if ($total % 10 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    # Purge old messages_history - it's only used for spam checking so we don't need to keep it indefinitely.
    $start = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
    error_log("Purge messages_history before $start");

    $total = 0;
    do {
        $sql = "SELECT id FROM messages_history WHERE arrival < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->preExec("DELETE FROM messages_history WHERE id = {$msg['id']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Purge messages which have been in Spam, Pending or Queued for ages.  Probably the group isn't being sync'd properly
    $start = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
    error_log("Purge pending / queued before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_groups WHERE collection IN ('" . MessageCollection::SPAM . "', '" . MessageCollection::PENDING . "') AND arrival < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->preExec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Any drafts which are also on groups are not really drafts.  This must be due to a bug.
    $msgids = $dbhm->query("SELECT messages_drafts.msgid FROM messages_drafts INNER JOIN messages_groups ON messages_groups.msgid = messages_drafts.msgid");
    foreach ($msgids as $msgid) {
        $dbhm->preExec("DELETE FROM messages_drafts WHERE msgid = ?;", [
            $msgid['msgid']
        ]);
    }

    # Purge old drafts.
    $start = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
    error_log("Purge old drafts before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_drafts WHERE timestamp < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->preExec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Purge old non-Freegle messages
    $start = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
    error_log("Purge non-Freegle before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_groups INNER JOIN groups ON messages_groups.groupid = groups.id WHERE `arrival` <= '$start' AND groups.type != 'Freegle' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->preExec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Now purge messages which have been deleted - we keep them for a while for PD purposes.
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    $end = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
    error_log("Purge deleted messages before $start");
    $total = 0;

    do {
        $sql = "SELECT messages.id FROM messages WHERE date >= '$end' AND deleted IS NOT NULL AND deleted <= '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->preExec("DELETE FROM messages WHERE id = {$msg['id']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    # We don't need the HTML content or full message for old messages - we're primarily interested in the text body, and
    # these are large attributes.
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    $end = date('Y-m-d', strtotime(MessageCollection::RECENTPOSTS));
    error_log("Purge HTML body for messages before $start");
    $total = 0;
    $id = NULL;

    do {
        $sql = "SELECT id FROM messages WHERE arrival >= '$end' AND arrival <= '$start' AND htmlbody IS NOT NULL LIMIT 1000;";
        $msgs = $dbhr->query($sql);
        $count = 0;
        foreach ($msgs as $msg) {
            $sql = "UPDATE messages SET htmlbody = NULL WHERE id = {$msg['id']};";

            $count = $dbhm->preExec($sql);
            $total += $count;
            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while ($count > 0);

    $start = date('Y-m-d', strtotime("midnight 30 days ago"));
    $end = date('Y-m-d', strtotime("midnight 60 days ago"));
    error_log("Purge message for messages before $start and after $end");
    $total = 0;
    $id = NULL;

    do {
        $sql = "SELECT id FROM messages WHERE arrival >= '$end' AND arrival <= '$start' AND message IS NOT NULL AND LENGTH(message) > 0;";
        $msgs = $dbhr->query($sql);
        $count = 0;

        foreach ($msgs as $msg) {
            $sql = "UPDATE messages SET message = NULL WHERE id = {$msg['id']};";
            $count = $dbhm->preExec($sql);
            $total += $count;
            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while ($count > 0);

    # Now purge messages which are stranded, not on any groups and not referenced from any chats or drafts.
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    error_log("Purge stranded messages before $start");
    $total = 0;

    do {
        $sql = "SELECT messages.id FROM messages LEFT JOIN messages_groups ON messages_groups.msgid = messages.id LEFT JOIN chat_messages ON chat_messages.refmsgid = messages.id LEFT JOIN messages_drafts ON messages_drafts.msgid = messages.id WHERE messages.arrival <= '$start' AND messages_groups.msgid IS NULL AND chat_messages.refmsgid IS NULL AND messages_drafts.msgid IS NULL LIMIT 1000;";
        $msgs = $dbhr->query($sql);
        $count = 0;

        foreach ($msgs as $msg) {
            #error_log("...{$msg['id']}");
            $sql = "DELETE FROM messages WHERE id = {$msg['id']};";
            $count = $dbhm->preExec($sql);
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while ($count > 0);

    error_log("Deleted $total");

    # This shouldn't happen due to delete cascading...but we've seen 7 such emails exist, and one caused future
    # problems.  So zap 'em.
    $dbhm->preExec("DELETE FROM users_emails WHERE userid IS NULL");
} catch (\Exception $e) {
    error_log("Failed with " . $e->getMessage());
    mail(GEEKS_ADDR, "Daily message purge failed", $e->getMessage());
    exit(1);
}

Utils::unlockScript($lockh);
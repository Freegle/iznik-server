<?php
#
# Purge logs. We do this in a script rather than an event because we want to chunk it, otherwise we can hang the
# cluster with an op that's too big.
#
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');

$lockh = lockScript(basename(__FILE__));

try {
    # Bypass our usual DB class as we don't want the overhead nor to log.
    $dsn = "mysql:host={$dbconfig['host']};dbname=iznik;charset=utf8";
    $dbhmold = $dbhm;

    $dbhm = new PDO($dsn, $dbconfig['user'], $dbconfig['pass'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => FALSE
    ));

    $dsn = "mysql:host={$dbconfig['host']};dbname=information_schema;charset=utf8";

    $dbhschema = new PDO($dsn, $dbconfig['user'], $dbconfig['pass'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => FALSE
    ));

    $sql = "SELECT * FROM KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'messages' AND table_schema = '" . SQLDB . "';";
    $schema = $dbhschema->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    # Purge Yahoo notify messages
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    error_log("Purge Yahoo notify messages before $start");
    $total = 0;

    $m = new Message($dbhmold, $dbhmold);

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
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge messages_history before $start");

    $total = 0;
    do {
        $sql = "SELECT id FROM messages_history WHERE arrival < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->exec("DELETE FROM messages_history WHERE id = {$msg['id']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Purge messages which have been in Spam, Pending or Queued for ages.  Probably the group isn't being sync'd properly
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge pending / queued before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_groups WHERE collection IN ('" . MessageCollection::SPAM . "', '" . MessageCollection::PENDING . "', '" . MessageCollection::QUEUED_YAHOO_USER . "') AND arrival < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->exec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Purge messages which have been stuck waiting for Yahoo users for ages.
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge waiting for Yahoo before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_groups WHERE collection = '" . MessageCollection::QUEUED_YAHOO_USER . "' AND arrival < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->exec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Purge old drafts.
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge old drafts before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_drafts WHERE timestamp < '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->exec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Purge old non-Freegle messages
    $start = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge non-Freegle before $start");

    $total = 0;
    do {
        $sql = "SELECT msgid FROM messages_groups INNER JOIN groups ON messages_groups.groupid = groups.id WHERE `arrival` <= '$start' AND groups.type != 'Freegle' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->exec("DELETE FROM messages WHERE id = {$msg['msgid']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");

    # Now purge messages which have been deleted - we keep them for a while for PD purposes.
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    $end = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge deleted messages before $start");
    $total = 0;

    do {
        $sql = "SELECT messages.id FROM messages WHERE date >= '$end' AND deleted IS NOT NULL AND deleted <= '$start' LIMIT 1000;";
        $msgs = $dbhm->query($sql)->fetchAll();
        foreach ($msgs as $msg) {
            $dbhm->exec("DELETE FROM messages WHERE id = {$msg['id']};");
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    # We don't need the HTML content or full message for old messages - we're primarily interested in the text body, and
    # these are large attributes.
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    $end = date('Y-m-d', strtotime("midnight 31 days ago"));
    error_log("Purge HTML body for messages before $start");
    $total = 0;
    $id = NULL;

    do {
        $sql = "SELECT id FROM messages WHERE arrival >= '$end' AND arrival <= '$start' AND htmlbody IS NOT NULL LIMIT 1000;";
        $msgs = $dbhr->preQuery($sql);
        error_log("Found " . count($msgs));
        foreach ($msgs as $msg) {
            $sql = "UPDATE messages SET htmlbody = NULL WHERE id = {$msg['id']};";

            # Use dbhmold with no logging to get retrying.
            $count = $dbhmold->preExec($sql, NULL, FALSE);
            $total += $count;
            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    $start = date('Y-m-d', strtotime("midnight 30 days ago"));
    $end = date('Y-m-d', strtotime("midnight 60 days ago"));
    error_log("Purge message for messages before $start and after $end");
    $total = 0;
    $id = NULL;

    do {
        $sql = "SELECT id FROM messages WHERE arrival >= '$end' AND arrival <= '$start' AND message IS NOT NULL AND LENGTH(message) > 0;";
        $msgs = $dbhr->preQuery($sql);
        error_log("Found " . count($msgs));
        foreach ($msgs as $msg) {
            $sql = "UPDATE messages SET message = NULL WHERE id = {$msg['id']};";
            $count = $dbhmold->preExec($sql, NULL, FALSE);
            $total += $count;
            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    # Now purge messages which are stranded, not on any groups and not referenced from any chats or drafts.
    $start = date('Y-m-d', strtotime("midnight 2 days ago"));
    error_log("Purge stranded messages before $start");
    $total = 0;

    do {
        $sql = "SELECT messages.id FROM messages LEFT JOIN messages_groups ON messages_groups.msgid = messages.id LEFT JOIN chat_messages ON chat_messages.refmsgid = messages.id LEFT JOIN messages_drafts ON messages_drafts.msgid = messages.id WHERE messages.arrival <= '$start' AND messages_groups.msgid IS NULL AND chat_messages.refmsgid IS NULL AND messages_drafts.msgid IS NULL LIMIT 1000;";
        $msgs = $dbhr->preQuery($sql);
        foreach ($msgs as $msg) {
            #error_log("...{$msg['id']}");
            $sql = "DELETE FROM messages WHERE id = {$msg['id']};";
            $count = $dbhmold->preExec($sql, NULL, FALSE);
            $total++;

            if ($total % 1000 == 0) {
                error_log("...$total");
            }
        }
    } while (count($msgs) > 0);

    error_log("Deleted $total");
} catch (Exception $e) {
    error_log("Failed with " . $e->getMessage());
    mail(GEEKS_ADDR, "Daily message purge failed", $e->getMessage());
    exit(1);
}

unlockScript($lockh);
<?php

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$tusage = NULL;
$rusage = NULL;

# Get envelope sender and recipient
$envfrom = getenv('SENDER');
$envto = getenv('RECIPIENT');

# Get incoming mail
$msg = '';
while (!feof(STDIN)) {
    $msg .= fread(STDIN, 1024);
}

$log = "/tmp/iznik_incoming.log";
$logh = fopen($log, 'a');

fwrite($logh, "-----\nFrom $envfrom to $envto Message\n$msg\n-----\n");

# Use master to avoid replication delays where we create a message when receiving, but it's not replicated when
# we route it.
$r = new MailRouter($dbhm, $dbhm);

error_log("\n----------------------\n$envfrom => $envto");

$rc = MailRouter::DROPPED;

# Chat reply or email submission.  We don't want to log chat replies - there are a lot and they clutter up
# the logs.
$chat = preg_match('/notify-(.*)-(.*)' . USER_DOMAIN . '/', $envto);

list ($id, $failok) = $r->received(Message::EMAIL, $envfrom, $envto, $msg, NULL, !$chat);

if ($id) {
    $rc = $r->route();
    fwrite($logh, "Route of $envfrom => $envto returned $rc\n");

    # Save archive for shadow testing with new Laravel code
    saveIncomingArchive($dbhr, $envfrom, $envto, $msg, $rc, $id, $r);

    exit(0);
} else if ($failok) {
    fwrite($logh, "Failure ok for $envfrom => $envto returned $rc\n");

    # Save archive even for failok cases
    saveIncomingArchive($dbhr, $envfrom, $envto, $msg, $rc, NULL, $r);
} else {
    fwrite($logh, "Failed to parse message for $envfrom => $envto\n");

    # Save archive for failed parses too
    saveIncomingArchive($dbhr, $envfrom, $envto, $msg, MailRouter::FAILURE, NULL, $r);

    exit(1);
}

/**
 * Save incoming email archive for shadow testing with new Laravel code.
 *
 * Archives are saved to /var/lib/freegle/incoming-archive/ as JSON files.
 * Each file contains the raw email, envelope info, and legacy routing result.
 *
 * To enable archiving, create the directory:
 *   mkdir -p /var/lib/freegle/incoming-archive
 *   chown www-data:www-data /var/lib/freegle/incoming-archive
 *
 * To disable archiving, remove or rename the directory.
 *
 * @param LoggedPDO $dbhr Database handle for reads
 * @param string $envfrom Envelope sender
 * @param string $envto Envelope recipient
 * @param string $rawEmail Raw email content
 * @param string $routingOutcome Routing result (e.g., MailRouter::APPROVED)
 * @param int|null $messageId Created message ID (if any)
 * @param MailRouter $router The MailRouter instance for extracting additional data
 */
function saveIncomingArchive($dbhr, $envfrom, $envto, $rawEmail, $routingOutcome, $messageId, $router) {
    $archiveDir = '/var/lib/freegle/incoming-archive';

    # Only archive if the directory exists (allows easy enable/disable)
    if (!is_dir($archiveDir)) {
        return;
    }

    # Create daily subdirectory for easier management
    $dateDir = $archiveDir . '/' . date('Y-m-d');
    if (!is_dir($dateDir)) {
        @mkdir($dateDir, 0755, TRUE);
    }

    # Get additional context from the routed message
    $userId = NULL;
    $groupId = NULL;
    $spamType = NULL;
    $spamReason = NULL;
    $subject = NULL;
    $fromAddress = NULL;

    if ($messageId) {
        # Query the message to get additional details
        # groupid is in messages_groups, not messages
        $msg = $dbhr->preQuery("SELECT m.fromuser, mg.groupid, m.spamtype, m.spamreason, m.subject, m.fromaddr
                                FROM messages m
                                LEFT JOIN messages_groups mg ON mg.msgid = m.id
                                WHERE m.id = ?", [$messageId]);
        if (count($msg) > 0) {
            $userId = $msg[0]['fromuser'];
            $groupId = $msg[0]['groupid'];
            $spamType = $msg[0]['spamtype'];
            $spamReason = $msg[0]['spamreason'];
            $subject = $msg[0]['subject'];
            $fromAddress = $msg[0]['fromaddr'];
        }
    }

    $archive = [
        'version' => 1,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'envelope' => [
            'from' => $envfrom,
            'to' => $envto,
        ],
        'raw_email' => base64_encode($rawEmail),
        'legacy_result' => [
            'routing_outcome' => $routingOutcome,
            'message_id' => $messageId,
            'user_id' => $userId,
            'group_id' => $groupId,
            'spam_type' => $spamType,
            'spam_reason' => $spamReason,
            'subject' => $subject,
            'from_address' => $fromAddress,
        ],
    ];

    # Generate unique filename with timestamp and random suffix
    $filename = sprintf('%s/%s_%06d.json',
        $dateDir,
        date('His'),
        mt_rand(0, 999999)
    );

    # Write atomically (write to temp, then rename)
    $tempFile = $filename . '.tmp';
    if (@file_put_contents($tempFile, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        @rename($tempFile, $filename);
    }
}


<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/mail/MailRouter.php');
require_once(IZNIK_BASE . '/include/message/Message.php');
require_once(IZNIK_BASE . '/include/message/MessageCollection.php');
require_once(IZNIK_BASE . '/include/user/User.php');

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

$groupname = NULL;

if (stripos($envfrom, "@returns.groups.yahoo.com") !== FALSE && (stripos($envfrom, "sentto-") !== FALSE)) {
    # This is a message sent out to us as a user on the group, so it's an approved message.
    error_log("Approved message to $envto");
    $r->received(Message::YAHOO_APPROVED, NULL, $envto, $msg);
    $rc = $r->route();
} else {
    # Chat reply or email submission.  We don't want to log chat replies - there are a lot and they clutter up
    # the logs.
    $chat = preg_match('/notify-(.*)-(.*)' . USER_DOMAIN . '/', $envto);

    error_log("Email");
    $id = $r->received(Message::EMAIL, $envfrom, $envto, $msg, NULL, !$chat);
    $rc = $r->route();
}

error_log("CPU cost " . getCpuUsage() . " rc $rc");
fwrite($logh, "Route returned $rc\n");
exit(0);
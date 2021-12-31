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

$id = $r->received(Message::EMAIL, $envfrom, $envto, $msg, NULL, !$chat);

if ($id) {
    $rc = $r->route();
    fwrite($logh, "Route of $envfrom => $envto returned $rc\n");
    exit(0);
} else {
    fwrite($logh, "Failed to parse message for $envfrom => $envto\n");
    exit(1);
}


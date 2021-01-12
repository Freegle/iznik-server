<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');

$msgs = $dbhr->preQuery("SELECT messages.id FROM messages_groups inner join messages on messages.id = messages_groups.msgid inner join users on users.id = messages.fromuser where collection = 'Incoming' and users.covidconfirmed is not null and messages.arrival >= '2021-01-08'");

foreach ($msgs as $msg) {
  $m = new Message($dbhr, $dbhm, $msg['id']);
  $r = new MailRouter($dbhr, $dbhm);
  $rc = $r->route($m);
}

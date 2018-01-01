<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Visualise.php');

$v = new Visualise($dbhr, $dbhm);
$v->scanMessages("48 hours ago");
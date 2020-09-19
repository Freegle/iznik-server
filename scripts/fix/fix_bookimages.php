<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/message/Attachment.php');

$ocrs = $dbhr->preQuery("SELECT * FROM booktastic_ocr WHERE processed IS NOT NULL;");

foreach ($ocrs as $ocr) {
    $a = new Attachment($dbhr, $dbhm, NULL, Attachment::TYPE_BOOKTASTIC);
    $a->create($ocr['id'], '', base64_decode($ocr['data']));
}
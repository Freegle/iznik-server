<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/noticeboard/Noticeboard.php');

$noticeboards = $dbhr->preQuery("SELECT * FROM noticeboards WHERE name IS NOT NULL OR description IS NOT NULL;");

foreach ($noticeboards as $noticeboard) {
    $n = new Noticeboard($dbhr, $dbhm, $noticeboard['id']);
    $news = $n->addNews();

    # Preserve the added time.
    $news->setPrivate('added', $noticeboard['added']);
}

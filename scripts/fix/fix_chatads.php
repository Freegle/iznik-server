<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');

$hashes = $dbhr->preQuery("SELECT distinct hash FROM chat_images inner join chat_messages on chat_images.chatmsgid = chat_messages.id WHERE date > '2018-07-07' AND imageid IS NOT NULL");

error_log("<table>");

foreach ($hashes as $hash) {
    $images = $dbhr->preQuery("SELECT imageid FROM chat_images INNER JOIN chat_messages ON chat_images.chatmsgid = chat_messages.id WHERE hash = ? LIMIT 1;", [
        $hash['hash']
    ]);

    foreach ($images as $image) {
        error_log("<tr><td>{$hash['hash']}</td><td><img src='https://www.ilovefreegle.org/tmimg_{$image['imageid']}.jpg'/></td></tr>");
    }
}

error_log("</table>");
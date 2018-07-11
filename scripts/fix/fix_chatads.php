<?php
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');

$start = date('Y-m-d', strtotime("3 days ago"));
$hashes = $dbhr->preQuery("SELECT distinct hash FROM chat_images inner join chat_messages on chat_images.chatmsgid = chat_messages.id WHERE date > '$mysqltime' AND imageid IS NOT NULL ORDER BY date DESC");
$small = [];
error_log("<table>");

foreach ($hashes as $hash) {
    $images = $dbhr->preQuery("SELECT imageid FROM chat_images INNER JOIN chat_messages ON chat_images.chatmsgid = chat_messages.id WHERE hash = ? LIMIT 1;", [
        $hash['hash']
    ]);

    foreach ($images as $image) {
        $a = new Attachment($dbhr, $dbhm, $image['imageid'], Attachment::TYPE_CHAT_MESSAGE);
        $i = new Image($a->getData());
        error_log("<tr><td>" . $i->width() . "</td><td>". $i->height() . "</td><td>{$hash['hash']}</td><td><img src='https://www.ilovefreegle.org/tmimg_{$image['imageid']}.jpg'/></td></tr>");
        $small[] = $hash['hash'];
    }
}

error_log("</table>");

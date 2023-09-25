<?php
# Rescale large images in message_attachments

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

$opts = getopt('i:');

if (count($opts) < 1) {
    echo "Usage: php chat_message_review.php -i <chat message id>\n";
} else {
    $id = Utils::presdef('i', $opts, null);
}

$messages = $dbhr->preQuery("SELECT * FROM chat_messages WHERE id = ?", [
    $id
]);

$m = new ChatMessage($dbhr, $dbhm);

foreach ($messages as $message) {
    $review = $m->checkReview($message['message'], FALSE, $message['userid']);

    error_log("Review? $review");
}
<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get the 100 most popular items.
$popular = $dbhr->preQuery("SELECT itemid, COUNT(*) AS count, items.name AS name FROM `messages_items` INNER JOIN items ON items.id = messages_items.itemid GROUP BY itemid ORDER BY count DESC LIMIT 100;");

# Now scan all messages
$msgs = $dbhr->query("SELECT DISTINCT messages.id, messages.subject, ma.id AS attid FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id INNER JOIN messages_attachments ma on messages.id = ma.msgid WHERE messages.type = 'Offer' ORDER BY messages.id DESC LIMIT 100001, 200000;");

$f = fopen("/tmp/ml_dataset.csv", "w");

mkdir('/tmp/ml');
fputcsv($f, ['Message ID', 'Title', 'Matched popular item', 'Image link']);

foreach ($msgs as $msg) {
    # Only look at well-defined subjects.
    if (preg_match('/.*?\:(.*)\(.*\)/', $msg['subject'], $matches))
    {
        # If we have a well-formed subject line, record the item.
        $item= trim($matches[1]);

        # Check if this is probably a common item.
        foreach ($popular as $p)
        {
            if (preg_match('/\b' . preg_quote($item) . '\b/i', $p['name'])) {
                #error_log("{$item} matches {$p['name']}");
                $data = file_get_contents('https://www.ilovefreegle.org/img_' . $msg['attid'] . '.jpg');

                if ($data)
                {
                    file_put_contents('/tmp/ml/img_' . $msg['attid'] . '.jpg', $data);

                    fputcsv($f, [
                        $msg['id'],
                        $msg['subject'],
                        $p['name'],
                        'img_' . $msg['attid'] . '.jpg'
                    ]);
                }

                break;
            }
        }
    }
}
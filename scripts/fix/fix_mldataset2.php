<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get the 100 most popular items.
error_log("Get popular");
$popular = $dbhr->preQuery("SELECT itemid, COUNT(*) AS count, items.name AS name FROM `messages_items` INNER JOIN items ON items.id = messages_items.itemid GROUP BY itemid ORDER BY count DESC LIMIT 100;");
error_log("Got");

$f = fopen("/tmp/ml_dataset2.csv", "r");
$fo = fopen("/tmp/ml_dataset.csv", "w");

mkdir('/tmp/ml');
fputcsv($f, ['Message ID', 'Title', 'Matched popular item', 'Image link']);

while (($data = fgetcsv($f, 1000, ",")) !== FALSE) {
    list ($id, $subject, $item, $img) = $data;
    # Only look at well-defined subjects.
    if (preg_match('/.*?\:(.*)\(.*\)/', $subject, $matches))
    {
        # Check if this is probably a common item.
        foreach ($popular as $p)
        {
#            error_log("Check $item vs " . '/\b' . preg_quote($item) . '\b/i');
            if (preg_match('/\b' . preg_quote($item) . '\b/i', $p['name']))
            {
 #               error_log("{$item} matches {$p['name']}");
                copy("/tmp/ml2/$img", "/tmp/ml/$img");
                fputcsv($fo, $data);
                break;
            }
        }
    }
}
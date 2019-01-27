<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/Inflector.php');

$categories = [];
$parents = [];
$parentpos = [];
$lastpos = NULL;
$lastid = NULL;
$names = [];
$lines = 0;

if (($handle = fopen("../../install/categories.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) === 7) {
            $id = $data[6];
            $pos = 0;
            while (!$data[$pos]) {
                $pos++;
            }

            $name = $data[$pos];

            $name = Inflector::singularize($name);

            $names[$id] = strtolower($name);

            if ($lastpos === NULL) {
                # First entry.
            }
            else if ($pos > $lastpos) {
                # Moved down the tree.  Add the last id as a parent.
                $parents[] = $lastid;
            } else if ($pos < $lastpos) {
                # Moved up the tree.
                $parents = array_splice($parents, 0, -($pos - $lastpos));
            } else {
                # At same level.
            }

            $categories[$id] = [
                'parents' => $parents,
                'id' => $id,
                'name' => $name
            ];

            $lastpos = $pos;
            $lastid = $id;

            $lines++;

            if ($lines > 30) {
                #break;
            }
        }
    }

    fclose($handle);

//    foreach ($categories as $id => $vals) {
//        echo("$id => {$vals['name']}, parents ");
//
//        foreach (array_reverse($vals['parents']) as $parentid) {
//            echo "$parentid {$names[$parentid]}, ";
//        }
//
//        echo "\n";
//    }

    $items = $dbhr->preQuery("SELECT * FROM items WHERE popularity > 2");
    $missed = $mapped = 0;

    foreach ($items as $item) {
        # Find item.
        $itemname = Inflector::singularize(strtolower($item['name']));

        $found = FALSE;

        foreach ($names as $id => $name) {
            if (strcmp($name, $itemname) === 0) {
                $found = TRUE;

                $vals = $categories[$id];
                echo "{$item['id']} {$item['name']} popularity {$item['popularity']} categorised: ";

                foreach (array_reverse($vals['parents']) as $parentid) {
                    echo "$parentid {$names[$parentid]}, ";
                }

                echo "\n";
                $mapped++;
            }
        }

        if (!$found) {
            echo "Couldn't find item #{$item['id']} {$item['name']} popularity {$item['popularity']}\n";
            $missed++;
        }
    }

    error_log("Mapped $mapped missed $missed");
}
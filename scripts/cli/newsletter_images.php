<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# We have a list of UK postcodes in the DB, among other locations.  UK postcodes change fairly frequently.
#
# 1. Go to https://www.ordnancesurvey.co.uk/opendatadownload/products.html
# 2. Download Code-Point Open, in ZIP form
# 3. Unzip it somewhere
# 4. Run this script to process it.
#
# It will add any new postcodes to the DB.
# TODO Removal?

$opts = getopt('d:');

if (count($opts) != 1) {
    echo "Usage: php newsletter_images -d <folder with images>)\n";
} else {
    $fold = Utils::presdef('d', $opts, NULL);

    if ($fold) {
        foreach (glob("$fold/*.*") as $file) {
            $data = file_get_contents($file);

            $a = new Attachment($dbhr, $dbhm, NULL, Attachment::TYPE_NEWSLETTER);
            $attid = $a->create(NULL, 'image/jpeg', $data);
            error_log("$file => $attid");
        }
    }
}

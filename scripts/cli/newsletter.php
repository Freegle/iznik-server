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

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "ilovefreegle.org";

$opts = getopt('e:i:');

if (count($opts) == 0) {
    echo "Usage: php newsletter <-e <email>> -i <newsletter id>)\n";
} else {
    $email = Utils::presdef('e', $opts, NULL);
    $id = Utils::presdef('i', $opts, NULL);

    $n = new Newsletter($dbhr, $dbhm, $id);

    if ($n->getId() == $id) {
        if ($email) {
            $u = User::get($dbhr, $dbhm);
            $eid = $u->findByEmail($email);
            error_log("Send to user $eid");

            if ($eid) {
                $n->send(NULL, $eid);
            }
        } else {
            $n->send(NULL, NULL);
        }
    }
}

<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

# Parse command line options
$opts = getopt('e:g:h');

if (isset($opts['h'])) {
    echo "Usage: php birthday.php [-e <email>] [-g <groupids>] [-h]\n";
    echo "  -e <email>    Send test email to specified address (stops after one email)\n";
    echo "  -g <groupids> Only send for specific group IDs (comma-separated)\n";
    echo "  -h            Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php birthday.php                      # Send birthday emails to all eligible users\n";
    echo "  php birthday.php -e test@example.com  # Send one test email to specified address\n";
    echo "  php birthday.php -g 123,456           # Send only for groups 123 and 456\n";
    echo "  php birthday.php -g 123 -e test@example.com # Test email for group 123 only\n";
    exit(0);
}

$d = new Donations($dbhr, $dbhm);

# Check for email override parameter
$emailOverride = isset($opts['e']) ? $opts['e'] : null;

# Check for group ID filter parameter
$groupids = null;
if (isset($opts['g'])) {
    $groupids = explode(',', $opts['g']);
    $groupids = array_map('intval', $groupids); // Convert to integers
    error_log("Filtering to groups: " . implode(', ', $groupids));
}

if ($emailOverride) {
    error_log("Using email override: $emailOverride");
}

# Send birthday emails using the Donations class method
$count = $d->sendBirthdayEmails($emailOverride, $groupids);

error_log("Birthday email process completed - sent $count emails");
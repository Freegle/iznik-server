<?php
namespace Freegle\Iznik;

define( 'BASE_DIR', dirname(__FILE__) . '/..' );

require_once('/etc/iznik.conf');
require_once(BASE_DIR . '/include/config.php');
require_once(BASE_DIR . '/include/db.php');

echo "User-agent: *\n";

echo "\nSITEMAP: http://" . USER_SITE . '/sitemap.xml';

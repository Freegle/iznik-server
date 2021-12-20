<?php
# This creates a table in Postgres with the geometries from the locations table.  This is so that we can
# use Postgres' KNN function.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$l = new Location($dbhr, $dbhm);
$count = $l->copyLocationsToPostgresql();
mail("log@ehibbert.org.uk", "$count locations copied to Postgresql", "", [], '-f' . NOREPLY_ADDR);

<?php
# This creates a table in Postgres with the geometries from the locations table.  This is so that we can
# explore Postgres' KNN function.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$pgsql = new \PDO("pgsql:host=localhost;dbname=postgres", "iznik", "iznik");
$pgsql->exec("DROP TABLE IF EXISTS locations_tmp;");
$pgsql->exec("DROP INDEX IF EXISTS idx_location;");
$pgsql->exec("CREATE TABLE locations_tmp (id serial, locationid bigint, name text, location postgis.geometry);");
$pgsql->exec("CREATE INDEX idx_location ON locations_tmp USING gist(location, postgis.ST_Area(location));");

$locations = $dbhr->preQuery("SELECT id, name, ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom FROM locations;");
$std = $pgsql->prepare("INSERT INTO locations_tmp (locationid, name, location) VALUES (?, ?, postgis.ST_GeomFromText(?, {$dbhr->SRID()}));");

$count = 0;
foreach ($locations as $location) {
    $std->execute([ $location['id'], $location['name'], $location['geom']]);

    $id = $pgsql->lastInsertId();

    $count++;

    if ($count % 1000 == 0) {
        error_log("...added $count ID $id");
    }
}




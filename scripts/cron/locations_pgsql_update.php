<?php
# This creates a table in Postgres with the geometries from the locations table.  This is so that we can
# explore Postgres' KNN function.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Assumes some Postgres setup.
# - user iznik
# - grant all privileges on locations_tmp to iznik;
# - installed btree_gist and postgis extensions

$pgsql = new \PDO("pgsql:host=localhost;dbname=postgres", "iznik", "iznik");
$pgsql->exec("DROP TABLE IF EXISTS locations_tmp;");
$pgsql->exec("DROP INDEX IF EXISTS idx_location;");
$pgsql->exec("CREATE TYPE location_type AS ENUM('Road','Polygon','Line','Point','Postcode');");
$pgsql->exec("CREATE TABLE locations_tmp (id serial, locationid bigint, name text, type location_type, area numeric, location postgis.geometry);");
$pgsql->exec("ALTER TABLE locations_tmp SET UNLOGGED");

error_log("Get locations");
$dbhr->doConnect();

# Get non-excluded polygons.
$locations = $dbhr->_db->query("SELECT locations.id, name, type, 
       ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom
FROM locations LEFT JOIN locations_excluded le on locations.id = le.locationid 
WHERE le.locationid IS NULL HAVING ST_Dimension(geom) = 2;");
error_log("Got first chunk");
$std = $pgsql->prepare("INSERT INTO locations_tmp (locationid, name, type, location) VALUES (?, ?, ?, ST_Area(postgis.ST_GeomFromText(?, {$dbhr->SRID()})), postgis.ST_GeomFromText(?, {$dbhr->SRID()}));");

$count = 0;
foreach ($locations as $location) {
    $std->execute([ $location['id'], $location['name'], $location['type'], $location['geom'], $location['geom']]);

    $id = $pgsql->lastInsertId();

    $count++;

    if ($count % 1000 == 0) {
        error_log("...added $count ID $id");
    }
}

$pgsql->exec("CREATE INDEX idx_location ON locations_tmp USING gist(location);");
$pgsql->exec("ALTER TABLE locations_tmp SET LOGGED");

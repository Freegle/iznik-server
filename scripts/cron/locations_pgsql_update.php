<?php
# This creates a table in Postgres with the geometries from the locations table.  This is so that we can
# use Postgres' KNN function.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Assumes some Postgres setup.
# - user iznik
# - grant all privileges on locations_tmp to iznik;
# - installed btree_gist and postgis extensions

$pgsql = new LoggedPDO(PGSQLHOST, PGSQLDB, PGSQLUSER, PGSQLPASSWORD, FALSE, NULL, 'pgsql');
$pgsql->preExec("DROP TABLE IF EXISTS locations_tmp;");
$pgsql->preExec("DROP INDEX IF EXISTS idx_location;");
$pgsql->preExec("DROP TYPE IF EXISTS location_type;");
$pgsql->preExec("CREATE TYPE location_type AS ENUM('Road','Polygon','Line','Point','Postcode');");
$pgsql->preExec("CREATE TABLE locations_tmp (id serial, locationid bigint, name text, type location_type, area numeric, location geometry);");
$pgsql->preExec("ALTER TABLE locations_tmp SET UNLOGGED");

error_log("Get locations");

# Get the locations.  Go direct to PDO as we want an unbuffered query to reduce memory usage.
$dbhr->doConnect();

# Get non-excluded polygons.
$locations = $dbhr->_db->query("SELECT locations.id, name, type, 
       ST_AsText(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) AS geom
FROM locations LEFT JOIN locations_excluded le on locations.id = le.locationid 
WHERE le.locationid IS NULL AND ST_Dimension(CASE WHEN ourgeometry IS NOT NULL THEN ourgeometry ELSE geometry END) = 2;");
error_log("Got first chunk " . var_export($dbhr->errorInfo(), TRUE));

$count = 0;
foreach ($locations as $location) {
    $pgsql->preExec("INSERT INTO locations_tmp (locationid, name, type, area, location) VALUES (?, ?, ?, ST_Area(ST_GeomFromText(?, {$dbhr->SRID()})), ST_GeomFromText(?, {$dbhr->SRID()}));", [
        $location['id'], $location['name'], $location['type'], $location['geom'], $location['geom']
    ]);

    $count++;

    if ($count % 1000 == 0) {
        error_log("...added $count");
    }
}

$pgsql->preExec("CREATE INDEX idx_location ON locations_tmp USING gist(location);");
$pgsql->preExec("ALTER TABLE locations_tmp SET LOGGED");

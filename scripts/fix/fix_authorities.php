<?php
define('SQLLOG', FALSE);

# Some authority data is MULTIPOLYGON.  This causes problems with some of the MySQL geometry functions - probably
# bugs.  So find such multipolygons and use the largest of its multipolygons instead.
require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$i = 0;

$auths = $dbhr->preQuery("SELECT id, AsText(polygon) AS polytext FROM authorities WHERE LOCATE('MULTIPOLYGON', AsText(polygon)) > 0;");

foreach ($auths as $auth) {
    $i++;

    if ($i % 100 == 0) {
        error_log($i);
    }

    $maxarea = 0;
    $poly = NULL;

    if (strpos($auth['polytext'], 'MULTIPOLYGON') !== FALSE) {
        error_log("Auth {$auth['id']}");
        $p = geoPHP::load($auth['polytext'], 'wkt');
        $comps = $p->getComponents();

        foreach ($comps as $comp) {
            if ($comp->area() > $maxarea) {
                $maxarea = $comp->area();
                $poly = $comp;
            }
        }

        if ($poly) {
            try {
                $dbhm->preExec("UPDATE authorities SET polygon = GeomFromText(?), simplified = ST_Simplify(GeomFromText(?), 0.001) WHERE id = ?;", [
                    $poly->asText(),
                    $poly->asText(),
                    $auth['id']
                ]);
            } catch (Exception $e) {
                error_log("Failed " . $e->getMessage() . " {$auth['id']}");
            }
        }
    }
}
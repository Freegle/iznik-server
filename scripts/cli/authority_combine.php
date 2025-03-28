<?php

// Usage: php authority_combine.php -i <id>,<id>,etc
// php authority_combine.php -i 72986,73106 2>combined.txt

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/lib/geoPHP/geoPHP.inc');

$opts = getopt('i:');

$ids = explode(',', $opts['i']);

$points = [];
$union = NULL;

$polys = [];

foreach ($ids as $id) {
    $l = new Authority($dbhr, $dbhm, $id);
    $atts = $l->getPublic();
    $geom = \geoPHP::load($atts['polygon'], 'wkt');

    $union = $union ? $union->union($geom) : $geom;
    if( !is_null($geom)){
      $polys[] = $geom;
      //$points = array_merge($points, $geom->getPoints());
    }
}

$mp = new \MultiPolygon($polys);

error_log($mp->asText());

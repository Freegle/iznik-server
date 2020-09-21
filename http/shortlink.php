<?php
namespace Freegle\Iznik;

define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$name = Utils::presdef('name', $_REQUEST, NULL);
$url = "https://" . USER_SITE;

if ($name) {
    $s = new Shortlink($dbhr, $dbhm);
    list ($id, $redirect) = $s->resolve($name);

    if ($id) {
        $url = $redirect;
    }
}

header("Location: $url");

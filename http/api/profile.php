<?php
namespace Freegle\Iznik;

function profile() {
    global $dbhr, $dbhm;

    $id = (Utils::presint('id', $_REQUEST, 0));
    $hash = Utils::presdef('hash', $_REQUEST, NULL);
    $def = Utils::presdef('d', $_REQUEST, 'https://' . IMAGE_DOMAIN . '/defaultprofile.png');
    $ut = array_key_exists('ut', $_REQUEST) ? filter_var($_REQUEST['ut'], FILTER_VALIDATE_BOOLEAN) : FALSE;

    $u = new User($dbhr, $dbhm);

    $id = $id ? $id : $u->findByEmailHash($hash);

    $ret = [ 'ret' => 1, 'status' => 'Unknown hash' ];
    $url = $def;

    if ($id) {
        $u = new User($dbhr, $dbhm, $id);
        $atts = $u->getPublic(NULL, FALSE, FALSE, FALSE, FALSE, FALSE, FALSE);

        $url = $atts['profile']['default'] ? $def : $atts['profile']['url'];
    }

    # Normally we just redirect to the location of the profile picture, but for UT we need to return it.
    if (!$ut) {
        // @codeCoverageIgnoreStart
        header('Location: ' . $url);
        exit(0);
        // @codeCoverageIgnoreEnd
    } else {
        $ret = [
            'ret' => 0,
            'status' => 'Success',
            'url' => $url
        ];
    }

    return($ret);
}

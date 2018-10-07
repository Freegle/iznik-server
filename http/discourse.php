<?php

define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/session/Session.php');

global $dbhr, $dbhm;

require_once(IZNIK_BASE . '/composer/vendor/cviebrock/discourse-php/src/Exception/PayloadException.php');
require_once(IZNIK_BASE . '/composer/vendor/cviebrock/discourse-php/src/SSOHelper.php');

$sso = new Cviebrock\DiscoursePHP\SSOHelper();
$sso->setSecret(DISCOURSE_SECRET);

$payload = $_GET['sso'];
$signature = $_GET['sig'];

if (($sso->validatePayload($payload,$signature))) {
    $nonce = $sso->getNonce($payload);

    $me = whoAmI($dbhr, $dbhm);

    if ($me) {
        $ctx = NULL;
        $atts = $me->getPublic(NULL, FALSE, FALSE, $ctx, FALSE, FALSE, FALSE, FALSE, FALSE, NULL, FALSE);
        $extraParameters = array(
            'username' => str_replace($me->getName(), ' ', ''),
            'name'     => $me->getName(),
            'avatar_url' => $atts['profile']['url'],
            'admin' => $me->isAdmin()
        );

        error_log("Discourse signin " . var_export($extraParameters, TRUE));

        $query = $sso->getSignInString($nonce, $me->getId(), $me->getEmailPreferred(), $extraParameters);
        header('Location: http://discourse.ilovefreegle.org/session/sso_login?' . $query);
        exit(0);
    }
}

header('Location: http://' . MOD_SITE);
die();

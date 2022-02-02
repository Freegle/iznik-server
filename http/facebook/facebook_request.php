<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Facebook\FacebookSession;
use Facebook\FacebookJavaScriptLoginHelper;
use Facebook\FacebookCanvasLoginHelper;
use Facebook\FacebookRequest;
use Facebook\FacebookRequestException;

error_log("Request Facebook auth");
$groupid = intval(Utils::presdef('groupid', $_REQUEST, 0));

$fb = new \Facebook\Facebook([
    'app_id' => FBGRAFFITIAPP_ID,
    'app_secret' => FBGRAFFITIAPP_SECRET
]);

$helper = $fb->getRedirectLoginHelper();

$permissions = [
    'manage_pages',
    'publish_pages'
];

$url = $helper->getLoginUrl('https://' . $_SERVER['HTTP_HOST'] . '/facebook/facebook_response.php?groupid=' . $groupid, $permissions);
error_log("Redirect to $url");
header('Location: ' . $url);
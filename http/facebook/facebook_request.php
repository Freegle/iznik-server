<?php
namespace Freegle\Iznik;

if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use JanuSoftware\Facebook\FacebookSession;
use JanuSoftware\Facebook\FacebookJavaScriptLoginHelper;
use JanuSoftware\Facebook\FacebookCanvasLoginHelper;
use JanuSoftware\Facebook\FacebookRequest;
use JanuSoftware\Facebook\FacebookRequestException;

error_log("Request Facebook auth");
$groupid = intval(Utils::presdef('groupid', $_REQUEST, 0));

$fb = new \JanuSoftware\Facebook\Facebook([
    'app_id' => FBGRAFFITIAPP_ID,
    'app_secret' => FBGRAFFITIAPP_SECRET,
    'default_graph_version' =>  'v13.0'
]);

$helper = $fb->getRedirectLoginHelper();

$permissions = [
    // Can't ask for manage posts while Facebook doesn't recognise us as a business.
//    'pages_manage_posts',
    'pages_read_engagement',
    'pages_show_list',
];

$url = $helper->getLoginUrl('https://' . $_SERVER['HTTP_HOST'] . '/facebook/facebook_response.php?groupid=' . $groupid, $permissions);
#error_log("Redirect to $url");
#echo($url);
header('Location: ' . $url);
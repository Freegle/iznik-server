<?php


require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');


use JanuSoftware\Facebook\FacebookSession;
use JanuSoftware\Facebook\FacebookJavaScriptLoginHelper;
use JanuSoftware\Facebook\FacebookCanvasLoginHelper;
use JanuSoftware\Facebook\FacebookRequest;
use JanuSoftware\Facebook\FacebookRequestException;

$id = 73384391;
$uid = 6945;

$_SESSION['id'] = 35909200;
$f = new GroupFacebook($dbhr, $dbhm, $uid);
$f->performSocialAction($id);

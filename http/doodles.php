<?php

define( 'BASE_DIR', dirname(__FILE__) . '/..' );
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;
?>
<!DOCTYPE HTML>
<html>
<head>
    <title>Freegle Doodles</title>
    <link rel="stylesheet" href="/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/bootstrap-theme.min.css">
    <link rel="stylesheet" href="/css/glyphicons.css">
    <link rel="stylesheet" href="/css/glyphicons-social.css">
    <link rel="stylesheet" href="/css/bootstrap-select.min.css">
    <link rel="stylesheet" href="/css/bootstrap-switch.min.css">
    <link rel="stylesheet" href="/css/bootstrap-dropmenu.min.css">
    <link rel="stylesheet" href="/css/bootstrap-notifications.min.css">
    <link rel="stylesheet" href="/css/datepicker3.css">
    <link rel="stylesheet" href="/js/lib/bootstrap-datetimepicker/css/bootstrap-datetimepicker.css">
    <link rel="stylesheet" href="/css/dd.css">
    <link rel="stylesheet" href="/css/fileinput.css" />

    <link rel="stylesheet" type="text/css" href="/css/style.css?a=199">
    <!--[if lt IE 9]>
    <link rel="stylesheet" type="text/css" href="/css/ie-only.css">
    <![endif]-->
    <link rel="stylesheet" type="text/css" href="/css/user.css?a=154">
    <!--[if lt IE 9]>
    <script src="/js/lib/html5shiv.js"></script>
    <script src="/js/lib/respond.min.js"></script>
    <![endif]-->

    <meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
    <meta name="HandheldFriendly" content="true">

    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript" src="/js/lib/jquery.js"></script>
    <script type="text/javascript" src="js/lib/require.js"></script>
    <script type="text/javascript" src="js/requirejs-setup.js"></script>
</head>
<body style="margin-top: 0px">
<div id="bodyEnvelope">
    <div id="bodyContent" class="nopad">
        <h1>Freegle Doodles</h1>
        <?php

        $logos = $dbhr->preQuery("SELECT * FROM logos ORDER BY date ASC;");

        foreach ($logos as $logo) {
            echo '<div class="row"><div class="col-xs-2">' . $logo['date'] . '</div><div class="col-xs-4">' . $logo['path'] . '<img class="img-morerounded img-responsive" style="width: 60px" src="' . $logo['path'] . '" /></div></div>';
        }
        ?>
    </div>
</div>
</body>

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
</head>
<body style="margin-top: 0px">
<div id="bodyEnvelope">
    <div id="bodyContent" class="nopad">
        <h1>Freegle Doodles</h1>
        <table>
            <tr>
                <th>Date</th>
                <th>Filename</th>
                <th>Image</th>
            </tr>
        <?php

        $logos = $dbhr->preQuery("SELECT * FROM logos ORDER BY date ASC;");

        foreach ($logos as $logo) {
            echo '<tr><td>' . $logo['date'] . '</td><td>' . $logo['path'] . '</td><td><img style="border-radius: 12px; width: 60px" src="' . $logo['path'] . '" /></td></td></tr>';
        }
        ?>
        </table>
    </div>
</div>
</body>

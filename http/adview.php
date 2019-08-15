<?php

$url = "https://adview.online/api/v1/jobs.json?publisher=2053&location=" . $_REQUEST['location'];

$data = file_get_contents($url);

echo $data;
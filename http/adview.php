<?php
header('Content-Type: application/json');

$url = "https://adview.online/api/v1/jobs.json?publisher=2053&limit=50&location=" . urlencode($_REQUEST['location']);

$data = file_get_contents($url);

echo $data;
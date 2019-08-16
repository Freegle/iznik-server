<?php
header('Content-Type: application/json');

$ip = presdef('REMOTE_ADDR', $_SERVER, NULL);
$url = "https://adview.online/api/v1/jobs.json?publisher=2053&limit=50&user_ip=$ip&location=" . urlencode($_REQUEST['location']);

$data = file_get_contents($url);

echo $data;
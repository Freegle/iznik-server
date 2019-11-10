<?php
header('Content-Type: application/json');

$ip = array_key_exists('REMOTE_ADDR', $_SERVER) ? $_SERVER['REMOTE_ADDR'] :  NULL;
$url = "https://adview.online/api/v1/jobs.json?publisher=2053&limit=50&radius=5&user_ip=$ip&location=" . urlencode($_REQUEST['location']);

$data = file_get_contents($url);

if ($data) {
    $d = json_decode($data, TRUE);
    if (array_key_exists('data', $d)) {
        shuffle($d['data']);
    }

    $data = json_encode($d);
}

echo $data;
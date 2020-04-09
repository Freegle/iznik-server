<?php

function catalogue() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $photo = presdef('photo', $_REQUEST, NULL);

    if ($photo) {
        $data = substr($photo, strpos($photo, ',') + 1);

        $c = new Catalogue($dbhr, $dbhm);
        list ($id, $text) = $c->ocr($data);

        $ret = [
            'ret' => 0,
            'status' => 'Success',
            'text' => $text,
            'id' => $id
        ];
    }

    return($ret);
}

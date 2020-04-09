<?php

function catalogue() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $photo = presdef('photo', $_REQUEST, NULL);

    if ($photo) {
        $data = substr($photo, strpos($photo, ',') + 1);

        $c = new Catalogue($dbhr, $dbhm);
        list ($id, $text) = $c->ocr($data);
        $authors = $c->extractPossibleAuthors($id);

        $ret = [
            'ret' => 0,
            'status' => 'Success',
            'text' => $text,
            'authors' => $authors,
            'id' => $id
        ];
    }

    return($ret);
}

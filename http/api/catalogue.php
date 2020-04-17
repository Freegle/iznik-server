<?php

function catalogue() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    $photo = presdef('photo', $_REQUEST, NULL);

    if ($photo) {
        $data = substr($photo, strpos($photo, ',') + 1);

        $c = new Catalogue($dbhr, $dbhm);
        list ($id, $text) = $c->ocr($data);
        list ($spines, $fragments) = $c->identifySpinesFromOCR($id);
        $c->searchForSpines($id, $spines, $fragments);
        $c->searchForBrokenSpines($id, $spines, $fragments);

        $ret = [
            'ret' => 0,
            'status' => 'Success',
            'text' => $text,
            'fragments' => $fragments,
            'id' => $id,
            'spines' => $spines
        ];
    }

    return($ret);
}

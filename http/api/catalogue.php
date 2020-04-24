<?php

function catalogue() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'POST':
        {
            $c = new Catalogue($dbhr, $dbhm);

            $action = presdef('action', $_REQUEST, NULL);
            $id = intval(presdef('id', $_REQUEST, 0));

            if ($action) {
                switch ($action) {
                    case 'Rate': {
                        $rating = intval(presdef('rating', $_REQUEST, 0));
                        $c->rate($id, $rating);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                            ];
                        break;
                    }
                }
            } else {
                $photo = presdef('photo', $_REQUEST, NULL);

                if ($photo) {
                    $data = substr($photo, strpos($photo, ',') + 1);

                    list ($id, $text) = $c->ocr($data);

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'text' => $text,
                        'id' => $id
                    ];
                }
            }
            break;
        }
        case 'GET': {
            $c = new Catalogue($dbhr, $dbhm);
            $id = intval(presdef('id', $_REQUEST, 0));

            list ($spines, $fragments) = $c->getResult($id);
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'id' => $id,
                'fragments' => $fragments,
                'spines' => $spines
            ];

            break;
        }
    }

    return($ret);
}

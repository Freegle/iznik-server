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

            if ($id) {
                list ($spines, $fragments) = $c->getResult($id);
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'id' => $id,
                    'fragments' => $fragments,
                    'spines' => $spines
                ];
            } else {
                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'results' => []
                ];

                $ocrs = $dbhr->preQuery("SELECT id FROM booktastic_ocr WHERE processed IS NOT NULL ORDER BY id desc LIMIT 30;");
                foreach ($ocrs as $ocr) {
                    $img = "https://" . IMAGE_DOMAIN . '/zimg_' . $ocr['id'] . '.jpg';
                    $thisone = [ 'img' => $img, 'books' => [], 'fragments' => [] ];

                    $results = $dbhr->preQuery("SELECT * FROM booktastic_results WHERE ocrid = ?;", [
                        $ocr['id']
                    ], FALSE, FALSE);

                    foreach ($results as $result) {
                        $spines = json_decode($result['spines'], TRUE);
                        $fragments = json_decode($result['fragments'], TRUE);

                        if ($spines && $fragments) {
                            $thisone['fragments'] = $fragments;

                            foreach ($spines as $spine) {
                                if (pres('author', $spine)) {
                                    $thisone['books'][] = $spine;
                                }
                            }
                        }
                    }

                    $ret['results'][] = $thisone;
                }
            }

            break;
        }
    }

    return($ret);
}

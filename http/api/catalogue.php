<?php
namespace Freegle\Iznik;

// @codeCoverageIgnoreStart
// This is a proof of concept for another project, it isn't tested as part of Freegle.

function catalogue() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $c = new Catalogue($dbhr, $dbhm);
            $id = (Utils::presint('id', $_REQUEST, 0));

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

                $ocrs = $dbhr->preQuery("SELECT booktastic_images.id, spines, fragments FROM booktastic_ocr INNER JOIN booktastic_results ON booktastic_results.ocrid = booktastic_ocr.id INNER JOIN booktastic_images ON booktastic_images.ocrid = booktastic_ocr.id WHERE processed IS NOT NULL ORDER BY booktastic_ocr.id desc LIMIT 30;");
                foreach ($ocrs as $ocr) {
                    $img = "https://" . IMAGE_DOMAIN . '/zimg_' . $ocr['id'] . '.jpg';
                    $timg = "https://" . IMAGE_DOMAIN . '/tzimg_' . $ocr['id'] . '.jpg';
                    $thisone = [ 'img' => $img, 'timg' => $timg, 'books' => [], 'fragments' => [] ];

                    $spines = json_decode($ocr['spines'], TRUE);
                    $fragments = json_decode($ocr['fragments'], TRUE);

                    if ($spines && $fragments) {
                        $thisone['fragments'] = $fragments;

                        foreach ($spines as $spine) {
                            if (Utils::pres('author', $spine)) {
                                $thisone['books'][] = $spine;
                            }
                        }
                    }

                    $ret['results'][] = $thisone;
                }
            }

            break;
        }

        case 'POST':
        {
            $c = new Catalogue($dbhr, $dbhm);

            $action = Utils::presdef('action', $_REQUEST, NULL);
            $id = (Utils::presint('id', $_REQUEST, 0));

            if ($action) {
                switch ($action) {
                    case 'Rate': {
                        $rating = (Utils::presint('rating', $_REQUEST, 0));
                        $c->rate($id, $rating);

                        $ret = [
                            'ret' => 0,
                            'status' => 'Success'
                        ];
                        break;
                    }
                }
            } else {
                $photo = Utils::presdef('photo', $_REQUEST, NULL);

                if ($photo) {
                    # Get base64 encoded data
                    $data = substr($photo, strpos($photo, ',') + 1);
                    list ($id, $text) = $c->ocr($data);

                    if ($id) {
                        # Create an image with the binary data.
                        $a = new Attachment($dbhr, $dbhm, NULL, Attachment::TYPE_BOOKTASTIC);
                        $imgid = $a->create($id, $photo['type'], base64_decode($data));
                    }

                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'text' => $text,
                        'id' => $id,
                        'imgid' => $imgid
                    ];
                }
            }
            break;
        }
    }

    return($ret);
}

// @codeCoverageIgnoreEnd

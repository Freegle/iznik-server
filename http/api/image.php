<?php
namespace Freegle\Iznik;

function image() {
    global $dbhr, $dbhm;

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];
    $id = (Utils::presint('id', $_REQUEST, 0));
    $msgid = Utils::presint('msgid', $_REQUEST, NULL);
    $identify = array_key_exists('identify', $_REQUEST) ? filter_var($_REQUEST['identify'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $ocr = array_key_exists('ocr', $_REQUEST) ? filter_var($_REQUEST['ocr'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $objects = array_key_exists('objects', $_REQUEST) ? filter_var($_REQUEST['objects'], FILTER_VALIDATE_BOOLEAN) : FALSE;
    $group = Utils::presdef('group', $_REQUEST, NULL);
    $newsletter = Utils::presdef('newsletter', $_REQUEST, NULL);
    $communityevent = Utils::presdef('communityevent', $_REQUEST, NULL);
    $volunteering = Utils::presdef('volunteering', $_REQUEST, NULL);
    $chatmessage = Utils::presdef('chatmessage', $_REQUEST, NULL);
    $user = Utils::presdef('user', $_REQUEST, NULL);
    $newsfeed = Utils::presdef('newsfeed', $_REQUEST, NULL);
    $story = Utils::presdef('story', $_REQUEST, NULL);
    $circle = Utils::presdef('circle', $_REQUEST, NULL);
    $booktastic = Utils::presdef('booktastic', $_REQUEST, NULL);

    $sizelimit = 800;
    
    if ($chatmessage) {
        $type = Attachment::TYPE_CHAT_MESSAGE;
    } else if ($communityevent) {
        $type = Attachment::TYPE_COMMUNITY_EVENT;
    } else if ($volunteering) {
        $type = Attachment::TYPE_VOLUNTEERING;
    } else if ($newsletter) {
        $type = Attachment::TYPE_NEWSLETTER;
    } else if ($group) {
        $type = Attachment::TYPE_GROUP;
    } else if ($user) {
        $type = Attachment::TYPE_USER;
    } else if ($newsfeed) {
        $type = Attachment::TYPE_NEWSFEED;
    } else if ($story) {
        $type = Attachment::TYPE_STORY;
    } else if ($booktastic) {
        $type = Attachment::TYPE_BOOKTASTIC;
    } else {
        $type = Attachment::TYPE_MESSAGE;
    }

    $_REQUEST['type'] = Utils::pres('typeoverride', $_REQUEST) ? $_REQUEST['typeoverride'] : $_REQUEST['type'];

    switch ($_REQUEST['type']) {
        case 'GET': {
            $a = new Attachment($dbhr, $dbhm, $id, $type);
            $data = $a->getData();

            $i = new Image($data);

            $ret = [
                'ret' => 1,
                'status' => "Failed to create image $id of type $type",
                'req'=> $_REQUEST
            ];


            if ($i->img) {
                $w = (Utils::presint('w', $_REQUEST, $i->width()));
                $h = (Utils::presint('h', $_REQUEST, $i->height()));

                if (($w > 0) || ($h > 0)) {
                    # Need to resize
                    $i->scale($w, $h);
                }

                if ($circle) {
                    $i->circle($w);
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'img' => $i->getDataPNG()
                    ];
                } else {
                    $ret = [
                        'ret' => 0,
                        'status' => 'Success',
                        'img' => $i->getData()
                    ];
                }
            }

            break;
        }

        case 'POST': {
            $ret = [ 'ret' => 1, 'status' => 'No photo provided' ];

            # This next line is to simplify UT.
            $rotate = Utils::presint('rotate', $_REQUEST, NULL);

            if ($rotate) {
                # We want to rotate.  Do so.
                $a = new Attachment($dbhr, $dbhm, $id, $type);
                $data = $a->getData();
                $i = new Image($data);
                $i->rotate($rotate);
                $newdata = $i->getData(100);
                $a->setData($newdata);

                if ($type == Attachment::TYPE_MESSAGE) {
                    # Only some kinds of attachments record whether they are rotated.
                    $a->recordRotate();
                }

                $ret = [
                    'ret' => 0,
                    'status' => 'Success',
                    'rotatedsize' => strlen($newdata)
                ];
            } else {
                $photo = Utils::presdef('photo', $_FILES, NULL) ? $_FILES['photo'] : $_REQUEST['photo'];
                $imgtype = Utils::presdef('imgtype', $_REQUEST, Attachment::TYPE_MESSAGE);
                $mimetype = Utils::presdef('type', $photo, NULL);

                # Make sure what we have looks plausible - the file upload plugin should ensure this is the case.
                if ($photo &&
                    Utils::pres('tmp_name', $photo) &&
                    strpos($mimetype, 'image/') === 0) {

                    try {
                        # We may need to rotate.
                        $data = file_get_contents($photo['tmp_name']);
                        $image = imagecreatefromstring($data);
                        $exif = @exif_read_data($photo['tmp_name']);

                        if($exif && !empty($exif['Orientation'])) {
                            switch($exif['Orientation']) {
                                case 2:
                                    imageflip($image , IMG_FLIP_HORIZONTAL);
                                    break;
                                case 3:
                                    $image = imagerotate($image,180,0);
                                    break;
                                case 4:
                                    $image = imagerotate($image,180,0);
                                    imageflip($image , IMG_FLIP_HORIZONTAL);
                                    break;
                                case 5:
                                    $image = imagerotate($image,90,0);
                                    imageflip ($image , IMG_FLIP_VERTICAL);
                                    break;
                                case 6:
                                    $image = imagerotate($image,-90,0);
                                    break;
                                case 7:
                                    $image = imagerotate($image,-90,0);
                                    imageflip ($image , IMG_FLIP_VERTICAL);
                                    break;
                                case 8:
                                    $image = imagerotate($image,90,0);
                                    break;
                            }

                            ob_start();
                            imagejpeg($image, NULL, 100);
                            $data = ob_get_contents();
                            ob_end_clean();
                        }

                        if ($data) {
                            $a = new Attachment($dbhr, $dbhm, NULL, $imgtype);
                            $id = $a->create($msgid, $photo['type'], $data);

                            # Make sure it's not too large, to keep DB size down.  Ought to have been resized by
                            # client, but you never know.
                            $data = $a->getData();
                            $i = new Image($data);
                            $h = $i->height();
                            $w = $i->width();

                            if ($w > $sizelimit) {
                                $h = $h * $sizelimit / $w;
                                $w = $sizelimit;
                                $i->scale($w, $h);
                                $data = $i->getData(100);
                                $a->setPrivate('data', $data);
                            }

                            $ret = [
                                'ret' => 0,
                                'status' => 'Success',
                                'id' => $id,
                                'path' => $a->getPath(FALSE),
                                'paththumb' => $a->getPath(TRUE)
                            ];

                            # Return a new thumbnail (which might be a different orientation).
                            $ret['initialPreview'] =  [
                                '<img src="' . $a->getPath(TRUE) . '" class="file-preview-image img-responsive">',
                            ];

                            $ret['initialPreviewConfig'] = [
                                [
                                    'key' => $id
                                ]
                            ];

                            $ret['append'] = TRUE;

                            if ($identify) {
                                $a = new Attachment($dbhr, $dbhm, $id);
                                $ret['items'] = $a->identify();
                            }

                            if ($ocr) {
                                $a = new Attachment($dbhr, $dbhm, $id, $type);
                                $ret['ocr'] = $a->ocr();
                            }

                            if ($objects) {
                                $a = new Attachment($dbhr, $dbhm, $id, $type);
                                $ret['objects'] = $a->objects();
                            }
                        }
                    } catch (\Exception $e) {
                        $ret = [ 'ret' => 5, 'status' => "Image create failed " . $e->getMessage() ];
                    }
                }

                # Uploader code requires this field.
                $ret['error'] = $ret['ret'] == 0 ? NULL : $ret['status'];
            }

            break;
        }

        case 'DELETE': {
            # This is used by the client bootstrap-fileinput to delete images.  But we don't actually delete them,
            # in case we need them for debug.  The client will later patch the message to remove them.
            $ret = [
                'ret' => 0,
                'status' => 'Success'
            ];

            break;
        }
    }

    return($ret);
}

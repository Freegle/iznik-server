<?php

namespace Freegle\Iznik;


use Jenssegers\ImageHash\ImageHash;

//use Google\Cloud\VideoIntelligence\V1\VideoIntelligenceServiceClient;
//use Google\Cloud\VideoIntelligence\V1\Feature;

# This is a base class
class Attachment {
    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;
    private $id, $table, $hash, $archived, $externalurl, $externaluid, $externalmods;

    /**
     * @return null
     */
    public function getId() {
        return $this->id;
    }

    const TYPE_MESSAGE = 'Message';
    const TYPE_GROUP = 'Group';
    const TYPE_NEWSLETTER = 'Newsletter';
    const TYPE_COMMUNITY_EVENT = 'CommunityEvent';
    const TYPE_CHAT_MESSAGE = 'ChatMessage';
    const TYPE_USER = 'User';
    const TYPE_NEWSFEED = 'Newsfeed';
    const TYPE_VOLUNTEERING = 'Volunteering';
    const TYPE_STORY = 'Story';
    const TYPE_NOTICEBOARD = 'Noticeboard';

    /**
     * @return mixed
     */
    public function getHash() {
        return $this->hash;
    }

    public function getPath($thumb = false, $id = null, $archived = false) {
        if ($this->externaluid) {
            $u = new UploadCare();
            return $u->getUrl($this->externaluid, $this->externalmods);
        }

        if ($this->externalurl) {
            return $this->externalurl;
        }

        # We serve up our attachment names as though they are files.
        # When these are fetched it will go through image.php
        $id = $id ? $id : $this->id;

        switch ($this->type) {
            case Attachment::TYPE_MESSAGE:
                $name = 'img';
                break;
            case Attachment::TYPE_GROUP:
                $name = 'gimg';
                break;
            case Attachment::TYPE_NEWSLETTER:
                $name = 'nimg';
                break;
            case Attachment::TYPE_COMMUNITY_EVENT:
                $name = 'cimg';
                break;
            case Attachment::TYPE_VOLUNTEERING:
                $name = 'oimg';
                break;
            case Attachment::TYPE_CHAT_MESSAGE:
                $name = 'mimg';
                break;
            case Attachment::TYPE_USER:
                $name = 'uimg';
                break;
            case Attachment::TYPE_NEWSFEED:
                $name = 'fimg';
                break;
            case Attachment::TYPE_STORY:
                $name = 'simg';
                break;
            case Attachment::TYPE_NOTICEBOARD:
                $name = 'bimg';
                break;
        }

        $name = $thumb ? "t$name" : $name;
        $domain = ($this->archived || $archived) ? IMAGE_ARCHIVED_DOMAIN : IMAGE_DOMAIN;

        return ("https://$domain/{$name}_$id.jpg");
    }

    public function getPublic() {
        $ret = array(
            'id' => $this->id,
            'hash' => $this->hash,
            $this->idatt => $this->{$this->idatt}
        );

        $ret['path'] = $this->getPath(false);
        $ret['paththumb'] = $this->getPath(true);
        $ret['mods'] = $this->externalmods;

        return ($ret);
    }

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = null, $type = Attachment::TYPE_MESSAGE, $atts = null) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->id = $id;
        $this->type = $type;
        $this->archived = false;
        $url = '';
        $uid = '';
        $mods = '';

        switch ($type) {
            case Attachment::TYPE_MESSAGE:
            {
                $this->table = 'messages_attachments';
                $this->idatt = 'msgid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            }
            case Attachment::TYPE_GROUP:
                $this->table = 'groups_images';
                $this->idatt = 'groupid';
                break;
            case Attachment::TYPE_NEWSLETTER:
                $this->table = 'newsletters_images';
                $this->idatt = 'articleid';
                break;
            case Attachment::TYPE_COMMUNITY_EVENT:
                $this->table = 'communityevents_images';
                $this->idatt = 'eventid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            case Attachment::TYPE_VOLUNTEERING:
                $this->table = 'volunteering_images';
                $this->idatt = 'opportunityid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            case Attachment::TYPE_CHAT_MESSAGE:
                $this->table = 'chat_images';
                $this->idatt = 'chatmsgid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            case Attachment::TYPE_USER:
            {
                $this->table = 'users_images';
                $this->idatt = 'userid';
                $this->externalurlname = 'url';
                $url = ', url';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            }
            case Attachment::TYPE_NEWSFEED:
                $this->table = 'newsfeed_images';
                $this->idatt = 'newsfeedid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            case Attachment::TYPE_STORY:
                $this->table = 'users_stories_images';
                $this->idatt = 'storyid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
            case Attachment::TYPE_NOTICEBOARD:
                $this->table = 'noticeboards_images';
                $this->idatt = 'noticeboardid';
                $this->uidname = 'externaluid';
                $this->modsname = 'externalmods';
                $uid = ', externaluid';
                $mods = ', externalmods';
                break;
        }

        if ($id) {
            $sql = "SELECT {$this->idatt}, hash, archived $url $uid $mods FROM {$this->table} WHERE id = ?;";
            $as = $atts ? [$atts] : $this->dbhr->preQuery($sql, [$id]);
            foreach ($as as $att) {
                $this->hash = $att['hash'];
                $this->archived = $att['archived'];
                $this->externalurl = Utils::presdef($this->externalurlname, $att, null);
                $this->externaluid = Utils::presdef($this->uidname, $att, null);
                $this->externalmods = Utils::presdef($this->modsname, $att, null);
                $this->{$this->idatt} = $att[$this->idatt];
            }
        }
    }

    public function create($id, $data, $uid = null, $url = null, $stripExif = TRUE, $mods = NULL) {
        if ($uid) {
            if ($stripExif && UPLOADCARE_PUBLIC_KEY) {
                // Image data is held externally on uploadcare.  The uploaded photo will contain EXIF data, and there
                // isn't currently a way to strip that out on upload.  So we have to copy the image to a new one which
                // "bakes in" the removal of the EXIF data.
                $uc = new UploadCare();
                $uid = $uc->stripExif($uid);
                $this->hash = $uc->getPerceptualHash($uid);
            }

            if ($this->externalurlname) {
                $rc = $this->dbhm->preExec(
                    "INSERT INTO {$this->table} (`{$this->idatt}`, `{$this->uidname}`, `{$this->externalurlname}`, `{$this->modsname}`, `hash`) VALUES (?, ?, ?, ?, ?);",
                    [
                        $id,
                        $uid,
                        $url,
                        json_encode($mods),
                        $this->hash,
                    ]
                );
            } else {
                $rc = $this->dbhm->preExec(
                    "INSERT INTO {$this->table} (`{$this->idatt}`, `{$this->uidname}`, `{$this->modsname}`, `hash`) VALUES (?, ?, ?, ?);",
                    [
                        $id,
                        $uid,
                        json_encode($mods),
                        $this->hash,
                    ]
                );
            }

            $imgid = $rc ? $this->dbhm->lastInsertId() : null;

            if ($imgid) {
                $this->id = $imgid;
                $this->externaluid = $uid;
                $this->externalmods = $mods;
                $this->externalurl = $url;
            }

            return ([$imgid, $uid]);
        } else {
            # We generate a perceptual hash.  This allows us to spot duplicate or similar images later.
            $hasher = new ImageHash;
            $img = @imagecreatefromstring($data);
            $hash = $img ? $hasher->hash($img) : null;

            $rc = $this->dbhm->preExec(
                "INSERT INTO {$this->table} (`{$this->idatt}`, `data`, `hash`) VALUES (?, ?, ?);",
                [
                    $id,
                    $data,
                    $hash
                ]
            );

            $imgid = $rc ? $this->dbhm->lastInsertId() : null;

            if ($imgid) {
                $this->id = $imgid;
                $this->hash = $hash;
            }

            return ($imgid);
        }
    }

    public function getById($id) {
        $sql = "SELECT id FROM {$this->table} WHERE {$this->idatt} = ? AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1) ORDER BY id;";
        $atts = $this->dbhr->preQuery($sql, [$id]);
        $ret = [];
        foreach ($atts as $att) {
            $ret[] = new Attachment($this->dbhr, $this->dbhm, $att['id']);
        }

        return ($ret);
    }

    public function getByIds($ids) {
        $ret = [];

        $extstr = '';
        $extwhere = '';

        switch ($this->type) {
            case Attachment::TYPE_MESSAGE:
                $extstr = ', externaluid, externalmods ';
                $extwhere = ' OR externaluid IS NOT NULL';
                break;
        }

        if (count($ids)) {
            $sql = "SELECT id, {$this->idatt}, hash, archived $extstr FROM {$this->table} 
                       WHERE {$this->idatt} IN (" . implode(',', $ids) . ") 
                       AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1 $extwhere) 
                       ORDER BY `primary` DESC, id;";
            #error_log($sql);
            $atts = $this->dbhr->preQuery($sql);
            foreach ($atts as $att) {
                $ret[] = new Attachment($this->dbhr, $this->dbhm, $att['id'], $this->type, $att);
            }
        }

        return ($ret);
    }

    public function getByImageIds($ids) {
        $ret = [];

        if (count($ids)) {
            $sql = "SELECT id, {$this->idatt}, hash, archived FROM {$this->table} WHERE id IN (" . implode(
                    ',',
                    $ids
                ) . ") AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1) ORDER BY id;";
            $atts = $this->dbhr->preQuery($sql);
            foreach ($atts as $att) {
                $ret[] = new Attachment($this->dbhr, $this->dbhm, $att['id'], $this->type, $att);
            }
        }

        return ($ret);
    }

    public function scp($host, $data, $fn, &$failed) {
        $connection = @ssh2_connect($host, 22);
        $failed = true;

        if ($connection) {
            if (@ssh2_auth_pubkey_file(
                $connection,
                CDN_SSH_USER,
                CDN_SSH_PUBLIC_KEY,
                CDN_SSH_PRIVATE_KEY
            )) {
                $temp = tempnam(sys_get_temp_dir(), "img_archive_$fn");
                file_put_contents($temp, $data);
                $rem = "/var/www/iznik/images/$fn";
                $retry = 0;

                do {
                    $rc = ssh2_scp_send($connection, $temp, $rem, 0644);

                    if (!$rc) {
                        $msg = "SCP of $rem failed, retry $retry";
                        \Sentry\captureMessage($msg);
                        error_log($msg);
                        sleep(1);
                    }

                    $retry++;
                } while (!$rc && $retry < 5);
                $failed = !$rc;
                unlink($temp);
                error_log("scp $temp to $host $rem returned $rc failed? $failed");
            }

            # Exit gracefully - might help with file truncation.
            ssh2_exec($connection, 'exit');
        }
    }

    public function archive() {
        if ($this->externalurl || $this->externaluid) {
            // We don't archive external images.
            return;
        }

        # We archive out of the DB onto our two CDN image hosts.  This reduces load on the servers because we don't
        # have to serve the images up, and it also reduces the disk space we need within the DB (which is not an ideal
        # place to store large amounts of image data);
        #
        # If we fail then we leave it unchanged for next time.
        $data = $this->getData();
        $rc = true;

        if ($data) {
            $rc = false;

            try {
                $name = null;

                # Only these types are in archive_attachments.
                switch ($this->type) {
                    case Attachment::TYPE_MESSAGE:
                        $tname = 'timg';
                        $name = 'img';
                        break;
                    case Attachment::TYPE_CHAT_MESSAGE:
                        $tname = 'tmimg';
                        $name = 'mimg';
                        break;
                    case Attachment::TYPE_NEWSFEED:
                        $tname = 'tfimg';
                        $name = 'fimg';
                        break;
                    case Attachment::TYPE_COMMUNITY_EVENT:
                        $tname = 'tcimg';
                        $name = 'cimg';
                        break;
                    case Attachment::TYPE_NOTICEBOARD:
                        $tname = 'tbimg';
                        $name = 'bimg';
                        break;
                }

                if ($name) {
                    $failed = false;

                    foreach ([CDN_HOST_1, CDN_HOST_2] as $host) {
                        # Upload the thumbnail.  If this fails we'll leave it untouched.
                        $i = new Image($data);
                        if ($i->img) {
                            $i->scale(250, 250);
                            $thumbdata = $i->getData(100);
                            $this->scp($host, $thumbdata, "{$tname}_{$this->id}.jpg", $failed2);
                            $failed |= $failed2;
                            $this->scp($host, $data, "{$name}_{$this->id}.jpg", $failed3);
                            $failed |= $failed3;
                        } else {
                            error_log("...failed to create image {$this->id}");
                        }
                    }

                    $rc = !$failed;
                }
            } catch (\Exception $e) {
                error_log("Archive failed " . $e->getMessage());
            }
        }

        if ($rc) {
            # Remove from the DB.
            $sql = "UPDATE {$this->table} SET archived = 1, data = NULL WHERE id = {$this->id};";
            $this->archived = true;
            $this->dbhm->exec($sql);
        }

        return ($rc);
    }

    public function setData($data) {
        $this->dbhm->preExec("UPDATE {$this->table} SET archived = 0, data = ? WHERE id = ?;", [
            $data,
            $this->id
        ]);
    }

    public function fgc($url, $use_include_path, $ctx) {
        return @file_get_contents($url, $use_include_path, $ctx);
    }

    public function canRedirect() {
        if ($this->externaluid) {
            $u = new UploadCare();
            return $u->getUrl($this->externaluid, $this->externalmods);
        } else if ($this->externalurl) {
            return $this->externalurl;
        } else {
            if ($this->archived) {
                # Only these types are in archive_attachments.
                switch ($this->type) {
                    case Attachment::TYPE_MESSAGE:
                        $tname = 'timg';
                        $name = 'img';
                        break;
                    case Attachment::TYPE_CHAT_MESSAGE:
                        $tname = 'tmimg';
                        $name = 'mimg';
                        break;
                    case Attachment::TYPE_NEWSFEED:
                        $tname = 'tfimg';
                        $name = 'fimg';
                        break;
                    case Attachment::TYPE_COMMUNITY_EVENT:
                        $tname = 'tcimg';
                        $name = 'cimg';
                        break;
                    case Attachment::TYPE_NOTICEBOARD:
                        $tname = 'tbimg';
                        $name = 'bimg';
                        break;
                }

                return 'https://' . IMAGE_ARCHIVED_DOMAIN . "/{$name}_{$this->id}.jpg";
            }
        }

        return false;
    }

    public function getData() {
        $ret = null;

        $url = $this->canRedirect();

        if ($url) {
            # This attachment has been archived out of our database, to a CDN.  Normally we would expect
            # that we wouldn't come through here, because we'd serve up an image link directly to the CDN, but
            # there is a timing window where we could archive after we've served up a link, so we have
            # to handle it.
            #
            # We fetch the data - not using SSL as we don't need to, and that host might not have a cert.  And
            # we put it back in the DB, because we are probably going to fetch it again.
            #
            # Apply a short timeout to avoid hanging the server if Azure is down.
            $ctx = stream_context_create(
                array(
                    'http' =>
                        array(
                            'timeout' => 2,
                        )
                )
            );

            $ret = $this->fgc($url, false, $ctx);
        } else {
            $sql = "SELECT * FROM {$this->table} WHERE id = ?;";
            $datas = $this->dbhr->preQuery($sql, [$this->id]);

            foreach ($datas as $data) {
                $ret = $data['data'];
            }
        }

        return ($ret);
    }

    public function identify() {
        # Identify objects in an attachment using Google Vision API.  Only for messages.
        $items = [];
        if ($this->type == Attachment::TYPE_MESSAGE) {
            $data = $this->getData();
            $base64 = base64_encode($data);

            $r_json = '{
                "requests": [
                    {
                      "image": {
                        "content":"' . $base64 . '"
                      },
                      "features": [
                          {
                            "type": "LABEL_DETECTION",
                            "maxResults": 20
                          }
                      ]
                    }
                ]
            }';

            $curl = curl_init();
            curl_setopt(
                $curl,
                CURLOPT_URL,
                'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_VISION_KEY
            );
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $r_json);
            $json_response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($status) {
                $rsp = json_decode($json_response, true);
                #error_log("Identified {$this->id} by Google $json_response for $r_json");

                if ($rsp && array_key_exists('responses', $rsp) && count($rsp['responses']) > 0 && array_key_exists(
                        'labelAnnotations',
                        $rsp['responses'][0]
                    )) {
                    $rsps = $rsp['responses'][0]['labelAnnotations'];
                    $i = new Item($this->dbhr, $this->dbhm);

                    foreach ($rsps as $rsp) {
                        $found = $i->findByName($rsp['description']);
                        $wasfound = false;
                        foreach ($found as $item) {
                            $this->dbhm->background(
                                "INSERT INTO messages_attachments_items (attid, itemid) VALUES ({$this->id}, {$item['id']});"
                            );
                            $wasfound = true;
                        }

                        if (!$wasfound) {
                            # Record items which were suggested but not considered as items by us.  This allows us to find common items which we ought to
                            # add.
                            #
                            # This is usually because they're too vague.
                            $url = "https://" . IMAGE_DOMAIN . "/img_{$this->id}.jpg";
                            $this->dbhm->background(
                                "INSERT INTO items_non (name, lastexample) VALUES (" . $this->dbhm->quote(
                                    $rsp['description']
                                ) . ", " . $this->dbhm->quote(
                                    $url
                                ) . ") ON DUPLICATE KEY UPDATE popularity = popularity + 1, lastexample = " . $this->dbhm->quote(
                                    $url
                                ) . ";"
                            );
                        }

                        $items = array_merge($items, $found);
                    }
                }
            }

            curl_close($curl);
        }

        return ($items);
    }

    public function findWebReferences() {
        # Find a web page containing this imge, if any.
        $ret = null;

        if ($this->type == Attachment::TYPE_MESSAGE) {
            $data = $this->getData();
            $base64 = base64_encode($data);

            $r_json = '{
                "requests": [
                    {
                      "image": {
                        "content":"' . $base64 . '"
                      },
                      "features": [
                          {
                            "type": "WEB_DETECTION",
                            "maxResults": 1
                          }
                      ]
                    }
                ]
            }';

            $curl = curl_init();
            curl_setopt(
                $curl,
                CURLOPT_URL,
                'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_VISION_KEY
            );
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $r_json);
            $json_response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($status) {
                $rsp = json_decode($json_response, true);
                #error_log("Identified {$this->id} by Google $json_response for $r_json");
                error_log("Matching " . var_export($rsp, true));

                if ($rsp &&
                    array_key_exists('responses', $rsp) &&
                    count($rsp['responses']) > 0 &&
                    array_key_exists('webDetection', $rsp['responses'][0]) &&
                    array_key_exists('pagesWithMatchingImages', $rsp['responses'][0]['webDetection'])) {
                    $rsps = $rsp['responses'][0]['webDetection']['pagesWithMatchingImages'];

                    foreach ($rsps as $r) {
                        if (array_key_exists('fullMatchingImages', $r) && strpos($r['url'], USER_SITE) === false) {
                            $ret = $r['url'];
                        }
                    }
                    error_log(var_export($rsps, true));
                }
            }

            curl_close($curl);
        }

        return ($ret);
    }

    public function ocr($data = null, $returnfull = false, $video = false) {
        # Identify text in an attachment using Google Vision API.
        $base64 = $data ? $data : base64_encode($this->getData());

        if ($video) {
//            "videoContext": {
//                "textDetectionConfig": {
//                    "languageHints": ["en"]
//                }
//              }
            $r_json = '{
              "inputContent": "' . $base64 . '",
              "features": ["TEXT_DETECTION"],
            }';
        } else {
            $r_json = '{
                "requests": [
                    {
                      "image": {
                        "content":"' . $base64 . '",
                      },
                      "features": [
                          {
                            "type": "TEXT_DETECTION"
                          }
                      ],
                      "imageContext": {
                        "languageHints": [
                          "en"
                        ]
                      }
                    }
                ]
            }';
        }

        $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_VISION_KEY;
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $r_json);

        if ($video) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: Bearer " . GOOGLE_VIDEO_KEY));
        }

        $json_response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $text = '';
        $rsps = null;

        if ($status) {
            error_log("Rsp $json_response");
            $rsp = json_decode($json_response, true);

            if ($rsp && array_key_exists('responses', $rsp) && count($rsp['responses']) > 0 && array_key_exists(
                    'textAnnotations',
                    $rsp['responses'][0]
                )) {
                $rsps = $rsp['responses'][0]['textAnnotations'];

                foreach ($rsps as $rsp) {
                    $text .= $rsp['description'] . "\n";
                    break;
                }
            }
        }

        curl_close($curl);

        return ($returnfull ? $rsps : $text);
    }

    public function objects($data = null) {
        # Identify objects in an attachment using Google Vision API.
        $base64 = $data ? $data : base64_encode($this->getData());

        $r_json = '{
            "requests": [
                {
                  "image": {
                    "content":"' . $base64 . '"
                  },
                  "features": [
                      {
                        "type": "OBJECT_LOCALIZATION"
                      }
                  ]
                }
            ]
        }';

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_VISION_KEY);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $r_json);
        $json_response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $rsp = null;

        if ($status) {
            $rsp = json_decode($json_response, true);
        }

        curl_close($curl);

        return ($rsp);
    }

    public function setPrivate($att, $val) {
        $this->dbhm->preExec("UPDATE {$this->table} SET `$att` = ? WHERE id = {$this->id};", [$val]);
    }

    public function delete() {
        $this->dbhm->preExec("DELETE FROM {$this->table} WHERE id = {$this->id};");
    }

    public function getIdAtt() {
        return $this->idatt;
    }

    function rotate($rotate) {
        if ($this->externaluid) {
            # We can rotate this by changing external mods.
            $mods = json_decode($this->externalmods, TRUE);
            $mods['rotate'] = $rotate;
            $this->setPrivate('externalmods', json_encode($mods));
        } else {
            $data = $this->getData();
            $i = new Image($data);
            $i->rotate($rotate);
            $newdata = $i->getData(100);
            $this->setData($newdata);
        }

        if ($this->type == Attachment::TYPE_MESSAGE) {
            # Only some kinds of attachments record whether they are rotated.
            $this->recordRotate();
        }
    }

    public function recordRotate() {
        $this->setPrivate('rotated', 1);
    }
}
<?php

namespace Freegle\Iznik;


use Jenssegers\ImageHash\ImageHash;
use GeminiAPI\Client;
use GeminiAPI\Enums\MimeType;
use GeminiAPI\Resources\ModelName;
use GeminiAPI\Resources\Parts\TextPart;
use GeminiAPI\Resources\Parts\ImagePart;

# TODO:
# - retire archiving

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

    public function getExternalUid() {
        return $this->externaluid;
    }

    public function getExternalMods() {
        return $this->externalmods;
    }

    public function getExternalUrl() {
        return $this->externalurl;
    }

    private function getImageDeliveryUrl($uid, $mods) {
        $p = strrpos($uid, 'freegletusd-');
        $url = NULL;

        if ($p !== FALSE) {
            $url = IMAGE_DELIVERY . "?";
            $mods = json_decode($mods, TRUE);
            
            if (Utils::pres('rotate', $mods)) {
                $url .= 'ro=' . $mods['rotate'] . "&";
            }

            $url .= "url=" . TUS_UPLOADER . "/" . substr($uid, $p + strlen('freegletusd-')) . "/";
        }

        return $url;
    }

    private function getExternalImageDeliveryUrl($externalurl, $mods) {
        $url = IMAGE_DELIVERY . "?";
        $mods = json_decode($mods, TRUE);

        if (Utils::pres('rotate', $mods)) {
            $url .= 'ro=' . $mods['rotate'] . "&";
        }

        $url .= "url=" . urlencode($externalurl);

        return $url;
    }

    public function getPath($thumb = false, $id = null, $archived = false, $mods = NULL) {
        if ($this->externaluid) {
            return $this->getImageDeliveryUrl($this->externaluid, $mods ? $mods : $this->externalmods);
        }

        if ($this->externalurl) {
            return $this->getExternalImageDeliveryUrl($this->externalurl, $this->externalmods);
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
        $this->uidname = 'externaluid';
        $this->modsname = 'externalmods';
        $uid = ', externaluid';
        $mods = ', externalmods';

        switch ($type) {
            case Attachment::TYPE_MESSAGE:
            {
                $this->table = 'messages_attachments';
                $this->idatt = 'msgid';
                $this->externalurlname = 'externalurl';
                $url = ', externalurl';
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
                break;
            case Attachment::TYPE_VOLUNTEERING:
                $this->table = 'volunteering_images';
                $this->idatt = 'opportunityid';
                break;
            case Attachment::TYPE_CHAT_MESSAGE:
                $this->table = 'chat_images';
                $this->idatt = 'chatmsgid';
                break;
            case Attachment::TYPE_USER:
            {
                $this->table = 'users_images';
                $this->idatt = 'userid';
                $this->externalurlname = 'url';
                $url = ', url';
                break;
            }
            case Attachment::TYPE_NEWSFEED:
                $this->table = 'newsfeed_images';
                $this->idatt = 'newsfeedid';
                break;
            case Attachment::TYPE_STORY:
                $this->table = 'users_stories_images';
                $this->idatt = 'storyid';
                break;
            case Attachment::TYPE_NOTICEBOARD:
                $this->table = 'noticeboards_images';
                $this->idatt = 'noticeboardid';
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

    public function create($id, $data, $uid = NULL, $url = null, $stripExif = TRUE, $mods = NULL, $hash = NULL) {
        if ($hash) {
            $this->hash = $hash;
        }

        if ($url && !$this->externalurlname) {
            # We need to fetch the data from an external URL, because there is no attribute in this table to
            # store the external url.
            $ctx = stream_context_create(['http' =>
                [
                    'timeout' => 120
                ]
             ]);

            $data = @file_get_contents($url, false, $ctx);
        }

        if (!$uid && $data) {
            # We have the literal data.  We want to avoid uploading the same image multiple times - something
            # which is particularly likely to happen with TN because it crossposts a lot and each separate message
            # (from our p.o.v.) contains a link to the same images.  We do this by doing a perceptual hash of the
            # image and having a local dirty cache of hashes we've seen before and the corresponding uploaded.
            # uid.  We rely on servers being rebooted before this gets too large.
            #
            # We use a simplistic file lock to serialise uploads so that this caching works.  It's common for us
            # to receive multiple emails from TN with the same images simultaneously.
            $fn = '/tmp/iznik.uploadlock';

            if (!file_exists($fn)) {
                touch($fn);
            }

            $fh = fopen($fn, 'r+');

            if ($fh) {
                if (!flock($fh, LOCK_EX)) {
                    error_log("Failed to lock upload file");
                    throw new \Exception("Failed to lock upload file");
                }
            } else {
                error_log("Failed to open upload file " . json_encode(error_get_last()));
                throw new \Exception("Failed to open upload file "  . json_encode(error_get_last()));
            }

            $hasher = new ImageHash;
            $img = @imagecreatefromstring($data);
            $uid = NULL;
            $fn = NULL;

            if ($img) {
                $this->hash = $hasher->hash($img)->toHex();
                $fn = "/tmp/imagehash-{$this->hash}";

                if (file_exists($fn)) {
                    $uid = file_get_contents($fn);
                    #error_log("Hash match on {$this->hash} for $id gives $uid");
                }
            }

            if (!$uid) {
                # No match - upload.
                $t = new Tus();
                $url = $t->upload(NULL, 'image/jpeg', $data);
                $uid = 'freegletusd-' . basename($url);
                file_put_contents($fn, $uid);
                #error_log("Uploaded to TUS $uid len " . strlen($data));
            }

            if ($fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }

        if ($uid) {
            # We now have an image uploaded.
            $rc = $this->dbhm->preExec(
                "INSERT INTO {$this->table} (`{$this->idatt}`, `{$this->uidname}`, `{$this->modsname}`, `hash`) VALUES (?, ?, ?, ?);",
                [
                    $id,
                    $uid,
                    json_encode($mods),
                    $this->hash,
                ]
            );

            $imgid = $rc ? $this->dbhm->lastInsertId() : null;

            if ($imgid) {
                $this->id = $imgid;
                $this->externaluid = $uid;
                $this->externalmods = $mods;
                $this->externalurl = $url;
            }

            return ([$imgid, $uid]);
        } else if ($this->externalurlname && $url) {
            $rc = $this->dbhm->preExec(
                "INSERT INTO {$this->table} (`{$this->idatt}`, `{$this->externalurlname}`) VALUES (?, ?);",
                [
                    $id,
                    $url,
                ]
            );

            $imgid = $rc ? $this->dbhm->lastInsertId() : null;

            if ($imgid) {
                $this->id = $imgid;
                $this->externalurl = $url;
            }

            return ([$imgid, NULL]);
        }

        return NULL;
    }

    public function getById($id) {
        $urlq = $this->externalurlname ? " OR {$this->externalurlname} IS NOT NULL" : '';
        $sql = "SELECT id FROM {$this->table} WHERE {$this->idatt} = ? AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1 OR externaluid IS NOT NULL $urlq) ORDER BY id;";
        $atts = $this->dbhr->preQuery($sql, [$id]);
        $ret = [];
        foreach ($atts as $att) {
            $ret[] = new Attachment($this->dbhr, $this->dbhm, $att['id']);
        }

        return ($ret);
    }

    public function getByIds($ids) {
        $ret = [];
        $urlq = $this->externalurlname ? " OR {$this->externalurlname} IS NOT NULL" : '';

        if (count($ids)) {
            $sql = "SELECT id, {$this->idatt}, hash, archived, externaluid, externalmods, externalurl FROM {$this->table} 
                       WHERE {$this->idatt} IN (" . implode(',', $ids) . ") 
                       AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1 OR externaluid IS NOT NULL $urlq) 
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
        $urlq = $this->externalurlname ? " OR {$this->externalurlname} IS NOT NULL" : '';
        if (count($ids)) {
            $sql = "SELECT id, {$this->idatt}, hash, archived FROM {$this->table} WHERE id IN (" . implode(
                    ',',
                    $ids
                ) . ") AND ((data IS NOT NULL AND LENGTH(data) > 0) OR archived = 1 OR externaluid IS NOT NULL $urlq) ORDER BY id;";
            $atts = $this->dbhr->preQuery($sql);
            foreach ($atts as $att) {
                $ret[] = new Attachment($this->dbhr, $this->dbhm, $att['id'], $this->type, $att);
            }
        }

        return ($ret);
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

    public function canRedirect($w = NULL, $h = NULL) {
        if ($this->externaluid) {
            # These are images uploaded to our current upload system, via tusd.
            return $this->getImageDeliveryUrl($this->externaluid, $this->externalmods);
        } else if ($this->externalurl) {
            # These are images hosted elsewhere, typically on TN or user profiles.
            return $this->getExternalImageDeliveryUrl($this->externalurl, $this->externalmods);
        } else {
            if ($this->archived) {
                # These are legacy images which have been archived to Azure storage.
                switch ($this->type) {
                    case Attachment::TYPE_MESSAGE:
                        $name = 'img';
                        break;
                    case Attachment::TYPE_CHAT_MESSAGE:
                        $name = 'mimg';
                        break;
                    case Attachment::TYPE_NEWSFEED:
                        $name = 'fimg';
                        break;
                    case Attachment::TYPE_COMMUNITY_EVENT:
                        $name = 'cimg';
                        break;
                    case Attachment::TYPE_NOTICEBOARD:
                        $name = 'bimg';
                        break;
                }

                $url = IMAGE_DELIVERY . "?";

                if ($w) {
                    $url .= "w=$w&";
                }

                if ($h) {
                    $url .= "h=$h&";
                }

                $url .= "url=" . urlencode("https://" . IMAGE_ARCHIVED_DOMAIN . "/{$name}_{$this->id}.jpg");

                return $url;
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
        // Ensure $rotate is not negative.
        if ($this->externaluid || $this->externalurl) {
            # We can rotate this by changing external mods.
            $mods = json_decode($this->externalmods, TRUE);
            $rotate = ($rotate + 360) % 360;
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

    public function recognise() {
        # Get external URL for image.  Use 768x768 as the maximum size because of how Gemini counts tokens..
        $url = $this->canRedirect(768, 768);
        $data = file_get_contents($url);

        $client = new Client(GOOGLE_GEMINI_API_KEY);
        $response = $client->withV1BetaVersion()
            ->generativeModel('gemini-2.0-flash-lite')
            ->withSystemInstruction(
                'Identify the primary item in each image. ' .
                'All items in images are likely to be second-hand household items.' .
                'Return all data in JSON format, as either strings or floats, with the following fields:' .
                'primaryItem, shortDescription, longDescription, approximateWeightInKg, size, condition, colour, estimatedValueInGBP, commonSynonyms, ElectricalItem, clarityOfImage.' .
                'In the JSON, use:' .
                'Great/OK/Poor for condition and clarityOfImage,' .
                'float for approximateWeightInKg,' .
                'short string for primaryItem,' .
                'float with an estimated secondhand eBay price in GBP estimatedValueInGBP,' .
                'the dimensions in wxhxd format in cm of a box which would contain the item for size' .
                '.  Write the description from the pov of the person who is giving away the item, friendly tone but stay factual and only add information that matches the image.'
            )->generateContent(
                new TextPart("Identify the image."),
                new ImagePart(MimeType::IMAGE_JPEG, base64_encode($data))
            );

        $text = $response->text();
        $json = substr($text, strpos($text, '{'), strrpos($text, '}') - strpos($text, '{') + 1);
        $jsond = json_decode($json, TRUE);

        if ($json && $jsond) {

            $this->dbhm->preExec("INSERT INTO messages_attachments_recognise (attid, info) VALUES (?, ?) ON DUPLICATE KEY UPDATE info = ?;", [
                $this->id,
                $json,
                $json
            ]);
        }

        return $jsond;
    }
}
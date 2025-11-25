<?php
namespace Freegle\Iznik;

use GuzzleHttp\Client;

class LoveJunk {
    /** @public  $dbhr LoggedPDO */
    public $dbhr;
    /** @public  $dbhm LoggedPDO */
    public $dbhm;

    public static $mock = FALSE;

    const MINIMUM_CPC = 0.10;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function send($id) {
        $ret = FALSE;
        $data = $this->getData($id);

        $client = new Client();

        try {
            if (!LoveJunk::$mock) {
                $r = $client->request('POST', LOVE_JUNK_API . '/freegle/drafts?secret=' . LOVE_JUNK_SECRET, [
                    'json'  => $data
                ]);
                $ret = TRUE;
                $rsp = json_decode((string)$r->getBody(), TRUE);
            } else {
                $ret = TRUE;
                $rsp = [
                    'body' => [
                        'draftId' => '1',
                        'response' => 'UT'
                    ]
                ];
            }

            $this->recordResult(TRUE, $id, json_encode($rsp['body']));
        } catch (\Exception $e) {
            if ($e->getCode() == 410) {
                // This is a valid error - import disabled.
                $ret = TRUE;
                $this->recordResult(TRUE, $id, $e->getCode() . " " . $e->getMessage());
            } else if ($e->getCode() == 409) {
                // Duplicate of another listing - that's fine from our point of view.
                $ret = TRUE;
                $this->recordResult(TRUE, $id, $e->getCode() . " " . $e->getMessage());
            } else {
                error_log("Error sending " . $e->getMessage() . " with " . json_encode($data));
                \Sentry\captureException($e);
                $this->recordResult(FALSE, $id, $e->getCode() . " " . $e->getMessage());
            }
        }

        return $ret;
    }

    public function edit($id, $ljdraftId) {
        $ret = FALSE;
        $data = $this->getData($id);

        $client = new Client();

        try {
            $this->dbhm->preExec("UPDATE lovejunk SET timestamp = NOW() WHERE msgid = ?", [
                $id
            ]);

            if (!LoveJunk::$mock) {
                $r = $client->request('PUT', LOVE_JUNK_API . '/freegle/drafts/' . $ljdraftId . '?secret=' . LOVE_JUNK_SECRET, [
                    'json'  => $data
                ]);
                $ret = TRUE;
            } else {
                $ret = TRUE;
                $rsp = [
                    'body' => [
                        'draftId' => '1',
                        'response' => 'UT'
                    ]
                ];
            }
        } catch (\Exception $e) {
            if ($e->getCode() == 410) {
                // This is a valid error - import disabled.
                $ret = TRUE;
            } else {
                error_log("Error editing " . $e->getMessage() . " with " . json_encode($data));
                \Sentry\captureException($e);
            }
        }

        return $ret;
    }

    private function getData($id) {
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $items = $m->getItems();
        $item = NULL;
        $data = NULL;

        if (count($items)) {
            $item = $items[0]['name'];
        } else {
            if (preg_match('/.*(OFFER|WANTED|TAKEN|RECEIVED) *[\\:-](.*)\\(.*\\)/', $m->getPrivate('suject'), $matches)) {
                $item = trim($matches[2]);
            }
        }

        $images = NULL;

        $rets = [
            [ 'id' => $id ]
        ];
        $msgs = [
            [ 'id' => $id ]
        ];

        $atts = $m->getPublicAttachments($rets, $msgs, FALSE);

        if (count($rets[$id]['attachments'])) {
            $images = [];

            foreach ($rets[$id]['attachments'] as $att) {
                $images[] = [
                    'url' => $att['path']
                ];
            }
        }

        $source = strpos($m->getSourceheader(), 'TN-') === 0 ? 'trashnothing' : 'freegle';

        $u = new User($this->dbhr, $this->dbhm, $m->getFromuser());

        if ($u->getPrivate('fullname')) {
            $firstName = $u->getPrivate('fullname');
            $lastName = ' ';
        } else {
            $firstName = $u->getPrivate('firstname');
            $lastName = $u->getPrivate('lastname');
        }

        $locid = $m->getPrivate('locationid');
        $locs = [];
        $postcode = NULL;
        $lat = $m->getPrivate('lat');
        $lng = $m->getPrivate('lng');
        $area = NULL;

        if ($locid) {
            // We have a location, so we can get the postcode name from that.
            $loc = $m->getLocation($locid, $locs);
            $postcode = $loc->getPrivate('name');
            $areaid = $loc->getPrivate('areaid');

            if ($areaid) {
                $a = new Location($this->dbhr, $this->dbhm, $areaid);
                $area = $a->getPrivate('name');
            }

        } else if ($lat || $lng) {
            // We don't have a postcode but we can try to find one from the lat/lng.
            $l = new Location($this->dbhr, $this->dbhm);
            $pc = $l->closestPostcode($lat, $lng);

            if ($pc) {
                $postcode = $pc['name'];
                $area = $pc['area']['name'];
            }
        } else {
            error_log("Failed on $id");
        }

        // We only want to send OFFERs with a location and item.
        if ($postcode && $item && $m->getType() == Message::TYPE_OFFER) {
            list ($lat, $lng) = Utils::blur($lat, $lng, Utils::BLUR_USER);

            $data = [
                'freegleId' => $id,
                'title' => $item,
                'description' => $m->getTextbody(),
                'source' => $source,
                'userData' => [
                    'userId' => $m->getFromuser(),
                    'firstName' => $firstName,
                    'lastName' => $lastName
                ],
                'locationData' => [
                    'postcode' => $postcode,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'area' => $area
                ]
            ];

            $data['images'] = $images;
        }

        error_log("Message $id data " . json_encode($data));

        return $data;
    }

    public function delete($id) {
        $ret = FALSE;

        $ljs = $this->dbhr->preQuery("SELECT * FROM lovejunk WHERE msgid = ? AND success = 1", [ $id ]);

        foreach ($ljs as $lj) {
            $ret = json_decode($lj['status'], TRUE);

            if (array_key_exists('draftId', $ret)) {
                $client = new Client();

                try {
                    if (!LoveJunk::$mock) {
                        $r = $client->request('DELETE', LOVE_JUNK_API . '/freegle/drafts/' . $ret['draftId'] . '?secret=' . LOVE_JUNK_SECRET);
                        $ret = TRUE;
                        $rsp = 200 . " " . $r->getReasonPhrase();
                    } else {
                        $ret = TRUE;
                        $rsp = 500 . " UT";
                    }

                    $this->recordResultDelete(TRUE, $id, json_encode($rsp));
                } catch (\Exception $e) {
                    error_log("Exception {$e->getMessage()}");
                    $this->recordResultDelete(TRUE, $id, $e->getCode() . " " . $e->getMessage());
                }
            }
        }

        return $ret;
    }

    private function recordResult($success, $msgid, $status) {
        $this->dbhm->preExec("INSERT INTO lovejunk (msgid, success, status) VALUES (?, ?, ?) ON DUPLICATE KEY update success = ?, status = ?;", [$msgid, $success, $status, $success, $status]);
    }

    private function recordResultDelete($success, $msgid, $status) {
        if ($success) {
            $this->dbhm->preExec("UPDATE lovejunk SET deleted = NOW(), deletestatus = ? WHERE msgid = ?", [
                $status,
                $msgid,
            ]);
        } else {
            $this->dbhm->preExec("UPDATE lovejunk SET deleted = NULL, deletestatus = ? WHERE msgid = ?", [
                $status,
                $msgid,
            ]);
        }
    }

    public function sendChatMessage($chatid, $message) {
        $msgs = $this->dbhr->preQuery("SELECT ljofferid FROM chat_rooms WHERE id = ?", [
            $chatid
        ]);

        foreach ($msgs as $msg) {
            $ljofferid = $msg['ljofferid'];
            $ret = 0;

            $client = new Client();

            $chunks = [ $message ];
            if (strlen($message) > 350) {
                // Split $message into chunks of no more than 350 characters, using a regular expression which splits on word boundaries.
                // We don't try that hard to split nicely.  Most messages will be shorter than this.
                $chunks = preg_split('/\b(.{1,350})\b/', $message, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            }

            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);

                if ($chunk) {
                    try {
                        if (!LoveJunk::$mock) {
                            $r = $client->request('POST', LOVE_JUNK_API . "/freegle/chats/$ljofferid?secret=" . LOVE_JUNK_SECRET, [
                                'json'  => [
                                    'message' => "$chunk\n"
                                ]
                            ]);

                            $statusCode = $r->getStatusCode();
                            $message = $r->getReasonPhrase();
                            echo "Chat $chatid offerid $ljofferid status $statusCode message $message\n";

                            if ($statusCode == 204) {
                                $ret++;
                            }
                        } else {
                            $ret++;
                        }
                    } catch (\Exception $e) {
                        // Ignore errors that can legitimately happen.
                        if (strpos($e->getMessage(), 'not active or could not find relevant freegle info') === FALSE &&
                            strpos($e->getMessage(), 'Could not create reuse offer message thread for offer') === FALSE &&
                            strpos($e->getMessage(), 'cannot write to thread') === FALSE) {
                            error_log("Exception {$e->getMessage()}");
                            \Sentry\captureException($e);
                        }
                    }
                }
            }
        }

        return $ret;
    }

    public function promise($chatid) {
        $ret = 0;

        $msgs = $this->dbhr->preQuery("SELECT ljofferid FROM chat_rooms WHERE id = ?", [
            $chatid
        ]);

        foreach ($msgs as $msg) {
            $ljofferid = $msg['ljofferid'];

            $client = new Client();

            try {
                if (!LoveJunk::$mock) {
                    $r = $client->request('PUT', LOVE_JUNK_API . "/freegle/offers/$ljofferid/accept?secret=" . LOVE_JUNK_SECRET, []);

                    $statusCode = $r->getStatusCode();
                    $message = $r->getReasonPhrase();
                    echo "Chat $chatid offerid $ljofferid promised status $statusCode message $message\n";

                    if ($statusCode == 200) {
                        $ret++;
                    }
                } else {
                    $ret++;
                }
            } catch (\Exception $e) {
                error_log("Exception {$e->getMessage()}");
                \Sentry\captureException($e);
            }
        }

        return $ret;
    }

    public function complete($chatid, $msgid) {
        $ret = 0;

        $msgs = $this->dbhr->preQuery("SELECT ljofferid FROM chat_rooms WHERE id = ?", [
            $chatid
        ]);

        foreach ($msgs as $msg) {
            $ljofferid = $msg['ljofferid'];

            $client = new Client();

            try {
                if (!LoveJunk::$mock) {
                    $r = $client->request('POST', LOVE_JUNK_API . "/freegle/offers/$ljofferid/complete?secret=" . LOVE_JUNK_SECRET, []);

                    $statusCode = $r->getStatusCode();
                    $message = $r->getReasonPhrase();
                    echo "Chat $chatid offerid $ljofferid completed status $statusCode message $message\n";

                    if ($statusCode == 204) {
                        $rsp = 200 . " " . $r->getReasonPhrase();
                        $this->recordResultDelete(TRUE, $msgid, json_encode($rsp));
                        $ret++;
                    }
                } else {
                    $ret++;
                }
            } catch (\Exception $e) {
                error_log("Exception {$e->getMessage()}");
                if (strpos($e->getMessage(), 'No suitable data found allowing supplier reuse of job') !== FALSE ||
                    strpos($e->getMessage(), 'Cannot find any data relevant to junk lover offer') !== FALSE) {
                    // Some errors are legitimate.
                    $this->recordResultDelete(TRUE, $msgid, $e->getMessage());
                } else {
                    \Sentry\captureException($e);
                }
            }
        }

        return $ret;
    }

    public function renege($chatid) {
        $ret = 0;

        $msgs = $this->dbhr->preQuery("SELECT ljofferid FROM chat_rooms WHERE id = ?", [
            $chatid
        ]);

        foreach ($msgs as $msg) {
            $ljofferid = $msg['ljofferid'];

            $client = new Client();

            try {
                if (!LoveJunk::$mock) {
                    $r = $client->request('PUT', LOVE_JUNK_API . "/freegle/offers/$ljofferid/relist?secret=" . LOVE_JUNK_SECRET, []);

                    $statusCode = $r->getStatusCode();
                    $message = $r->getReasonPhrase();
                    echo "Chat $chatid offerid $ljofferid reneged status $statusCode message $message\n";

                    if ($statusCode == 204) {
                        $ret++;
                    }
                } else {
                    $ret++;
                }
            } catch (\Exception $e) {
                error_log("Exception {$e->getMessage()}");
                \Sentry\captureException($e);
            }
        }

        return $ret;
    }

    public function completeOrDelete($msgid) {
        $m = new Message($this->dbhr, $this->dbhm, $msgid);

        $promises = $m->getPromises();
        $completed = FALSE;

        // Check each promise to see if it is to a LoveJunk user; if so then complete the promise.
        foreach ($promises as $promise) {
            $u = new User($this->dbhr, $this->dbhm, $promise['userid']);
            $r = new ChatRoom($this->dbhr, $this->dbhm);

            if ($u->getPrivate('ljuserid')) {
                list ($cid, $banned) = $r->createConversation($m->getFromuser(), $promise['userid'], TRUE);

                if ($cid) {
                    $r = new ChatRoom($this->dbhr, $this->dbhm, $cid);

                    if ($r->getPrivate('ljofferid')) {
                        // This message is promised to this LoveJunk user.  Complete the promise.
                        $this->complete($cid, $msgid);
                        $completed = TRUE;
                    }
                }
            }
        }

        if (!$completed) {
            // Gone elsewhere or some other outcome.
            $this->delete($msgid);
        }

        return $completed;
    }

    public function modMessage($frommail, $uid, $html) {
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $message = \Swift_Message::newInstance()
            ->setSubject("Freegle Volunteer message for LoveJunk user #$uid " . $u->getName())
            ->setFrom($frommail)
            ->setTo(LJ_SUPPORT_ADDR)
            ->setCc('log@ehibbert.org.uk');

        $htmlPart = \Swift_MimePart::newInstance();
        $htmlPart->setCharset('utf-8');
        $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
        $htmlPart->setContentType('text/html');
        $htmlPart->setBody($html);
        $message->attach($htmlPart);

        list ($transport, $mailer) = Mail::getMailer();
        Mail::addHeaders($this->dbhr, $this->dbhm, $message, Mail::MODMAIL);

        $mailer->send($message);
    }
}
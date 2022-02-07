<?php
namespace Freegle\Iznik;

class FreebieAlerts
{
    private $dbhr, $dbhm;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function doCurl($url, $fields) {
        $status = NULL;
        $json_response = NULL;

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                "Content-type: application/json",
                "Key: " . FREEBIE_ALERTS_KEY
            ]);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));

            $json_response = FREEBIE_ALERTS_KEY ? curl_exec($curl) : NULL;
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            $msg = date("Y-m-d H:i:s") . " post to freebies returned $status $json_response";
            error_log($msg);

            if ($status != 200 || $json_response != '{"success":true}') {
                \Sentry\captureMessage($msg);
            }
        } catch (\Exception $e) {
            error_log("Failed to update Freebie Alerts " . $e->getMessage());
            \Sentry\captureException($e);
        }

        return [ $status, $json_response ];
    }

    public function add($msgid) {
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $status = NULL;
        $json_response = NULL;

        # Only want outstanding OFFERs.
        if (!$m->hasOutcome() && $m->getPrivate('type') == Message::TYPE_OFFER) {
            $u = User::get($this->dbhr, $this->dbhm, $m->getFromuser());

            # TN messages are sync'd from TN itself.
            if (!$u->isTN()) {
                $atts = $m->getPublic();

                $images = [];

                foreach ($atts['attachments'] as $att) {
                    $images[] = $att['path'];
                }

                $body = $m->getTextbody();
                $body = $body ? $body : 'No description';

                $params = [
                    'id' => $msgid,
                    'title' => $m->getSubject(),
                    'description' => $body,
                    'latitude' => $atts['lat'],
                    'longitude' => $atts['lng'],
                    'images' => implode(',', $images),
                    'created_at' => Utils::ISODate($m->getPrivate('arrival'))
                ];

                list ($status, $json_response) = $this->doCurl('https://api.freebiealerts.app/freegle/post/create', $params);
                error_log(date("Y-m-d H:i:s") . " Added $msgid to freebies returned " . $json_response);
            } else {
                error_log(date("Y-m-d H:i:s") . " Skip TN message " . $u->getEmailPreferred());
            }
        } else {
            error_log(date("Y-m-d H:i:s") . " Skip message " . $m->hasOutcome() . " type " . $m->getPrivate('type'));
        }

        return $status;
    }

    public function remove($msgid) {
        list ($status, $json_response) = $this->doCurl("https://api.freebiealerts.app/freegle/post/$msgid/delete", []);
        return $status;
    }
}

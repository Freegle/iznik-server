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
        if (!FREEBIE_ALERTS_KEY) {
            return [NULL, NULL];
        }

        try {
            $response = $this->executeCurlRequest($url, $fields);
            $this->validateResponse($response);
            return [$response['status'], $response['body']];
        } catch (\Exception $e) {
            error_log("Failed to update Freebie Alerts " . $e->getMessage());
            \Sentry\captureException($e);
            return [NULL, NULL];
        }
    }

    private function executeCurlRequest($url, $fields) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Content-type: application/json",
            "Key: " . FREEBIE_ALERTS_KEY
        ]);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));

        $json_response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => $json_response];
    }

    private function validateResponse($response) {
        $status = $response['status'];
        $json_response = $response['body'];
        $rsp = json_decode($json_response, TRUE);

        $isSuccessful = $status == 200 &&
                       is_array($rsp) &&
                       array_key_exists('success', $rsp) &&
                       $rsp['success'];

        if (!$isSuccessful) {
            $msg = date("Y-m-d H:i:s") . " post to freebies returned $status $json_response";
            \Sentry\captureMessage($msg);
        }
    }

    public function add($msgid) {
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $status = NULL;

        if (!$this->isEligibleMessage($m)) {
            error_log(date("Y-m-d H:i:s") . " Skip message " . $m->hasOutcome() . " type " . $m->getPrivate('type'));
            return $status;
        }

        $u = User::get($this->dbhr, $this->dbhm, $m->getFromuser());

        if ($u->isTN()) {
            error_log(date("Y-m-d H:i:s") . " Skip TN message " . $u->getEmailPreferred());
            return $status;
        }

        $atts = $m->getPublic();

        if (!$this->hasRequiredData($atts, $m)) {
            error_log(date("Y-m-d H:i:s") . " Skip message $msgid - missing required data (lat/lng or subject)");
            return $status;
        }

        return $this->createFreebieAlert($msgid, $m, $atts);
    }

    private function isEligibleMessage($message) {
        return !$message->hasOutcome() && $message->getPrivate('type') == Message::TYPE_OFFER;
    }

    private function hasRequiredData($atts, $message) {
        return $atts['lat'] !== NULL && $atts['lng'] !== NULL && $message->getSubject();
    }

    private function createFreebieAlert($msgid, $message, $atts) {
        $images = [];

        foreach ($atts['attachments'] as $att) {
            $images[] = $att['path'];
        }

        $body = $message->getTextbody();
        $body = $body ? $body : 'No description';

        $groups = $message->getGroups(FALSE, FALSE);

        $params = [
            'id' => $msgid,
            'title' => $message->getSubject(),
            'description' => $body,
            'latitude' => $atts['lat'],
            'longitude' => $atts['lng'],
            'images' => implode(',', $images),
            'created_at' => Utils::ISODate(count($groups) ? $groups[0]['arrival'] : $message->getPrivate('arrival'))
        ];

        list ($status, $response) = $this->doCurl('https://api.freebiealerts.app/freegle/post/create', $params);
        error_log(date("Y-m-d H:i:s") . " Added $msgid to freebies returned " . $response);

        return $status;
    }

    public function remove($msgid) {
        list ($status, ) = $this->doCurl("https://api.freebiealerts.app/freegle/post/$msgid/delete", []);
        return $status;
    }
}

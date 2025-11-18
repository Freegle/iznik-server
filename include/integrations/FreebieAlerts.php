<?php
namespace Freegle\Iznik;

class FreebieAlerts
{
    private const HTTP_OK = 200;
    private const API_BASE_URL = 'https://api.freebiealerts.app/freegle/post';
    private const CURL_TIMEOUT = 60;

    private $dbhr, $dbhm;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    private function logWithTimestamp($message) {
        error_log(date("Y-m-d H:i:s") . " " . $message);
    }

    private function isResponseSuccessful($status, $json_response) {
        if ($status != self::HTTP_OK) {
            return FALSE;
        }

        $rsp = json_decode($json_response, TRUE);
        return $rsp && array_key_exists('success', $rsp) && $rsp['success'];
    }

    public function doCurl($url, $fields) {
        $status = NULL;
        $json_response = NULL;

        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                "Content-type: application/json",
                "Key: " . FREEBIE_ALERTS_KEY
            ]);
            curl_setopt($curl, CURLOPT_POST, TRUE);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));

            $json_response = FREEBIE_ALERTS_KEY ? curl_exec($curl) : NULL;
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if (!$this->isResponseSuccessful($status, $json_response)) {
                $msg = "post to freebies returned $status $json_response";
                $this->logWithTimestamp($msg);
                \Sentry\captureMessage($msg);
            }
        } catch (\Exception $e) {
            error_log("Failed to update Freebie Alerts " . $e->getMessage());
            \Sentry\captureException($e);
        }

        return [ $status, $json_response ];
    }

    private function shouldAddMessage(Message $message) {
        if ($message->hasOutcome() || $message->getPrivate('type') != Message::TYPE_OFFER) {
            return FALSE;
        }

        // Don't send messages without location data to FreebieAlerts
        // as they will return a 500 error
        $atts = $message->getPublic();
        if ($atts['lat'] === NULL || $atts['lng'] === NULL) {
            $this->logWithTimestamp("Skip message without location data msgid=" . $message->getId());
            return FALSE;
        }

        return TRUE;
    }

    private function extractImages($attachments) {
        $images = [];
        foreach ($attachments as $att) {
            $images[] = $att['path'];
        }
        return $images;
    }

    private function buildMessageParams($msgid, Message $message) {
        $atts = $message->getPublic();
        $images = $this->extractImages($atts['attachments']);
        $body = $message->getTextbody() ?: 'No description';
        $groups = $message->getGroups(FALSE, FALSE);

        $params = [
            'id' => $msgid,
            'title' => $message->getSubject() ?: 'No Title',
            'description' => $body,
            'created_at' => Utils::ISODate(count($groups) ? $groups[0]['arrival'] : $message->getPrivate('arrival'))
        ];

        if ($atts['lat'] !== NULL && $atts['lng'] !== NULL) {
            $params['latitude'] = $atts['lat'];
            $params['longitude'] = $atts['lng'];
        }

        if (!empty($images)) {
            $params['images'] = implode(',', $images);
        }

        return $params;
    }

    public function add($msgid) {
        $message = new Message($this->dbhr, $this->dbhm, $msgid);
        $status = NULL;

        if (!$this->shouldAddMessage($message)) {
            $this->logWithTimestamp("Skip message " . $message->hasOutcome() . " type " . $message->getPrivate('type'));
            return $status;
        }

        $user = User::get($this->dbhr, $this->dbhm, $message->getFromuser());

        if ($user->isTN()) {
            $this->logWithTimestamp("Skip TN message " . $user->getEmailPreferred());
            return $status;
        }

        $params = $this->buildMessageParams($msgid, $message);
        list ($status, $json_response) = $this->doCurl(self::API_BASE_URL . '/create', $params);
        $this->logWithTimestamp("Added $msgid to freebies returned " . $json_response);

        return $status;
    }

    public function remove($msgid) {
        list ($status, $json_response) = $this->doCurl(self::API_BASE_URL . "/$msgid/delete", []);
        return $status;
    }
}

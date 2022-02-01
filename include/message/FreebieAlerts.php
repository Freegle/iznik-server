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
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);

        $json_response = FREEBIE_ALERTS_KEY ? curl_exec($curl) : NULL;
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return [ $status, $json_response ];
    }

    public function add($msgid) {
        $m = new Message($this->dbhr, $this->dbhm, $msgid);
        $atts = $m->getPublic();

        $images = [];

        foreach ($atts['attachments'] as $att) {
            $images[] = $att->getPath();
        }

        $params = [
            'id' => $msgid,
            'title' => $m->getSubject(),
            'description' => $m->getTextbody(),
            'latitude' => $atts['lat'],
            'longitude' => $atts['lng'],
            'images' => implode(',', $images),
            'created_at' => Utils::ISODate($m->getPrivate('arrival'))
        ];

        list ($status, $json_response) = $this->doCurl('https://api.freebiealerts.app/freegle/post/create', $params);
        return $status;
    }

    public function remove($msgid) {
        list ($status, $json_response) = $this->doCurl("https://api.freebiealerts.app/freegle/post/$msgid/delete", []);
        return $status;
    }
}

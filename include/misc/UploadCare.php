<?php
namespace Freegle\Iznik;

class UploadCare {
    private $config = NULL;
    private $fileApi = NULL;

    function __construct() {
        if (UPLOADCARE_PUBLIC_KEY) {
            $this->config = \Uploadcare\Configuration::create(UPLOADCARE_PUBLIC_KEY, UPLOADCARE_SECRET_KEY);
            $this->api = (new \Uploadcare\Api($this->config));
            $this->fileApi = $this->api->file();
            $this->uploaderApi = $this->api->uploader();
        }
    }

    function getUrl($uid, $mods) {
        # Construct the external URL from the UID and mods.
        $url = UPLOADCARE_CDN . "$uid/";
        $mods = json_decode($mods, TRUE);

        if ($mods) {
            foreach ($mods as $mod => $val) {
                $url .= "-/$mod/$val/";
            }
        }

        return $url;
    }
}

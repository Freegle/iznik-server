<?php
namespace Freegle\Iznik;

class UploadCare {
    private $config = NULL;
    private $fileApi = NULL;


    function __construct() {
        $this->config = \Uploadcare\Configuration::create(UPLOADCARE_PUBLIC_KEY, UPLOADCARE_SECRET_KEY);
        $this->api = (new \Uploadcare\Api($this->config));
        $this->fileApi = $this->api->file();
        $this->uploaderApi = $this->api->uploader();
    }

    function stripExif($uid, $url) {
        $oldFileInfo = $this->fileApi->fileInfo($uid);

        # We want to strip the EXIF data.  We remove all of it to avoid any privacy issues.  You have to
        # add preview as an operation to make it work.
        #
        # syncUploadFromUrl guarantees that the image is available on the CDN before returning.
        $newFileInfo = $this->uploaderApi->syncUploadFromUrl("https://ucarecdn.com/$uid/-/strip_meta/all/-/preview/");
        $newuid = $newFileInfo->getUuid();
        $newurl = $newFileInfo->getOriginalFileUrl();
        $this->fileApi->storeFile($newuid);
        #error_log("Copy $uid, $url to $newuid, $newurl, ready " . $newFileInfo->isReady());

        if ($newuid) {
            $this->fileApi->deleteFile($oldFileInfo);
        }

        return [ $newuid, $newurl ];
    }

    function getPerceptualHash($uid) {
        $json  = file_get_contents("https://ucarecdn.com/$uid/-/json/");

        if ($json) {
            $data = json_decode($json, true);
            return $data['hash'];
        }

        return NULL;
    }
}

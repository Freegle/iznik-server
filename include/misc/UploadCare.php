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

    function upload($data, $mimeType) {
        $file = $this->uploaderApi->fromContent($data, $mimeType);
        $uid = $file->getUuid();

        return $uid;
    }

    function stripExif($uid) {
        # We want to strip the EXIF data.  We remove all of it to avoid any privacy issues.  You have to
        # add preview as an operation to make it work.
        #
        # syncUploadFromUrl guarantees that the image is available on the CDN before returning.
//        $oldFileInfo = $this->fileApi->fileInfo($uid);
//        $newFileInfo = $this->uploaderApi->syncUploadFromUrl(UPLOADCARE_CDN . "$uid/-/strip_meta/all/-/preview/");
//        $newuid = $newFileInfo->getUuid();
//        $this->fileApi->storeFile($newuid);
//        #error_log("Copy $uid, $url to $newuid, $newurl, ready " . $newFileInfo->isReady());
//
//        if ($newuid) {
//            $this->fileApi->deleteFile($oldFileInfo);
//        }
//
//        return $newuid;

        // Disabling to investigate the effect on costs.
        return $uid;
    }

    function getPerceptualHash($uid) {
        $json  = file_get_contents(UPLOADCARE_CDN . "$uid/-/json/");

        if ($json) {
            $data = json_decode($json, true);
            return $data['hash'];
        }

        return NULL;
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

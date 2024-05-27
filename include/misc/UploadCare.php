<?php
namespace Freegle\Iznik;

class UploadCare {
    private $config = NULL;
    private $fileApi = NULL;


    function __construct() {
        $this->config = \Uploadcare\Configuration::create(UPLOADCARE_PUBLIC_KEY, UPLOADCARE_SECRET_KEY);
        $this->fileApi = (new \Uploadcare\Api($this->config))->file();
    }

    function stripExif($uid, $url) {
        $oldFileInfo = $this->fileApi->fileInfo($uid);

        # We want to strip the EXIF data.  We remove all of it to avoid any privacy issues.  You have to
        # add preview as an operation to make it work.
        $newFileInfo = $this->fileApi->copyToLocalStorage($uid . "/-/strip_meta/all/-/preview/", true);
        $newuid = $newFileInfo->getUuid();
        $newurl = $newFileInfo->getOriginalFileUrl();
        $this->fileApi->storeFile($newuid);
        #error_log("Copy $uid, $url to $newuid, $newurl, ready " . $newFileInfo->isReady());

        # The image is not immediately available on the CDN.  Wait for upto a second.
        for ($i = 0; $i < 10; $i++) {
            if ($newFileInfo->isReady()) {
                #error_log("$newurl is ready");
                break;
            } else {
                #error_log("$newurl is not ready");
                usleep(100000); # 0.1s
                $newFileInfo = $this->fileApi->fileInfo($newuid);
            }
        }

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

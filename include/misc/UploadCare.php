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
        #error_log("Copy $uid, $url to $newuid, $newurl");

        if ($newuid) {
            $this->fileApi->deleteFile($oldFileInfo);
        }

        return [ $newuid, $newurl ];
    }
}

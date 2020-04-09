<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');

class Catalogue
{
    /** @var  $dbhr LoggedPDO */
    var $dbhr;
    /** @var  $dbhm LoggedPDO */
    var $dbhm;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function doOCR($a, $data) {
        return $a->ocr($data, TRUE);
    }

    public function ocr($data) {
        $a = new Attachment($this->dbhr, $this->dbhm);
        $text = $this->doOCR($a, $data);
        $this->dbhm->preExec("INSERT INTO booktastic_ocr (data, text) VALUES (?, ?);", [
            $data,
            json_encode($text)
        ]);

        $id = $this->dbhm->lastInsertId();

        return [ $id, $text ];
    }

    public function identifyAuthors($id) {

    }
}
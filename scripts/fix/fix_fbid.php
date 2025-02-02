<?php


require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');


use JanuSoftware\Facebook\FacebookSession;
use JanuSoftware\Facebook\FacebookJavaScriptLoginHelper;
use JanuSoftware\Facebook\FacebookCanvasLoginHelper;
use JanuSoftware\Facebook\FacebookRequest;
use JanuSoftware\Facebook\FacebookRequestException;

$groups = $dbhr->preQuery("SELECT * FROM groups_facebook;");

$f = new GroupFacebook($dbhr, $dbhm);
$fb = $f->getFB(FALSE);

foreach ($groups as $group) {
    try {
        if (!$group['id']) {
            $ret = $fb->get($group['name'], 'EAABo7zTHzCsBADWGwABloWnZC2YbguPmkKV4vsHkbsNZAJAqLJYmlybEzLLRK5piy5yzG1sKwzCBaqvRbeZCxrjB7VMeZCvTqL2CK8qsZAOb7lYTQiMDMw4HlUt240CVu0dX51YzZAydIZAPi4RNcutnjbZAQtdlCrGy464ZBDU43ZAgZDZD');
            $data = $ret->getDecodedBody();
            var_dump($data);
            $dbhm->preExec("UPDATE groups_facebook SET name = ?, id = ? WHERE groupid = ?;", [
                $data['name'],
                $data['id'],
                $group['groupid']
            ]);
        }
    } catch (\Exception $e) {}
}
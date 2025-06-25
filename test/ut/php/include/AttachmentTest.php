<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class AttachmentTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    private $blobCount = 0;

    public function createBlockBlob() {
        $this->blobCount++;
    }

    public function testHash() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        list ($attid1, $uid) = $a->create(NULL,$data);
        $this->assertNotNull($attid1);

        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        list ($attid2, $uid) = $a->create(NULL, $data);
        $this->assertNotNull($attid1);

        $a1 = new Attachment($this->dbhr, $this->dbhm, $attid1, Attachment::TYPE_GROUP);
        $a2 = new Attachment($this->dbhr, $this->dbhm, $attid2, Attachment::TYPE_GROUP);
        $this->assertEquals($a1->getHash(), $a2->getHash());
    }

    public function testUrl() {
        $url = 'https://www.ilovefreegle.org/user_logo_vector.svg';

        $this->dbhm->preExec("INSERT INTO users_images (url) VALUES (?);", [
            $url
        ]);

        $id = $this->dbhm->lastInsertId();

        $data = file_get_contents($url);
        $a = new Attachment($this->dbhr, $this->dbhm, $id, Attachment::TYPE_USER);
        $this->assertEquals($data, $a->getData());
    }

    public function testGetByImageIds() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        list ($attid1, $uid) = $a->create(NULL,$data);
        $this->assertNotNull($attid1);

        $atts = $a->getByImageIds([ $attid1 ]);
        $this->assertEquals($attid1, $atts[0]->getId());
    }

    public function testExternal() {
        $url = 'https://ilovefreegle.org/icon.png';
        $a = new Attachment($this->dbhr, $this->dbhm);
        list ($attid, $uid) = $a->create(NULL,NULL,'freegletusd-uid', $url, FALSE);
        $this->assertNotNull($attid);

        $a = new Attachment($this->dbhr, $this->dbhm, $attid);
        # Data length will be zero because this isn't a real uploaded file.
        $this->assertEquals(0, strlen($a->getData()));
        $this->assertEquals(TUS_UPLOADER . '/uid/', $a->getPath());
        $atts = $a->getPublic();
        $this->assertEquals(TUS_UPLOADER . '/uid/', $atts['path']);
        $this->assertEquals(TUS_UPLOADER . '/uid/', $atts['paththumb']);
    }
}


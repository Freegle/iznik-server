<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/message/Attachment.php';
require_once IZNIK_BASE . '/include/user/User.php';


/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class AttachmentTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testIdentify() {
        if (!getenv('STANDALONE')) {
            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
            $a = new Attachment($this->dbhr, $this->dbhm);
            $attid = $a->create(NULL, 'image/jpeg', $data);
            assertNotNull($attid);

            $a = new Attachment($this->dbhr, $this->dbhm, $attid);

            $idents = $a->identify();
            $this->log("Identify returned " . var_export($idents, TRUE));
            assertEquals('chair', trim(strtolower($idents[0]['name'])));
        }

        assertTrue(TRUE);

        }

    private $blobCount = 0;

    public function createBlockBlob() {
        $this->blobCount++;
    }

    public function attTypes() {
        # Most types don't archive.
        return([
            [ Attachment::TYPE_MESSAGE, 2 ],
            [ Attachment::TYPE_GROUP, 0 ],
            [ Attachment::TYPE_NEWSLETTER, 0 ],
            [ Attachment::TYPE_COMMUNITY_EVENT, 0 ],
            [ Attachment::TYPE_VOLUNTEERING , 0],
            [ Attachment::TYPE_CHAT_MESSAGE, 2 ],
            [ Attachment::TYPE_USER, 0 ],
            [ Attachment::TYPE_NEWSFEED, 2 ],
            [ Attachment::TYPE_STORY, 0 ]
        ]);
    }

    /**
     * @dataProvider attTypes
     */
    public function testArchive($attType, $blobCount) {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');

        $a = $this->getMockBuilder('Attachment')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm, NULL, $attType ])
            ->setMethods(array('getAzure'))
            ->getMock();
        $a->method('getAzure')->willReturn($this);

        $attid = $a->create(NULL, 'image/jpeg', $data);
        assertNotNull($attid);

        $ret = $a->archive();
        assertEquals($blobCount, $this->blobCount);
        assertEquals($blobCount > 0, $ret);

        if (!getenv('STANDALONE')) {
            $dat2 = $a->getData();
            assertEquals($data, $dat2);
        }

        $a->delete();

        }

    public function testHash() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        $attid1 = $a->create(NULL, 'image/jpeg', $data);
        assertNotNull($attid1);

        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        $attid2 = $a->create(NULL, 'image/jpeg', $data);
        assertNotNull($attid1);

        $a1 = new Attachment($this->dbhr, $this->dbhm, $attid1, Attachment::TYPE_GROUP);
        $a2 = new Attachment($this->dbhr, $this->dbhm, $attid2, Attachment::TYPE_GROUP);
        assertEquals($a1->getHash(), $a2->getHash());

        }
}


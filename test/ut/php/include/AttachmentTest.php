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

    public function testIdentify() {
        if (GOOGLE_VISION_KEY != ' GOOGLE_VISION_KEY') {
            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
            $a = new Attachment($this->dbhr, $this->dbhm);
            $attid = $a->create(null, $data);
            $this->assertNotNull($attid);

            $a = new Attachment($this->dbhr, $this->dbhm, $attid);

            $idents = $a->identify();
            $this->log("Identify returned " . var_export($idents, true));
            $this->assertEquals('chair', trim(strtolower($idents[0]['name'])));
        }

        $this->assertTrue(TRUE);
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
            [ Attachment::TYPE_COMMUNITY_EVENT, 2 ],
            [ Attachment::TYPE_VOLUNTEERING , 0],
            [ Attachment::TYPE_CHAT_MESSAGE, 2 ],
            [ Attachment::TYPE_USER, 0 ],
            [ Attachment::TYPE_NEWSFEED, 2 ],
            [ Attachment::TYPE_STORY, 0 ],
            [ Attachment::TYPE_NOTICEBOARD, 2 ]
        ]);
    }

    /**
     * @dataProvider attTypes
     */
    public function testArchive($attType, $blobCount) {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');

        $a = $this->getMockBuilder('Freegle\Iznik\Attachment')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm, 1, $attType ])
            ->setMethods([ 'scp', 'fgc' ])
            ->getMock();

        $originalPath = $a->getPath();
        $this->assertNotNull($originalPath);

        $a->method('scp')->will($this->returnCallback(function ($host, $data, $fn, &$failed) {
            $this->blobCount++;
        }));

        $a->method('fgc')->willReturnCallback(function($url, $use_include_path, $ctx) {
            return 'UT';
        });

        $attid = $a->create(NULL, $data);
        $this->assertNotNull($attid);

        $ret = $a->archive();
        $this->assertEquals($blobCount * 2, $this->blobCount);
        $this->assertEquals($blobCount > 0, $ret);

        $dat2 = $a->getData();
        $this->assertTrue($data == $dat2 || $dat2 == 'UT');

        $a->delete();
    }

    public function testHash() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        $attid1 = $a->create(NULL,$data);
        $this->assertNotNull($attid1);

        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        $attid2 = $a->create(NULL, $data);
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
        $attid1 = $a->create(NULL,$data);
        $this->assertNotNull($attid1);

        $atts = $a->getByImageIds([ $attid1 ]);
        $this->assertEquals($attid1, $atts[0]->getId());
    }

    public function testSCP() {
        if (function_exists('ssh2_connect')) {
            $failed = FALSE;
            $a = new Attachment($this->dbhr, $this->dbhm);
            $a->scp('localhost', 'testdata', 'unittest', $failed);
            $this->assertEquals(1, $failed);

            # Invalid host, fails.
            $a->scp('localhost2', 'testdata', 'unittest', $failed);
            $this->assertEquals(1, $failed);
        }

        $this->assertTrue(TRUE);
    }

    public function testExternal() {
        $url = 'https://ilovefreegle.org/icon.png';
        $a = new Attachment($this->dbhr, $this->dbhm);
        $attid = $a->create(NULL,NULL,'uid', $url);
        $this->assertNotNull($attid);
        $a->setPrivate('externalurl', $url);

        $a = new Attachment($this->dbhr, $this->dbhm, $attid);
        $this->assertGreaterThan(0, strlen($a->getData()));
        $a->archive();
        $this->assertGreaterThan(0, strlen($a->getData()));
        $this->assertEquals($url, $a->getPath());
        $atts = $a->getPublic();
        $this->assertEquals($url, $atts['path']);
        $this->assertEquals($url, $atts['paththumb']);
    }
}


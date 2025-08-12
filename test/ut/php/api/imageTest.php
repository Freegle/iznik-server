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
class imageAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
    }

    public function testApproved()
    {
        list($g, $group1) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($u, $uid, $emailid) = $this->createTestUser(NULL, NULL, 'Test User', 'test@test.com', 'testpw');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');
        list ($r, $id, $failok, $rc) = $this->createTestMessage($msg, 'testgroup', 'from@test.com', 'to@test.com', $group1, $uid);
        $this->assertEquals(MailRouter::APPROVED, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('sourceheader', Message::PLATFORM);

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals($id, $ret['message']['id']);

        # One image stripped out due to aspect ratio.
        $this->assertEquals(1, count($ret['message']['attachments']));
        $img1 = $ret['message']['attachments'][0]['id'];

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'w' => 100
        ], FALSE);

        $a->delete();
        $g->delete();
    }

    public function testPost()
    {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/pan.jpg');
        file_put_contents("/tmp/pan.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/pan.jpg'
            ],
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $id = $ret['id'];

        $a = new Attachment($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('null', $a->getPublic()['mods']);
        
        # Now rotate. We have to check the resulting mods to make sure the image has been rotated.
        $ret = $this->call('image', 'POST', [
            'id' => $id,
            'rotate' => 90
        ]);

        $this->assertEquals(0, $ret['ret']);

        $a = new Attachment($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('{"rotate":90}', $a->getPublic()['mods']);

        # Rotate back.
        $ret = $this->call('image', 'POST', [
            'id' => $id,
            'rotate' => -90
        ]);
        $this->assertEquals(0, $ret['ret']);

        $a = new Attachment($this->dbhr, $this->dbhm, $id);
        $this->assertEquals('{"rotate":270}', $a->getPublic()['mods']);

        $ret = $this->call('image', 'DELETE', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
    }

    public function testHEIC()
    {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/image1.heic');
        file_put_contents("/tmp/image1.heic", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/image1.heic',
                'type' => 'image/heic'
            ],
        ]);

        $this->assertEquals(2, $ret['ret']);
    }

    /**
     * @dataProvider types
     */
    public function testTypes($type) {
        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/pan.jpg'
            ],
            $type => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $id = $ret['id'];

        $ret = $this->call('image', 'DELETE', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
    }

    public function types() {
        return [
            [NULL],
            ['group'],
            ['newsletter'],
            ['communityevent'],
            ['chatmessage'],
            ['user'],
            ['newsfeed'],
            ['volunteering'],
            ['story']
        ];
    }

    /**
     * @dataProvider exif
     */
    public function testExif($file) {
        $data = file_get_contents(IZNIK_BASE . "/test/ut/php/images/exif/$file.jpg");
        file_put_contents("/tmp/$file.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => "/tmp/$file.jpg"
            ]
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $id = $ret['id'];

        $ret = $this->call('image', 'DELETE', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
    }

    public function exif() {
        return [
          [ 'down' ],
            [ 'down-mirrored' ],
            [ 'left' ],
            [ 'left-mirrored' ],
            [ 'right' ],
            [ 'right-mirrored' ],
            [ 'up' ],
            [ 'up-mirrored' ]
        ];
    }

    public function testExternal() {
        # When testing we use the demo tus server, which is sometimes flaky.
        for ($tries = 0; $tries < 5; $tries++) {
            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
            $t = new Tus();
            $uid = $t->upload(NULL, 'image/jpeg', $data);

            if ($uid) {
                break;
            }

            sleep(10);
        }

        $ret = $this->call('image', 'POST', [
            'externaluid' => $uid,
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $id = $ret['id'];

        // Get it back.  Will redirect but we don't have a good way to capture that in a test.
        $ret = $this->call('image', 'GET', [
            'id' => $id,
        ], FALSE);

        $ret = $this->call('image', 'DELETE', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
    }
}

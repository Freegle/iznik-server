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
        $g = Group::get($this->dbhr, $this->dbhm);
        $group1 = $g->create('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail('test@test.com');
        $u->addMembership($group1);
        $u->setMembershipAtt($group1, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
       list ($id, $failok) = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
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

        $this->assertTrue(strlen($ret) == 1178 || strlen($ret) == 1179);

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100
        ], FALSE);

        $this->assertEquals(2116, strlen($ret));

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100
        ], FALSE);

        $this->assertEquals(2116, strlen($ret));

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100,
            'group' => 1
        ], TRUE);

        $this->log("Expect 1 " . var_export($ret, TRUE));
        $this->assertEquals(1, $ret['ret']);

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100,
            'newsletter' => 1
        ], TRUE);

        $this->assertEquals(1, $ret['ret']);

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
            'identify' => TRUE
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $id = $ret['id'];
        
        # Now rotate.
        $origdata = $this->call('image', 'GET', [
            'id' => $id,
            'w' => 100
        ], FALSE);

        $ret = $this->call('image', 'POST', [
            'id' => $id,
            'rotate' => 90
        ]);

        $this->assertEquals(0, $ret['ret']);

        $newdata = $this->call('image', 'GET', [
            'id' => $id,
            'w' => 100
        ], FALSE);

        $this->log("Lengths " . strlen($origdata) . " vs " . strlen($newdata));
        $this->assertNotEquals($origdata, $newdata);

        $ret = $this->call('image', 'POST', [
            'id' => $id,
            'rotate' => -90
        ]);

        $newdata = $this->call('image', 'GET', [
            'id' => $id,
            'w' => 100
        ], FALSE);

        # Get as a circle.
        $origdata = $this->call('image', 'GET', [
            'id' => $id,
            'w' => 100
        ], FALSE);

        $newdata = $this->call('image', 'GET', [
            'id' => $id,
            'w' => 100,
            'circle' => TRUE
        ], FALSE);
        $this->assertNotEquals($origdata, $newdata);

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
            'identify' => TRUE
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
        $url = 'https://ilovefreegle.org/icon.png';
        $data = file_get_contents($url);

        $ret = $this->call('image', 'POST', [
            'externaluid' => 'uid',
            'externalurl' => $url,
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertNotNull($ret['id']);
        $id = $ret['id'];

        // Get it back.  Will redirect but we don't have a good way to capture that in a test.
        $ret = $this->call('image', 'GET', [
            'id' => $id,
        ], FALSE);

        // ...so double-check the internals.
        $a = new Attachment($this->dbhr, $this->dbhm, $id);
        $this->assertEquals(UPLOADCARE_CDN . "uid/", $a->getPath());

        $ret = $this->call('image', 'DELETE', [
            'id' => $id
        ]);

        $this->assertEquals(0, $ret['ret']);
    }
}

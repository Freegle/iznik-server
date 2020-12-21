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

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
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
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('sourceheader', Message::PLATFORM);

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);

        # One image stripped out due to aspect ratio.
        assertEquals(1, count($ret['message']['attachments']));
        $img1 = $ret['message']['attachments'][0]['id'];

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'w' => 100
        ], FALSE);

        assertTrue(strlen($ret) == 1178 || strlen($ret) == 1179);

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100
        ], FALSE);

        assertEquals(2116, strlen($ret));

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100
        ], FALSE);

        assertEquals(2116, strlen($ret));

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100,
            'group' => 1
        ], TRUE);

        $this->log("Expect 1 " . var_export($ret, TRUE));
        assertEquals(1, $ret['ret']);

        $ret = $this->call('image', 'GET', [
            'id' => $img1,
            'h' => 100,
            'newsletter' => 1
        ], TRUE);

        assertEquals(1, $ret['ret']);

        $a->delete();
        $g->delete();

        }

    public function testPost()
    {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/pan.jpg');
        file_put_contents("/tmp/pan.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/pan.jpg',
                'type' => 'image/jpeg'
            ],
            'identify' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
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

        assertEquals(0, $ret['ret']);

        $newdata = $this->call('image', 'GET', [
            'id' => $id,
            'w' => 100
        ], FALSE);

        $this->log("Lengths " . strlen($origdata) . " vs " . strlen($newdata));
        assertNotEquals($origdata, $newdata);

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
        assertNotEquals($origdata, $newdata);

        $ret = $this->call('image', 'DELETE', [
            'id' => $id
        ]);

        assertEquals(0, $ret['ret']);

        }

    public function testOCR() {
        # We won't have a vision key on Docker.
        if (GOOGLE_VISION_KEY != ' GOOGLE_VISION_KEY') {
            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/giveandtake.jpg');
            file_put_contents("/tmp/giveandtake.jpg", $data);

            $ret = $this->call('image', 'POST', [
                'photo' => [
                    'tmp_name' => '/tmp/giveandtake.jpg',
                    'type' => 'image/jpeg'
                ],
                'ocr' => TRUE
            ]);

            assertTrue(strpos($ret['ocr'], 'ANYONE CAN COME ALONG') !== FALSE);
        }

        assertTrue(TRUE);
    }

    public function testObjects() {
        if (GOOGLE_VISION_KEY != ' GOOGLE_VISION_KEY') {
            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/multiple1.jpg');
            file_put_contents("/tmp/multiple1.jpg", $data);

            $ret = $this->call('image', 'POST', [
                'photo' => [
                    'tmp_name' => '/tmp/multiple1.jpg',
                    'type' => 'image/jpeg'
                ],
                'objects' => TRUE
            ]);

            error_log("Returned " . var_export($ret, TRUE));
            assertEquals(10, count($ret['objects']['responses'][0]['localizedObjectAnnotations']));
        }

        assertTrue(TRUE);
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

        assertEquals(5, $ret['ret']);
    }
}

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
class IncomingMessageTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.email IN ('test@test.com', 'test2@test.com');");
    }

    public function testBasic() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $t = "TestUser" . microtime(true) . "@test.com";
        $msg = str_replace('From: "Test User" <test@test.com>', 'From: "' . $t . '" <test@test.com>', $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('Basic test', $m->getSubject());
        assertEquals($t, $m->getFromname());
        assertEquals('test@test.com', $m->getFromaddr());
        assertEquals('Hey.', $m->getTextbody());
        assertEquals('from@test.com', $m->getEnvelopefrom());
        assertEquals('to@test.com', $m->getEnvelopeto());
        assertEquals("<HTML><HEAD>
<STYLE id=eMClientCss>
blockquote.cite { margin-left: 5px; margin-right: 0px; padding-left: 10px; padding-right:0px; border-left: 1px solid #cccccc }
blockquote.cite2 {margin-left: 5px; margin-right: 0px; padding-left: 10px; padding-right:0px; border-left: 1px solid #cccccc; margin-top: 3px; padding-top: 0px; }
.plain pre, .plain tt { font-family: monospace; font-size: 100%; font-weight: normal; font-style: normal; white-space: pre-wrap; }
a img { border: 0px; }body {font-family: Tahoma;font-size: 12pt;}
.plain pre, .plain tt {font-family: Tahoma;font-size: 12pt;}</STYLE>
</HEAD>
<BODY>Hey.</BODY></HTML>", $m->getHtmlbody());
        assertEquals(0, count($m->getParsedAttachments()));
        assertEquals(Message::TYPE_OTHER, $m->getType());
        assertEquals('FDv2', $m->getSourceheader());

        # Save it
        $id = $m->save();
        assertNotNull($id);

        # Read it back
        unset($m);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('Basic test', $m->getSubject());
        assertEquals('Basic test', $m->getHeader('subject'));
        assertEquals($t, $m->getFromname());
        assertEquals('test@test.com', $m->getFromaddr());
        assertEquals('Hey.', $m->getTextbody());
        assertEquals('from@test.com', $m->getEnvelopefrom());
        assertEquals('to@test.com', $m->getEnvelopeto());
        $m->delete();

        }

    public function testAttachment() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachment');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('MessageMaker', $m->getSourceheader());

        # Check the parsed attachments
        $atts = $m->getParsedAttachments();
        assertEquals(2, count($atts));
        assertEquals('g4g220x194.png', $atts[0]->getFilename());
        assertEquals('image/png', $atts[0]->getContentType());
        assertEquals('g4g160.png', $atts[1]->getFilename());
        assertEquals('image/png', $atts[1]->getContentType());

        # Save it
        $id = $m->save();
        assertNotNull($id);

        # Check the saved attachment.  Only one - other stripped for aspect ratio.
        $atts = $m->getAttachments();
        assertEquals('image/png', $atts[0]->getContentType());
        assertEquals(7975, strlen($atts[0]->getData()));

        # Check the returned attachment.  Only one - other stripped for aspect ratio.
        $atts = $m->getPublic();
        assertEquals(1, count($atts['attachments']));

        $m->delete();

        }

    public function testAttachmentDup() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/attachmentdup');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);

        $id = $m->save();
        assertNotNull($id);

        # Check the returned attachment.  Only one - other stripped for aspect ratio.
        $atts = $m->getPublic();
        assertEquals(1, count($atts['attachments']));

        $m->delete();

        }

    public function testEmbedded() {
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/inlinephoto');
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);

        # Check the parsed inline images.  Should only show one, as dupicate.
        $imgs = $m->getInlineimgs();
        assertEquals(1, count($imgs));

        # Save it and check they show up as attachments
        $id = $m->save();
        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getById($id);
        assertEquals(1, count($atts));

        $m->delete();

        # Test invalid embedded image
        $msg = str_replace("https://www.google.co.uk/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png", "http://google.com", $msg);
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);

        # Check the parsed inline images - should be none
        $imgs = $m->getInlineimgs();
        assertEquals(0, count($imgs));

        # Save it and check they don't show up as attachments
        $id = $m->save();
        $a = new Attachment($this->dbhr, $this->dbhm);
        $atts = $a->getById($id);        
        assertEquals(0, count($atts));

        }

    public function testTN() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/tn'));
        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertEquals('20065945', $m->getTnpostid());

        # Save it
        $id = $m->save();
        assertNotNull($id);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(50.123, $m->getPrivate('lat'));
        assertEquals(-1.234, $m->getPrivate('lng'));

        $m->delete();
    }

    public function testType() {
        assertEquals(Message::TYPE_OFFER, Message::determineType('OFFER: item (location)'));
        assertEquals(Message::TYPE_WANTED, Message::determineType('[Group]WANTED: item'));

        }
}


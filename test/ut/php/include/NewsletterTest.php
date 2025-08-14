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
class newsletterTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $newslettersSent = [];

    protected function setUp() : void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM newsletters WHERE subject = 'UT Newsletter';");

        $this->msgsSent = [];

        $this->tidy();
    }

    public function sendMock($mailer, $message) {
        $this->newslettersSent[] = $message->toString();
    }

    public function testBasic() {
        list($g, $gid) = $this->createTestGroup("testgroup", Group::GROUP_REUSE);

        $n = new Newsletter($this->dbhr, $this->dbhm);
        $id = $n->create($gid, 'UT newsletter', 'UT newsletter text');

        # Add a header and two other articles.
        $n->addArticle(Newsletter::TYPE_HEADER, 0, '<h1>Header</h1>', NULL);
        $n->addArticle(Newsletter::TYPE_ARTICLE, 2, '<p>Article without photo</p>', NULL);

        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_NEWSLETTER);
        list ($attid, $uid) = $a->create(NULL, $data);
        $artid = $n->addArticle(Newsletter::TYPE_ARTICLE, 1, '<p>Article with photo</p>', $attid);
        $a->setPrivate('articleid', $artid);

        # And two users, one who wants newsletters and one who doesn't.
        list($u, $uid1, $eid1) = $this->createTestUser(NULL, NULL, "Test User", 'test1@blackhole.io', 'testpw');
        $u->addMembership($gid, User::ROLE_MEMBER, $eid1);
        $u->setPrivate('newslettersallowed', 0);
        list($u2, $uid2, $eid2) = $this->createTestUser(NULL, NULL, "Test User", 'test2@blackhole.io', 'testpw');
        $u2->addMembership($gid, User::ROLE_MEMBER, $eid2);

        # Now test.
        $this->assertEquals(1, $n->send($gid));

        $this->log("Mail sent" . var_export($this->newslettersSent, TRUE));

        # Turn off
        $n->off($uid2, $gid);

        $this->assertEquals(0, $n->send($gid));

        # Invalid email
        list($u3, $uid3, $eid3) = $this->createTestUser(NULL, NULL, "Test User", 'test.com', 'testpw');
        $u3->addMembership($gid);
        $this->assertEquals(0, $n->send($gid));

        }
}


<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikTestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/group/Facebook.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class groupFacebookTest extends IznikTestCase {
    private $dbhr, $dbhm;

    private $msgsSent = [];

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->tidy();
    }

    private $getException = FALSE;

    public function get() {
        if ($this->getException) {
            throw new Exception ('UT Exception');
        }

        return($this);
    }

    public function getDecodedBody() {
        error_log("getDecoded");
        return([
            'data' => [
                [
                    'id' => 1
                ]
            ]
        ]);
    }

    public function testBasic() {
        error_log(__METHOD__);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_UT);
        error_log("Created group $gid");

        $t = $this->getMockBuilder('GroupFacebook')
            ->setConstructorArgs([ $this->dbhr, $this->dbhm, $gid ])
            ->setMethods(array('getFB'))
            ->getMock();
        $t->method('getFB')->willReturn($this);

        $id = $t->add($gid,
            'test',
        'Test',
        'TestID'
            );

        assertEquals(1, $t->getPostsToShare(1, "last week"));

        $this->getException = TRUE;
        assertEquals(0, $t->getPostsToShare(1, "last week"));

        $t = new GroupFacebook($this->dbhr, $this->dbhm, $id);
        assertEquals($id, $t->getPublic()['uid']);

        assertEquals($id, GroupFacebook::listForGroup($this->dbhr, $this->dbhm, $gid)[0]);
        assertEquals($id, GroupFacebook::listForGroups($this->dbhr, $this->dbhm, [ $gid ])[0]);

        $t->remove($id);
        $t = new GroupFacebook($this->dbhr, $this->dbhm, $id);
        assertNull($t->getPublic()['id']);

        error_log(__METHOD__ . " end");
    }

    public function post() {
        return(TRUE);
    }


    public function testErrors() {
        error_log(__METHOD__);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->findByShortName('FreeglePlayground');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::YAHOO_APPROVED, 'from@test.com', 'to@test.com', $msg);
        list($id, $already) = $m->save();

        $r = new MailRouter($this->dbhr, $this->dbhm, $id);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        error_log("Approved message id $id");

        # Ensure we have consent to see this message
        $a = new Message($this->dbhr, $this->dbhm, $id);
        error_log("From user " . $a->getFromuser());
        $sender = User::get($this->dbhr, $this->dbhm, $a->getFromuser());
        $sender->setPrivate('publishconsent', 1);

        $mock = $this->getMockBuilder('GroupFacebook')
            ->setConstructorArgs([$this->dbhr, $this->dbhm, $gid])
            ->setMethods(array('getFB'))
            ->getMock();

        $mock->method('getFB')->willThrowException(new Exception('Test', 100));

        error_log(__METHOD__ . " end");
    }
}


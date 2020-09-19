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
class worryWordsTest extends IznikTestCase
{
    private $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM worrywords WHERE keyword LIKE 'UTtest%';");
    }

    public function testBasic()
    {
        $this->dbhm->preExec("INSERT INTO worrywords (keyword, type) VALUES (?, ?);", [
            'UTtest1',
            WorryWords::TYPE_REPORTABLE
        ]);

        $w = new WorryWords($this->dbhr, $this->dbhm);

        $m = new Message($this->dbhr, $this->dbhm);
        $mid = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->setPrivate('subject', 'OFFER: fine (Somewhere)');
        $m->setPrivate('textbody', 'A body');
        assertNull($w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody()));

        $m = new Message($this->dbhr, $this->dbhm);
        $mid = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->setPrivate('subject', 'OFFER: UTtest1 (Somewhere)');
        $m->setPrivate('textbody', 'A body');
        assertNotNull($w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody()));

        $this->waitBackground();
        $logs = $this->dbhr->preQuery("SELECT * FROM logs WHERE msgid = ?", [$mid]);
        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_WORRYWORDS, $logs);
        error_log("Found log " . var_export($log, TRUE));

//        $m = new Message($this->dbhr, $this->dbhm);
//        $mid = $m->createDraft();
//        $m = new Message($this->dbhr, $this->dbhm, $mid);
//        $m->setPrivate('subject', 'OFFER: fine (Somewhere)');
//        $m->setPrivate('textbody', "Some text uttest2\r\nMore text");
//        assertNotNull($w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody()));
//
//        $this->waitBackground();
//        $logs = $this->dbhr->preQuery("SELECT * FROM logs WHERE msgid = ?", [$mid]);
//        $log = $this->findLog(Log::TYPE_MESSAGE, Log::SUBTYPE_WORRYWORDS, $logs);
//        error_log("Found log " . var_export($log, TRUE));
    }

    public function testAllowed()
    {
        $this->dbhm->preExec("INSERT INTO worrywords (keyword, type) VALUES (?, ?);", [
            'UTtest1',
            WorryWords::TYPE_ALLOWED
        ]);

        $w = new WorryWords($this->dbhr, $this->dbhm);

        $m = new Message($this->dbhr, $this->dbhm);
        $mid = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm);
        $mid = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm, $mid);
        $m->setPrivate('subject', 'OFFER: UTtest1 (Somewhere)');
        $m->setPrivate('textbody', 'A body');
        assertNull($w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody()));
    }

//
//    public function testEH()
//    {
//        $w = new WorryWords($this->dbhr, $this->dbhm);
//        $m = new Message($this->dbhr, $this->dbhm, 62381003);
//        error_log(
//            var_export($w->checkMessage($m->getID(), $m->getFromuser(), $m->getSubject(), $m->getTextbody()), TRUE)
//        );
//    }
}
<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require(UT_DIR . '/../../include/config.php');
require(UT_DIR . '/../../include/db.php');

use JanuSoftware\Facebook\WebDriver;
use JanuSoftware\Facebook\WebDriver\WebDriverBy;
use JanuSoftware\Facebook\WebDriver\Remote;
use JanuSoftware\Facebook\WebDriver\Remote\DesiredCapabilities;

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
abstract class IznikWebTestCase extends PHPUnit_Framework_TestCase {
    public $dbhr, $dbhm, $driver;

    public function __construct()
    {
        $host = 'http://localhost:4444/wd/hub';
        $this->driver = Remote\RemoteWebDriver::create($host, DesiredCapabilities::chrome());

        # Wait 30s for things to show.
        $this->driver->manage()->timeouts()->implicitlyWait = 30;
    }

    /**
     *
     */
    protected function setUp() : void {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE users, users_emails FROM users INNER JOIN users_emails ON users.id = users_emails.userid WHERE users_emails.backwards LIKE 'oi.elohkcalb%';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort LIKE 'testgroup%';");
    }

    protected function tearDown() : void {
        parent::tearDown ();
        $this->driver->quit();
        @session_destroy();
    }

    public function waitLoad() {
        # Wait for us to fully load our page
        $this->driver->findElement(WebDriver\WebDriverBy::id('bodyContent'));
    }

}


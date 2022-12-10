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
class configAPITest extends IznikAPITestCase {
    public function testBase() {
        $this->dbhm->preExec("DELETE FROM config WHERE `key` = ?;", [
            'UT'
        ]);
        $this->dbhm->preExec("INSERT INTO config (`key`, value) VALUES ('UT', 'Testing')");

        $ret = $this->call('config', 'GET', [
            'key' => 'UT'
        ]);

        $this->assertEquals(0, $ret['ret']);
        $this->assertEquals(1, count($ret['values']));
        $this->assertEquals('Testing', $ret['values'][0]['value']);
    }
}


<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once UT_DIR . '/../../composer/vendor/phpunit/phpunit/src/Framework/TestCase.php';
require_once UT_DIR . '/../../composer/vendor/phpunit/phpunit/src/Framework/Assert/Functions.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class configIncTest extends \PHPUnit\Framework\TestCase {
    public function testConfig() {
        require UT_DIR . '/../../include/config.php';
        require UT_DIR . '/../../include/db.php';
        assertTrue(defined('MMDB'));
    }
}


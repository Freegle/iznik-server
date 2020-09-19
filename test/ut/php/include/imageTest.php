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
class imageTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testNullParams() {
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/Tile.jpg');
        $i = new Image($data);

        $w = $i->width();
        $h = $i->height();

        $i->scale(NULL, NULL);

        assertEquals($w, $i->width());
        assertEquals($h, $i->height());

        $i->scale($w+1, NULL);

        // Rounds up.
        assertEquals($w + 2, $i->width());
        assertEquals($h + 2, $i->height());

        $i->scale(NULL, $h+1);
        assertEquals($w + 2, $i->width());
        assertEquals($h + 1, $i->height());

        }
}


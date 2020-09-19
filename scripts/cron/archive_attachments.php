<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

# We archive chat photos out of the DB.  This reduces load on the servers because we don't have to serve
# the images up, and it also reduces the disk space we need within the DB (which is not an ideal
# place to store large amounts of image data);
$sql = "SELECT id FROM chat_images WHERE archived = 0;";
$atts = $dbhr->preQuery($sql);
error_log(count($atts) . " chat images to archive");
$count = 0;
$total = count($atts);

foreach ($atts as $att) {
    $a = new Attachment($dbhr, $dbhm, $att['id'], Attachment::TYPE_CHAT_MESSAGE);
    if (!$a->archive()) {
        error_log("Failed to archive {$att['id']}");
        //$a->delete();
    }

    $count++;
    error_log("...$count / $total");
}

$sql = "SELECT id FROM communityevents_images WHERE archived = 0;";
$atts = $dbhr->preQuery($sql);
error_log(count($atts) . " event images to archive");
$count = 0;
$total = count($atts);

foreach ($atts as $att) {
    $a = new Attachment($dbhr, $dbhm, $att['id'], Attachment::TYPE_COMMUNITY_EVENT);
    if (!$a->archive()) {
        error_log("Failed to archive {$att['id']}");
        //$a->delete();
    }

    $count++;
    error_log("...$count / $total");
}

$sql = "SELECT id FROM newsfeed_images WHERE archived = 0;";
$atts = $dbhr->preQuery($sql);
error_log(count($atts) . " newsfeed images to archive");
$count = 0;
$total = count($atts);

foreach ($atts as $att) {
    $a = new Attachment($dbhr, $dbhm, $att['id'], Attachment::TYPE_NEWSFEED);
    if (!$a->archive()) {
        error_log("Failed to archive {$att['id']}");
        //$a->delete();
    }

    $count++;
    error_log("...$count / $total");
}

# We shrink profile pictures down to 200x200.
$pics = $dbhr->preQuery("SELECT * FROM users_images WHERE data IS NOT NULL AND LENGTH(data) > 50000;");
error_log("Check " . count($pics) . " profile pictures");
foreach ($pics as $pic) {
    try {
        $i = new Image($pic['data']);
        $i->scale(200, 200);
        $d = $i->getData(100);
        if (strlen($d) < strlen($pic['data'])) {
            error_log("{$pic['id']} " . strlen($pic['data']) . " => " . strlen($d));
            $dbhm->preExec("UPDATE users_images SET data = ? WHERE id = ?;", [
                $d,
                $pic['id']
            ]);
        }
    } catch (\Exception $e) {
        error_log($e->getMessage());
    }
}

# We delete older profile images.  This is because the upload function does an INSERT.
$dups = $dbhr->preQuery("SELECT userid, MAX(id) AS max, COUNT(*) AS count FROM `users_images` GROUP BY userid HAVING count > 1;");
foreach ($dups as $dup) {
    $dbhm->preExec("DELETE FROM users_images WHERE userid = ? AND id < ?;", [
        $dup['userid'],
        $dup['max']
    ]);
}

# We archive message photos out of the DB.  This reduces load on the servers because we don't have to serve
# the images up, and it also reduces the disk space we need within the DB (which is not an ideal
# place to store large amounts of image data);
$sql = "SELECT id, contenttype FROM messages_attachments WHERE archived = 0;";
$atts = $dbhr->preQuery($sql);
error_log(count($atts) . " to archive");
$count = 0;
$total = count($atts);

foreach ($atts as $att) {
    $a = new Attachment($dbhr, $dbhm, $att['id']);
    if (!$a->archive()) {
        error_log("Failed to archive {$att['id']}");
        //$a->delete();
    }

    $count++;
    error_log("...$count / $total");
}

$sql = "SELECT id FROM booktastic_images WHERE archived = 0;";
$atts = $dbhr->preQuery($sql);
error_log(count($atts) . " book images to archive");
$count = 0;
$total = count($atts);

foreach ($atts as $att) {
    $a = new Attachment($dbhr, $dbhm, $att['id'], Attachment::TYPE_BOOKTASTIC);
    if (!$a->archive()) {
        error_log("Failed to archive {$att['id']}");
        //$a->delete();
    }

    $count++;
    error_log("...$count / $total");
}

Utils::unlockScript($lockh);
<?php
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# We may archive off images to our CDN which belong to messages we later delete.  There is no need to keep those.
$lockh = Utils::lockScript(basename(__FILE__));
$path = "/var/www/iznik/images";
$msgdeleted = 0;
$msgretained = 0;
$msgtotal = 0;
$chatdeleted = 0;
$chatretained = 0;
$chattotal = 0;

# Shuffle in case we bomb out partway through, then at least we'll have done something on each a lot of the time.
$hosts = [CDN_HOST_1, CDN_HOST_2];

foreach ($hosts as $host) {
    $connection = ssh2_connect($host, 22);

    if ($connection) {
        if (ssh2_auth_pubkey_file($connection, CDN_SSH_USER,
            CDN_SSH_PUBLIC_KEY,
            CDN_SSH_PRIVATE_KEY)) {
            $sftp = ssh2_sftp($connection);
            $sftp_fd = intval($sftp);

            $handle = opendir("ssh2.sftp://$sftp_fd$path");

            while (false != ($entry = readdir($handle))){
                if (preg_match('/^(timg|img)_(.*)\./', $entry, $matches)) {
                    $mid = $matches[2];
                    $msgs = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE id = ?;", [
                        $mid
                    ]);

                    $msgtotal++;

                    if (count($msgs)) {
//                        error_log("$mid exists");
                        $msgretained++;
                    } else {
                        #error_log("Remove message image $entry for $mid");
                        ssh2_sftp_unlink($sftp, "$path/$entry");
                        $msgdeleted++;
                    }

                    if ($msgtotal % 1000 === 0) {
                        error_log("...message images $msgtotal (deleted $msgdeleted)");
                    }
                } else if (preg_match('/^(tmimg|mimg)_(.*)\./', $entry, $matches)) {
                    $mid = $matches[2];
                    $msgs = $dbhr->preQuery("SELECT id FROM chat_images WHERE id = ?;", [
                        $mid
                    ]);

                    $chattotal++;

                    if (count($msgs)) {
//                        error_log("$mid exists");
                        $chatretained++;
                    } else {
                        #error_log("Remove chat image $entry for $mid");
                        ssh2_sftp_unlink($sftp, "$path/$entry");
                        $chatdeleted++;
                    }

                    if ($chattotal % 1000 === 0) {
                        error_log("...chat images $chattotal (deleted $chatdeleted)");
                    }
                }
            }

            closedir($handle);
        }
    }
}

mail(GEEKS_ADDR, "CDN pruned $msgtotal ($msgdeleted), $chattotal ($chatdeleted)", "");

Utils::unlockScript($lockh);
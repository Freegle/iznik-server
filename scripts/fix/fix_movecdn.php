<?php

use WindowsAzure\Common\ServicesBuilder;
use MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/misc/Image.php');

function getAzure() {
    return(ServicesBuilder::getInstance()->createBlobService(AZURE_CONNECTION_STRING));
}

if (TRUE) {
    $atts = $dbhr->preQuery("SELECT id FROM chat_images WHERE archived = 1;");
    error_log("Chat " . count($atts));
    $attcount = 0;

    $blobClient = getAzure();

    foreach ($atts as $att) {
        try {
            $fn = "mimg_{$att['id']}.jpg";

            if (!file_exists("/tmp/images/$fn") || !file_exists("/tmp/images/t$fn")) {
                $getBlobResult = $blobClient->getBlob("images", $fn);
                $data = $getBlobResult->getContentStream();
                if ($data) {
                    file_put_contents("/tmp/images/$fn", $data);

                    # Create thumbnail - saves fetching again.
                    $data = file_get_contents("/tmp/images/$fn");
                    $i = new Image($data);
                    if ($i->img) {
                        $i->scale(250, 250);
                        $thumbdata = $i->getData(100);
                        file_put_contents("/tmp/images/t$fn", $thumbdata);
                    }
                }
            }
        } catch (\Exception $e) {
            $code = $e->getCode();
            $error_message = $e->getMessage();
            echo $code.": ".$error_message.PHP_EOL;
        }

        $attcount++;

        if ($attcount % 100 === 0) {
            error_log("...$attcount / " . count($atts));
        }
    }
}

if (TRUE) {
    $atts = $dbhr->preQuery("SELECT id FROM newsfeed_images WHERE archived = 1;");
    error_log("Newsfeed " . count($atts));
    $attcount = 0;

    $blobClient = getAzure();

    foreach ($atts as $att) {
        try {
            $fn = "fimg_{$att['id']}.jpg";

            if (!file_exists("/tmp/images/$fn") || !file_exists("/tmp/images/t$fn")) {
                $getBlobResult = $blobClient->getBlob("images", $fn);
                $data = $getBlobResult->getContentStream();

                if ($data) {
                    file_put_contents("/tmp/images/$fn", $data);

                    # Create thumbnail - saves fetching again.
                    $data = file_get_contents("/tmp/images/$fn");
                    $i = new Image($data);
                    if ($i->img) {
                        $i->scale(250, 250);
                        $thumbdata = $i->getData(100);
                        file_put_contents("/tmp/images/t$fn", $thumbdata);
                    }
                }
            }
        } catch (\Exception $e) {
            $code = $e->getCode();
            $error_message = $e->getMessage();
            echo $code.": ".$error_message.PHP_EOL;
        }

        $attcount++;

        if ($attcount % 100 === 0) {
            error_log("...$attcount / " . count($atts));
        }
    }
}

if (TRUE) {
    $start = '2020-03-01';
    $msgs = $dbhr->preQuery("SELECT id FROM messages WHERE arrival >= '$start' ORDER BY arrival DESC;");
    error_log("Messages " . count($msgs));
    $attcount = 0;
    $msgcount = 0;

    $blobClient = getAzure();

    foreach ($msgs as $msg) {
        $atts = $dbhr->preQuery("SELECT id FROM messages_attachments WHERE msgid = ? AND archived = 1;", [
            $msg['id'],
        ]);

        foreach ($atts as $att) {
            $attcount++;

            try {
                $fn = "img_{$att['id']}.jpg";
                if (!file_exists("/tmp/images/$fn") || !file_exists("/tmp/images/t$fn")) {
                    $getBlobResult = $blobClient->getBlob("images", $fn);
                    $data = $getBlobResult->getContentStream();

                    if ($data) {
                        file_put_contents("/tmp/images/$fn", $data);

                        # Create thumbnail - saves fetching again.
                        $data = file_get_contents("/tmp/images/$fn");
                        $i = new Image($data);
                        if ($i->img) {
                            $i->scale(250, 250);
                            $thumbdata = $i->getData(100);
                            file_put_contents("/tmp/images/t$fn", $thumbdata);
                        }
                    }
                }
            } catch (\Exception $e) {
                $code = $e->getCode();
                $error_message = $e->getMessage();
                echo $code.": ".$error_message.PHP_EOL;
            }
        }

        $msgcount++;

        if ($msgcount % 1000 === 0) {
            error_log("...$msgcount / " . count($msgs));
        }
    }
}

error_log("Attachments $attcount");



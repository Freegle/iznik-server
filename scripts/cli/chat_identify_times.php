<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$msgs = $dbhr->preQuery("SELECT * FROM chat_messages WHERE date >= '2020-12-22' AND message IS NOT NULL AND type IN ('Default', 'Interested') LIMIT 1000");

function validNER($date)
{
    if (strpos($date, '_REF') !== FALSE) {
        #error_log("$date is ref");
        return FALSE;
    }

    $d = \DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));

    if (!$d) {
        #error_log("$date invalid");
        return FALSE;
    }

    $formatted = $d->format('Y-m-d');

    if (strpos($date, $formatted) !== 0) {
        #error_log("$date format");
        return FALSE;
    }

    if ($formatted < (new \DateTime())->format('Y-m-d')) {
        #error_log("$date past");
        return FALSE;
    }

    return TRUE;
}

function validDate($date) {
    if (strpos(strtolower($date), 'christmas') !== FALSE ||
        strpos(strtolower($date), 'xmas') !== FALSE ||
        strpos(strtolower($date), 'new year') !== FALSE ||
        strpos(strtolower($date), 'easter') !== FALSE
    ) {
        #error_log("$date festival");
        return FALSE;
    }

    return TRUE;
}

foreach ($msgs as $msg) {
    $curl = curl_init();
    $url = 'http://localhost:9123?properties=' . urlencode(    json_encode(
                                                                   [
                                                                       'annotators' => "tokenize,ssplit,pos,ner",
                                                                       "ner.docdate.usePresent" => "true",
                                                                       "outputFormat" => "json"
                                                                   ]
                                                               )
        );
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt(
        $curl,
        CURLOPT_POSTFIELDS,
        $msg['message']
    );

    $json_response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($status == 200) {
        $found = FALSE;
        $rsp = json_decode($json_response, TRUE);
        foreach ($rsp['sentences'] as $sentence) {
            foreach ($sentence['entitymentions'] as $entitymention) {
                #error_log(json_encode($entitymention));
                if (Utils::presdef('ner', $entitymention, NULL) == 'DATE') {
                    #error_log("--- possible date");
                    if (
                        validNER(Utils::pres('normalizedNER', $entitymention)) &&
                        validDate($entitymention['text'])
                    ) {
                        #error_log($msg['message']);
                        error_log("..." . $entitymention['text'] . " = " . $entitymention['normalizedNER']
                        #          . " " . json_encode($entitymention)
                        );

                        $found = TRUE;
                    }
                }
            }
        }

        if ($found) {
            error_log(" from {$msg['message']}");
        }
    }
}

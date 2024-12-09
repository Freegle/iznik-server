<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$results = [];

#$newsfeeds = $dbhr->preQuery("SELECT * FROM newsfeed WHERE message IS NOT NULL ORDER BY id DESC LIMIT 100;");
$newsfeeds = $dbhr->preQuery("SELECT * FROM chat_messages WHERE message IS NOT NULL ORDER BY id DESC LIMIT 100;");

foreach ($newsfeeds as $newsfeed) {
    do {
        $sleep = FALSE;

        try {
            $commentsClient = new \PerspectiveApi\CommentsClient(GOOGLE_PERSPECTIVE_KEY);
            $commentsClient->comment(['text' => $newsfeed['message']]);
            $commentsClient->languages(['en']);
            $commentsClient->context(['entries' => ['text' => 'off-topic', 'type' => 'PLAIN_TEXT']]);
            $commentsClient->requestedAttributes(['TOXICITY' => ['scoreType' => 'PROBABILITY', 'scoreThreshold' => 0]]);
            $response = $commentsClient->analyze();
            $tox = $response->attributeScores()['TOXICITY']['summaryScore']['value'];
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Quota exceeded')  !== FALSE) {
                #error_log("...quota exceeded");
                $sleep = TRUE;
            } else {
                throw $e;
            }
        }

        if ($sleep) {
            sleep(1);
        }
    } while ($sleep);

    if ($tox > 0.3) {
        #error_log("$tox for " . $newsfeed['id'] . " " . $newsfeed['message']);
        $results[] = [
            'id' => $newsfeed['id'],
            'message' => $newsfeed['message'],
            'tox' => $tox
        ];
    }
}

# Sort ascending by tox
usort($results, function($a, $b) {
    return $b['tox'] - $a['tox'];
});

foreach ($results as $result) {
    echo ($result['tox'] * 100) . " " . $result['id'] . " " . $result['message'] . "\n";
}

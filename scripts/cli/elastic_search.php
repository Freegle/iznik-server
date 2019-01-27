<?php

# Index recent messages into ElasticSearch.
use Elasticsearch\ClientBuilder;

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/message/Message.php');

$client = ClientBuilder::create()
    ->setHosts([
        'db-1.ilovefreegle.org:9200'
    ])
    ->build();

$opts = getopt('s:');

if (count($opts) < 1) {
    echo "Usage: php elastic_search -s <subject>\n";
} else {
    $subject = $opts['s'];

    $params = [
        'index' => 'iznik',
        'type' => 'messages'
    ];

    if ($subject) {
        $params['body']['query']['fuzzy']['subject'] = $subject;
    }

    $results = $client->search($params);
    error_log("Returned " . var_export($results, TRUE));
}


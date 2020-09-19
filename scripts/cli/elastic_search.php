<?php

# Index recent messages into ElasticSearch.
namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

use Elasticsearch\ClientBuilder;


$client = ClientBuilder::create()
    ->setHosts([
        'bulk3.ilovefreegle.org:9200'
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


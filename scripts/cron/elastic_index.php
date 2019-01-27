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

try {
    $client->indices()->delete([
        'index' => 'messages'
    ]);
} catch (Exception $e) {
    error_log("Delete index failed with " . $e->getMessage());
}

# Add the index
$params = [
    'index' => 'iznik',
    'body' => [
        'mappings' => [
            'messages' => [
                '_source' => [
                    'enabled' => TRUE
                ],
                'properties' => [
                    'subject' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ],
                    'textbody' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ],
                    'arrival' => [
                        'type' => 'date',
                        'format' => "YYYY-MM-DD'T'HH:mm:ssZ"
                    ],
                    'msgtype' => [
                        'type' => 'short'
                    ],
                    'location' => [
                        'type' => 'geo_point'
                    ]
                ]
            ]
        ]
    ]
];

try {
    $response = $client->indices()->create($params);
    error_log("Created index " . var_export($response, TRUE));
} catch (Exception $e) {
    error_log("Create index failed with " . $e->getMessage());
}

$opts = getopt('s:');

if (count($opts) < 1) {
    echo "Usage: php elastic_index -s <since when>\n";
} else {
    $since = $opts['s'];
    $mysqltime = date("Y-m-d H:i:s", strtotime($since));

    error_log("Find messages...");
    $messages = $dbhr->preQuery("SELECT DISTINCT messages.id, messages_groups.arrival FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id WHERE messages_groups.arrival >= '$mysqltime' AND messages_groups.collection = ? AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL LIMIT 100;", [
        MessageCollection::APPROVED
    ], FALSE, FALSE);
    error_log("..." . count($messages));

    foreach ($messages as $message) {
        $m = new Message($dbhr, $dbhm, $message['id']);

        $params = [
            'index' => 'iznik',
            'type' => 'messages',
            'id' => $m->getID(),
            'body' => [
                'subject' => $m->getSubject(),
                'textbody' => $m->getTextbody(),
                'arrival' => ISODate($message['arrival']),
                'msgtype' => $m->getType() == Message::TYPE_OFFER ? 0 : 1,
                'location' => [
                    'lat' => $m->getPrivate('lat'),
                    'lon' => $m->getPrivate('lng')
                ]
            ]
        ];

        error_log("Create with params " . var_export($params, TRUE));
        $response = $client->index($params);
        error_log("Indexed message " . var_export($response, TRUE));
    }
}

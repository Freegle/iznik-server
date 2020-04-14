<?php

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/db.php');

use Elasticsearch\ClientBuilder;

$client = ClientBuilder::create()
    ->setHosts([
        'db-1.ilovefreegle.org:9200'
    ])
    ->build();

//try {
//    # Delete any old index
//    $client->indices()->delete([
//        'index' => 'booktastic'
//    ]);
//} catch (Exception $e) {
//
//}

# Add the index
$params = [
    'index' => 'booktastic',
    'body' => [
        'mappings' => [
            'books' => [
                '_source' => [
                    'enabled' => TRUE
                ],
                'properties' => [
                    'vaifid' => [
                        'type' => 'text'
                    ],

                    'author' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ],
                    'title' => [
                        'type' => 'text',
                        'analyzer' => 'english'
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

$handle = fopen("/tmp/uk.csv", "r");
$count = 0;

do {
    $csv = fgetcsv($handle);

    if ($csv) {
        # Author is in format lastname, rest, whereas we want a different format.
        $vaifid = $csv[0];
        $author = $csv[1];
        $title = $csv[2];

        #error_log("Initial author $author");
        $p = strpos($author, ',');
        $q = strpos($author, ',', $p + 1);

        if ($q !== -1) {
            # Extra info, e.g. dates - remove.
            $author = trim(substr($author, 0, $q));
        }

        if (preg_match('/(.*)\(/', $author, $matches)) {
            # Extra info - remove.
            $author = trim($matches[1]);
        }

        #error_log("Remove info $author");

        if ($p !== FALSE) {
            $author = trim(substr($author, $p + 1)) . " " . trim(substr($author, 0, $p));
            #error_log("Reorder $author");
        }

        # Check if already there.
        $already = $client->search([
            'index' => 'booktastic',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            'match' => [
                                'author' => $author
                            ],
                            'match' => [
                                'title' => $title
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        #error_log("Already returned " . var_export($already, TRUE));

        if ($already['hits']['total'] === 0) {
            #error_log("Not there");
            $params = [
                'index' => 'booktastic',
                'body' => [
                    'vaifid' => $vaifid,
                    'author' => $author,
                    'title' => $title
                ]
            ];

            #error_log("Create with params " . var_export($params, TRUE));
            #error_log("$author");
            $response = $client->index($params);
        } else {
            # Ignore, already there.
        }

        $count++;

        if ($count % 1000 == 0) {
            error_log("...$count");
        }
        #error_log("Indexed message " . var_export($response, TRUE));
    }
} while ($csv);

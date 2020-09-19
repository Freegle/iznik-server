<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

const CLEAN = FALSE;

use Elasticsearch\ClientBuilder;

$client = ClientBuilder::create()
    ->setHosts([
        'bulk3.ilovefreegle.org:9200'
//    'localhost:9200'
    ])
    ->build();

if (CLEAN) {
    try {
        # Delete any old index
        error_log("Delete old");
        $client->indices()->delete([
            'index' => 'booktastic'
        ]);
        error_log("Deleted");
    } catch (\Exception $e) {
        error_log("Delete failed " . $e->getMessage());
    }

    # Add the index
    $params = [
        'index' => 'booktastic',
        'body' => [
            'settings' => [
                'analysis' => [
                    'normalizer' => [
                        'my_normalizer' => [
                            'type' => 'custom',
                            'filter' => ['lowercase']
                        ]
                    ]
                ]
            ],
            'mappings' => [
                '_source' => [
                    'enabled' => TRUE
                ],
                'properties' => [
                    'viafid' => [
                        'type' => 'keyword'
                    ],
                    'author' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer',
                        'split_queries_on_whitespace' => TRUE
                    ],
                    'title' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer',
                        'split_queries_on_whitespace' => TRUE
                    ],
                    'normalauthor' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer',
                        'split_queries_on_whitespace' => TRUE
                    ],
                    'normaltitle' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer',
                        'split_queries_on_whitespace' => TRUE
                    ],
                ]
            ]
        ]
    ];

    try {
        error_log("Create index");
        $response = $client->indices()->create($params);
        error_log("Created index " . var_export($response, TRUE));
    } catch (\Exception $e) {
        error_log("Create index failed with " . $e->getMessage());
    }
}

$addParamsInitial = [
    'body' => []
];

$addParams = $addParamsInitial;
$c = new Catalogue($dbhr, $dbhm);

function addOne($client, $viafid, $author, $title, &$count) {
    global $addParams, $addParamsInitial, $c;

    $addParams['body'][] = [
        'index' => [
            '_index' => 'booktastic',
        ]
    ];

    $addParams['body'][] = [
        'viafid' => $viafid,
        'normalauthor' => $c->normaliseAuthor($author),
        'normaltitle' => $c->normaliseTitle($title),
        'author' => $author,
        'title' => $title,
    ];

    $count++;

    if ($count % 1000 == 0) {
        $response = $client->bulk($addParams);
        $addParams = $addParamsInitial;
        error_log("...$count");
    }
}

$opts = getopt('f:');

$handle = fopen($opts['f'], "r");
$count = 0;

do {
    $csv = fgetcsv($handle);

    if ($csv) {
        # Author is in format lastname, rest, whereas we want a different format.
        $viafid = $csv[0];
        $author = $csv[1];
        $title = $csv[2];

        $p = strpos($author, ',');
        $q = strpos($author, ',', $p + 1);

        if ($q != FALSE) {
            # Extra info, e.g. dates - remove.
            $author = trim(substr($author, 0, $q));
        }

        if (preg_match('/(.*?)\(/', $author, $matches)) {
            # Extra info - remove.
            $author = trim($matches[1]);
        }

        if ($p !== FALSE) {
            $author = trim(substr($author, $p + 1)) . " " . trim(substr($author, 0, $p));
        }

        # Only interested in books with latin characters for now.  Asterix, etc.
        $latinReg = '/[^\\p{Common}\\p{Latin}]/u';
        $stitle = $c->normaliseTitle($title, TRUE);

        if (!preg_match($latinReg, $author) && !preg_match($latinReg, $title)) {
            if  (!strlen($stitle)) {
                addOne($client, $viafid, $author, $title, $count);
            }
        }
    }
} while ($csv);

if (count($addParams['body'])) {
    $client->bulk($addParams);
}

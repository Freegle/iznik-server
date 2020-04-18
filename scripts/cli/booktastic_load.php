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
            'books' => [
                '_source' => [
                    'enabled' => TRUE
                ],
                'properties' => [
                    'viafid' => [
                        'type' => 'keyword'
                    ],
                    'author' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer'
                    ],
                    'title' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer'
                    ],
                    'sortedauthor' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer'
                    ],
                    'sortedtitle' => [
                        'type' => 'keyword',
                        'normalizer' => 'my_normalizer'
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

$opts = getopt('f:');

$handle = fopen($opts['f'], "r");
$count = 0;

function sortstring($string,$unique = false) {
    $string = str_replace('.', '', $string);
    $array = explode(' ',strtolower($string));
    if ($unique) $array = array_unique($array);
    sort($array);
    return implode(' ',$array);
}

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
        $addit = TRUE;

        if ($already['hits']['total'] > 0) {
            # Might already be there.  Check if this is an exact match.
            foreach ($already['hits']['hits'] as $hit) {
                #error_log("May already be there " . var_export($already['hits'], TRUE));
                $hitauthor = strtolower($hit['_source']['author']);
                $hittitle = strtolower($hit['_source']['title']);

                if (!strcmp(strtolower($author), $hitauthor) && !strcmp(strtolower($title), $hittitle)) {
                    #error_log("Already there");
                    $addit = FALSE;
                } else {
                    #error_log("False match $author - $title to $hitauthor - $hittitle");
                }
            }
        }

        if ($addit) {
            #error_log("Not there");
            $params = [
                'index' => 'booktastic',
                'body' => [
                    'viafid' => $viafid,
                    'author' => $author,
                    'title' => $title,
                    'sortedauthor' => sortstring($author),
                    'sortedtitle' => sortstring($title)
                ]
            ];

            #error_log("Create with params " . var_export($params, TRUE));
            #error_log("$author");
            $response = $client->index($params);
            #error_log("Add returned " . var_export($response, TRUE));
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

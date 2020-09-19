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
    ])
    ->build();

$params = [
    'index' => 'names',
    'body' => [
        'mappings' => [
            '_source' => [
                'enabled' => TRUE
            ],
            'properties' => [
                'word' => [
                    'type' => 'keyword'
                ]
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

$params = [
    'index' => 'words',
    'body' => [
        'mappings' => [
            '_source' => [
                'enabled' => TRUE
            ],
            'properties' => [
                'word' => [
                    'type' => 'keyword'
                ]
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

$opts = getopt('f:');

function addOne($client, &$add, $index, $word, $freq, &$count) {
    $add['body'][] = [
        'index' => [
            '_index' => $index,
        ]
    ];

    $add['body'][] = [
        'word' => $word,
        'frequency' => $freq
    ];

    $count++;

    if ($count % 1000 == 0) {
        $response = $client->bulk($add);
        $add = [
            'body' => []
        ];
        error_log("...$count");
    }
}

$handle = fopen($opts['f'], "r");
$count = 0;
$namelist = [];
$wordlist = [];

function addWords(&$list, $str) {
    $words = explode(' ', strtolower($str));

    foreach ($words as $word) {
        if (Utils::pres($word, $list)) {
            $list[$word]++;
        } else {
            $list[$word] = 1;
        }
    }
}

$count = 0;

do {
    $csv = fgetcsv($handle);

    if ($csv) {
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

        addWords($wordlist, $title);
        addWords($namelist, $author);

        $count++;
        if ($count % 1000 == 0) {
            error_log("...$count, " . count($wordlist) . ", " . count($namelist));
        }
    }
} while ($csv);

$count = 0;
$wordadd = [
    'body' => []
];

foreach ($wordlist as $word => $freq) {
    addOne($client, $wordadd, 'words', $word, $freq, $count);
}

if (count($wordadd['body'])) {
    $client->bulk($wordadd);
}

$count = 0;
$nameadd = [
    'body' => []
];

foreach ($namelist as $word => $freq) {
    addOne($client, $nameadd, 'names', $word, $freq, $count);
}

if (count($nameadd['body'])) {
    $client->bulk($nameadd);
}

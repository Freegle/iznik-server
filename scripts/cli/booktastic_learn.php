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
        'elastic1:9200'
    ])
    ->build();

$c = new Catalogue($dbhr, $dbhm);

# Our initial DB was VIAF.  ISBNDB has lots more.  Find the ones in ISBNDB which we don't have.
$isbndb = $dbhr->preQuery("SELECT * FROM booktastic_isbndb;");

$newones = [];

foreach ($isbndb as $isbn) {
    if (Utils::pres('results', $isbn)) {
        $res = json_decode($isbn['results'], TRUE);

        if ($res) {
            foreach ($res['books'] as $book) {
                foreach (array_unique($book['authors']) as $author) {
                    $title = $book['title'];

                    if (@levenshtein($author, $isbn['author']) < 2) {
                        $normauthor = $c->normaliseAuthor($author);
                        $normtitle = $c->normaliseTitle($title);

                        if (!Utils::pres("$normauthor - $normtitle", $newones)) {
                            $res = $client->search([
                                'index' => 'booktastic',
                                'body' => [
                                    'query' => [
                                        'bool' => [
                                            'must' => [
                                                [ 'match' => [ 'normalauthor' => $normauthor ] ],
                                            ],
                                            'must' => [
                                                [ 'match' => [ 'normaltitle' => $normtitle ] ],
                                            ]
                                        ]
                                    ]
                                ],
                                'size' => 1,
                            ]);

                            $found = count($res['hits']['hits']) > 0;

                            if (!$found) {
                                #error_log("Not got $author - $title, searched $normauthor, $normtitle");
                                $newones["$normauthor - $normtitle"] = [ $author, $title ];
                            }
                        }
                    }
                }
            }
        }
    }
}

foreach ($newones as $k => $v) {
    list ($author, $title) = $v;
    error_log("$author - $title");
}
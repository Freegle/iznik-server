<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatMessage.php');


use Phpml\Dataset\CsvDataset;
use Phpml\Dataset\ArrayDataset;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WordTokenizer;
use Phpml\CrossValidation\StratifiedRandomSplit;
use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\Metric\Accuracy;
use Phpml\Classification\SVC;
use Phpml\SupportVectorMachine\Kernel;
use Phpml\Classification\NaiveBayes;

const CORPUS_SIZE = 1000;

function getTextForUser($dbhr, $userid) {
    $msg = '';

    $chatmsgs = $dbhr->preQuery("SELECT DISTINCT message FROM chat_messages WHERE userid = ? AND message IS NOT NULL AND type = ?;", [
        $userid,
        ChatMessage::TYPE_INTERESTED
    ]);

    foreach ($chatmsgs as $chatmsg) {
        $msg .= " {$chatmsg['message']}";
    }

    #error_log("Text for $userid is $msg");
    return($msg);
}

$vectorizer = new TokenCountVectorizer(new WordTokenizer());
$tfIdfTransformer = new TfIdfTransformer();

$samples = [];
$labels = [];
$users = [];

# Reneges are best guess for users who haven't worked out.
$reneges = $dbhr->preQuery("SELECT messages_reneged.userid FROM messages_reneged LIMIT " . CORPUS_SIZE . ";");
foreach ($reneges as $renege) {
    $text = getTextForUser($dbhr, $renege['userid']);

    if (strlen($text)) {
        $samples[] = $text;
        $labels[] = 'Down';
        $users[] = $renege['userid'];
    }
}

error_log(count($reneges) . " reneges");

# Outcomes tells us users who have worked out.
$nonreneges = $dbhr->preQuery("SELECT userid FROM messages_outcomes WHERE userid IS NOT NULL ORDER BY id DESC LIMIT " . CORPUS_SIZE . ";");

foreach ($nonreneges as $nonrenege) {
    $text = getTextForUser($dbhr, $renege['userid']);

    if (strlen($text)) {
        $samples[] = $text;
        $labels[] = 'Up';
        $users[] = $renege['userid'];
    }
}

error_log(count($nonreneges) . " non-reneges");

error_log(count($samples) . " samples");

#error_log("Samples " . var_export($samples, TRUE));
#error_log("Labels " . var_export($labels, TRUE));

error_log("...vector fit train");
$vectorizer->fit($samples);
error_log("...vector transform train");
$vectorizer->transform($samples);

error_log("...TFIDF fit train");
$tfIdfTransformer->fit($samples);
error_log("...TFIDF transform train");
$tfIdfTransformer->transform($samples);

$dataset = new ArrayDataset($samples, $labels);
#error_log("...split");
//$randomSplit = new StratifiedRandomSplit($dataset, 0.99);

$classifier = new SVC(Kernel::RBF, 10000);

error_log("...train");
//$classifier->train($randomSplit->getTrainSamples(), $randomSplit->getTrainLabels());
$classifier->train($samples, $labels);
//error_log("...predict");
//$predicton = $randomSplit->getTestSamples();
//$predictedLabels = $classifier->predict($predicton);
//
//error_log('Accuracy: '.Accuracy::score($randomSplit->getTestLabels(), $predictedLabels));

# Now test against user feedback
$samples = [];
$labels = [];
$users = [];

$ratings = $dbhr->preQuery("SELECT * FROM ratings;");
foreach ($ratings as $rating) {
    # Find all the text from this user.
    $text = getTextForUser($dbhr, $rating['ratee']);

    if (strlen($text)) {
        error_log("Sample for {$rating['ratee']} {$rating['rating']} $text");
        $users[] = $rating['ratee'];
        $samples[] = $text;
        $labels[] = $rating['rating'];
    }
}

$wrong = 0;
$badlywrong = 0;
$right = 0;
$up = 0;
$down = 0;

error_log(count($samples) . " ratings to test");

$vectorizer = new TokenCountVectorizer(new WordTokenizer());
$tfIdfTransformer = new TfIdfTransformer();

error_log("...vector transform fit");
$vectorizer->fit($samples);
error_log("...vector transform predict");
$vectorizer->transform($samples);

error_log("...TFIDF fit predict");
$tfIdfTransformer->fit($samples);
error_log("...TFIDF transform predict");
$tfIdfTransformer->transform($samples);

error_log("...predict");
$predicts = $classifier->predict($samples);

for ($i = 0; $i < count($samples); $i++) {
    $pred = $predicts[$i];

    if ($pred === 'Up') {
        $up++;
    } else {
        $down++;
    }

    $ratings = $dbhr->preQuery("SELECT * FROM ratings WHERE ratee = ?;", [
        $users[$i]
    ]);

    $verdict = 0;
    foreach ($ratings as $rating) {
        #error_log("...rated {$rating['rating']} by {$rating['rater']}");
        if ($rating['rating'] === 'Up') {
            $verdict++;
        } else {
            $verdict--;
        }
    }

    if ($verdict > 0 && $pred === 'Down') {
        error_log("User {$users[$i]} predict wrong Down but ratings say Up");
        $wrong++;
        $badlywrong++;
    } else if ($verdict < 0 && $pred === 'Up') {
        error_log("User {$users[$i]} predict wrong Up but ratings say Down");
        $wrong++;
    } else {
        $right++;
    }
}

error_log("\n\nPredicted Up $up Down $down, wrong $wrong right $right badly wrong $badlywrong (" . (100 * $badlywrong / ($wrong + $right)) . "%)");
<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');


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

const CORPUS_SIZE = 5000;

$vectorizer = new TokenCountVectorizer(new WordTokenizer());
$tfIdfTransformer = new TfIdfTransformer();

$samples = [];
$labels = [];

$reneges = $dbhr->preQuery("SELECT message FROM messages_reneged INNER JOIN chat_messages ON chat_messages.userid = messages_reneged.userid AND chat_messages.refmsgid = messages_reneged.msgid ORDER BY chat_messages.id DESC LIMIT " . CORPUS_SIZE . ";");
foreach ($reneges as $renege) {
    if (pres('message', $renege)) {
        $samples[] = $renege['message'];
        $labels[] = 'bad';
    }
}

error_log(count($reneges) . " reneges");

$nonreneges = $dbhr->preQuery("SELECT message FROM messages_promises INNER JOIN chat_messages ON chat_messages.userid = messages_promises.userid AND chat_messages.refmsgid = messages_promises.msgid LEFT JOIN messages_reneged ON messages_reneged.userid = chat_messages.userid AND messages_reneged.msgid = chat_messages.refmsgid WHERE messages_reneged.userid IS NULL ORDER BY chat_messages.id DESC LIMIT " . CORPUS_SIZE . ";");

foreach ($nonreneges as $nonrenege) {
    if (pres('message', $nonrenege)) {
        $samples[] = $nonrenege['message'];
        $labels[] = 'good';
    }
}

error_log(count($nonreneges) . " non-reneges");

#error_log("Samples " . var_export($samples, TRUE));
#error_log("Labels " . var_export($labels, TRUE));

error_log("...vector fit");
$vectorizer->fit($samples);
error_log("...vector transform");
$vectorizer->transform($samples);
unset($vectorizer);

error_log("...TFIDF fit");
$tfIdfTransformer->fit($samples);
error_log("...TFIDF transform");
$tfIdfTransformer->transform($samples);

unset($tfIdfTransformer);

$classifier = new NaiveBayes();

error_log("...dataset");
$dataset = new ArrayDataset($samples, $labels);
error_log("...split");
$randomSplit = new StratifiedRandomSplit($dataset, 0.1);

$classifier = new SVC(Kernel::RBF, 10000);
error_log("...train");
$classifier->train($randomSplit->getTrainSamples(), $randomSplit->getTrainLabels());

error_log("...predict");
$predictedLabels = $classifier->predict($randomSplit->getTestSamples());

error_log('Accuracy: '.Accuracy::score($randomSplit->getTestLabels(), $predictedLabels));

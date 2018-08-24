<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');
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
use Phpml\Pipeline;
use Phpml\ModelManager;
use Phpml\Estimator;
use Phpml\Transformer;
use Phpml\Tokenization\Tokenizer;

# Override Pipeline to expose predictProbability.  Can't extend because there are private methods we'd need to call.
class OurPipeline implements Estimator
{
    /**
     * @var array|Transformer[]
     */
    private $transformers = [];

    /**
     * @var Estimator
     */
    private $estimator;

    /**
     * @param array|Transformer[] $transformers
     */
    public function __construct(array $transformers, Estimator $estimator)
    {
        foreach ($transformers as $transformer) {
            $this->addTransformer($transformer);
        }

        $this->estimator = $estimator;
    }

    public function addTransformer(Transformer $transformer): void
    {
        $this->transformers[] = $transformer;
    }

    public function setEstimator(Estimator $estimator): void
    {
        $this->estimator = $estimator;
    }

    /**
     * @return array|Transformer[]
     */
    public function getTransformers(): array
    {
        return $this->transformers;
    }

    public function getEstimator(): Estimator
    {
        return $this->estimator;
    }

    public function train(array $samples, array $targets): void
    {
        foreach ($this->transformers as $transformer) {
            $transformer->fit($samples, $targets);
            $transformer->transform($samples);
        }

        $this->estimator->train($samples, $targets);
    }

    /**
     * @return mixed
     */
    public function predict(array $samples)
    {
        $this->transformSamples($samples);

        return $this->estimator->predict($samples);
    }

    public function predictProbability(array $samples)
    {
        $this->transformSamples($samples);

        return $this->estimator->predictProbability($samples);
    }

    private function transformSamples(array &$samples): void
    {
        foreach ($this->transformers as $transformer) {
            $transformer->transform($samples);
        }
    }
}

class BiWordTokenizer implements Tokenizer
{
    public function tokenize(string $text): array
    {
        # Don't care about case.
        $text = strtolower($text);

        # Remove all non alphabetic.
        $text = preg_replace("/[^a-z ]/", '', $text);

        $tokens = [];
        preg_match_all('/\w\w+/u', $text, $tokens);
        $words = $tokens[0];
        $pairs = [];
        for ($i = 0; $i + 1 < count($words); $i++) {
            # Return the pair.
            $pairs[] = $words[$i] . " " . $words[$i + 1];
        }

        return $pairs;
    }
}

class Predict extends Entity
{
    const CORPUS_SIZE = 1500;

    const WORD_REGEX = "/[^\w]*([\s]+[^\w]*|$)/";

    private $classifier = NULL, $samples = [], $labels = [], $users = [], $vocabulary = NULL, $pipeline = NULL;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->vectorizer = new TokenCountVectorizer(new WordTokenizer());
        $this->tfIdfTransformer = new TfIdfTransformer();
        $this->classifier = new SVC(
            Kernel::LINEAR, // $kernel
            1.0,            // $cost
            3,              // $degree
            null,           // $gamma
            0.0,            // $coef0
            0.001,          // $tolerance
            100,            // $cacheSize
            true,           // $shrinking
            true            // $probabilityEstimates, set to true
        );

        $this->pipeline = new OurPipeline([
            $this->vectorizer,
            $this->tfIdfTransformer,
        ], $this->classifier);

        $this->samples = [];
        $this->users = [];
    }

    private function getTextForUser($userid)
    {
        $msg = '';

        # Putting a backstop on the oldest message we look at means we will adapt slowly over time if the way
        # people write changes, rather than accumulating too much historical baggage.
        $mysqltime = date ("Y-m-d", strtotime("Midnight 1 year ago"));
        $chatmsgs = $this->dbhr->preQuery("SELECT DISTINCT message FROM chat_messages WHERE userid = ? AND message IS NOT NULL AND date >= '$mysqltime';", [
            $userid
        ], FALSE, FALSE);

        foreach ($chatmsgs as $chatmsg) {
            $msg .= " {$chatmsg['message']}";
        }

        return ($msg);
    }

    public function train($minrating = NULL)
    {
        # Train our model using thumbs up/down ratings which users have given, and the chat messages.
        # Using id DESC means that we will adapt slowly over time if the way people rate changes.
        $minq = $minrating ? " WHERE id >= $minrating" : '';
        $ratings = $this->dbhr->preQuery("SELECT * FROM ratings $minq ORDER BY id DESC LIMIT " . Predict::CORPUS_SIZE . ";");

        if (count($ratings)) {
            foreach ($ratings as $rating) {
                # Find all the text from this user.
                $text = $this->getTextForUser($rating['ratee']);

                if (strlen($text)) {
                    $this->users[] = $rating['ratee'];
                    $this->samples[] = $text;
                    $this->labels[] = $rating['rating'];
                }
            }

            $this->pipeline->train($this->samples, $this->labels);

            $this->vocabulary = $this->vectorizer->getVocabulary();
            #error_log("Got vocab " . var_export($this->vocabulary, TRUE));
        }

        return (count($this->samples));
    }

    public function checkAccuracy()
    {
        # We want to check the accuracy beyond what the model does.  That checks how often we are right or wrong,
        # but for us it's much worse to predict that someone is not a good freegler when they are than it is
        # to predict that they are a good freegler when they're not.
        $wrong = 0;
        $badlywrong = 0;
        $right = 0;
        $up = 0;
        $down = 0;

        $predicts = $this->pipeline->predict($this->samples);

        for ($i = 0; $i < count($this->samples); $i++) {
            $pred = $predicts[$i];

            if ($pred === 'Up') {
                $up++;
            } else {
                $down++;
            }

            $ratings = $this->dbhr->preQuery("SELECT * FROM ratings WHERE ratee = ?;", [
                $this->users[$i]
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
                error_log("User {$this->users[$i]} predict wrong Down but ratings say Up");
                $wrong++;
                $badlywrong++;
            } else if ($verdict < 0 && $pred === 'Up') {
                error_log("User {$this->users[$i]} predict wrong Up but ratings say Down");
                $wrong++;
            } else {
                $right++;
            }
        }

        return([ $up, $down, $right, $wrong, $badlywrong ]);
    }

    public function predict($uid, $checkonly = FALSE, $force = FALSE) {
        # Check to see if we have a prediction.  They are updated in cron.
        $predictions = $this->dbhr->preQuery("SELECT * FROM predictions WHERE userid = ?;", [
            $uid
        ], FALSE, FALSE);

        $ret = User::RATING_UNKNOWN;

        if (count($predictions) && !$force) {
            # We already have a prediction.  Return it.
            $ret = $predictions[0]['prediction'];
        } else if (!$checkonly) {
            # Predict just one user.
            $text = $this->getTextForUser($uid);

            # The text might contains words which weren't present in the data set we used to train.  We have to remove
            # these otherwise the transform will produce vectors which aren't valid.
            $w = new WordTokenizer();
            $words = $w->tokenize($text);
            $words = array_intersect($words, $this->vocabulary);
            $samples = [ implode(' ', $words) ];

            # We use the vectorizer and tfIdfTransformer we set up during train.  We don't call fit because that
            # would wipe them.
            $probs = $this->pipeline->predictProbability($samples);

            # We want to be fairly sure each way.
            if ($probs[0][User::RATING_UP] > 0.7) {
                $ret = User::RATING_UP;
            } else if ($probs[0][User::RATING_DOWN] > 0.7) {
                $ret = User::RATING_DOWN;
            } else {
                $ret = User::RATING_UNKNOWN;
            }

            $this->dbhm->preExec("REPLACE INTO predictions (userid, prediction, probs) VALUES (?, ?, ?);", [
                $uid,
                $ret,
                json_encode($probs)
            ]);
        }

        return($ret);
    }

    public function getModel() {
        $fn = tempnam('/tmp', 'iznik.predict.');
        $modelManager = new ModelManager();
        $modelManager->saveToFile($this->pipeline, $fn);
        $data = file_get_contents($fn);
        unlink($fn);
        return([ $data, $this->vocabulary ]);
    }

    public function loadModel($model, $vocabulary) {
        $fn = tempnam('/tmp', 'iznik.predict.');
        file_put_contents($fn, $model);
        $modelManager = new ModelManager();
        $this->pipeline = $modelManager->restoreFromFile($fn);
        $this->vocabulary = $vocabulary;
        unlink($fn);
    }

    public function ensureModel($minrating = NULL, $fn = '/tmp/iznik.predictions') {
        # We keep a model cached locally on disk which we refresh every 24 hours.  This means we have reasonable
        # performance but will adapt over time.
        $train = TRUE;

        error_log("Check for model ");
        if (file_exists($fn)) {
            error_log("...exists, age " . (time() - filemtime($fn)));
            if (time() - filemtime($fn) < 24 * 3600) {
                # We have a model that's been updated in the last day.
                error_log("...got recent");
                $data = file_get_contents($fn);
                error_log("...length " . strlen($data));

                if ($data) {
                    $uncompressed = gzuncompress($data);
                    error_log("...uncompress length " . strlen($uncompressed));

                    if ($uncompressed) {
                        $decoded = json_decode($uncompressed, TRUE);
                        error_log("...decoded");

                        if ($decoded) {
                            error_log("...load");
                            $this->loadModel($decoded[0], $decoded[1]);
                            $train = FALSE;
                        }
                    }
                }
            }
        }

        if ($train) {
            # We didn't retrieve one.  Build it.
            error_log("...train");
            $this->train($minrating);

            # ...and save it.
            error_log("...retrieve");
            $data = $this->getModel();
            error_log("...encoded");
            $savestr = json_encode($data);
            error_log("...length " . strlen($savestr));
            $savecmp = gzcompress($savestr);
            error_log("...compressed length " . strlen($savecmp));
            file_put_contents($fn, $savecmp);
        }
    }
}
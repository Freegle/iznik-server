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

class Predict extends Entity
{
    const CORPUS_SIZE = 2000;

    private $classifier, $samples, $users;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    private function getTextForUser($userid)
    {
        $msg = '';

        # Putting a backstop on the oldest message we look at means we will adapt slowly over time if the way
        # people write changes, rather than accumulating too much historical baggage.
        $mysqltime = date ("Y-m-d", strtotime("Midnight 1 year ago"));
        $chatmsgs = $this->dbhr->preQuery("SELECT DISTINCT message FROM chat_messages WHERE userid = ? AND message IS NOT NULL AND type = ? AND date >= '$mysqltime';", [
            $userid,
            ChatMessage::TYPE_INTERESTED
        ]);

        foreach ($chatmsgs as $chatmsg) {
            $msg .= " {$chatmsg['message']}";
        }

        return ($msg);
    }

    public function train($minrating = NULL)
    {
        # Train our model using thumbs up/down ratings which users have given, and the chat messages.
        $vectorizer = new TokenCountVectorizer(new WordTokenizer());
        $tfIdfTransformer = new TfIdfTransformer();

        $this->samples = [];
        $labels = [];
        $this->users = [];
        $accuracy = 0;

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
                    $labels[] = $rating['rating'];
                }
            }

            $vectorizer->fit($this->samples);
            $vectorizer->transform($this->samples);
            unset($vectorizer);

            $tfIdfTransformer->fit($this->samples);
            $tfIdfTransformer->transform($this->samples);

            $dataset = new ArrayDataset($this->samples, $labels);
            $randomSplit = new StratifiedRandomSplit($dataset, 0.1);

            $this->classifier = new SVC(Kernel::RBF, 10000);

            $this->classifier->train($randomSplit->getTrainSamples(), $randomSplit->getTrainLabels());

            $predicton = $randomSplit->getTestSamples();
            $predictedLabels = $this->classifier->predict($predicton);

            $accuracy = Accuracy::score($randomSplit->getTestLabels(), $predictedLabels);
        }

        return ([$accuracy, count($this->samples)]);
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
        
        $predicts = $this->predict($this->samples);
        
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

    public function predict($samples) {
        return($this->classifier->predict($samples));       
    }
}
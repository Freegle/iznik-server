<?php
namespace Freegle\Iznik;



class AdView
{
    private $dbhr, $dbhm;
    
    const COMMONWORDS = ['of', 'up', 'to', 'be'];

    /** @var  $dbhm LoggedPDO */
    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }
    
    public function sortJobs($jobs, $userid = NULL) {
        // Score the jobs based on which jobs generate the most clicks.  Anything we have ourselves
        // clicked on earlier jumps to the top of of the rankings.
        $keywords = [];

        # Get the keywords in all the urls.
        foreach ($jobs as &$job) {
            if (preg_match('/.*\/(.*)\?/', $job['url'], $matches)) {
                $words = explode('-', $matches[1]);
                $keywords = array_merge($keywords, $words);
            }
        }

        $keywords = array_unique($keywords);
        $keywords = array_diff(self::COMMONWORDS, $keywords);

        # Get the number of clicks on each.
        $sql = "SELECT keyword, count FROM jobs_keywords WHERE keyword IN (";
        foreach ($keywords as $keyword) {
            $sql .= $this->dbhr->quote($keyword) . ',';
        }

        $sql .= '0)';

        $rows = $this->dbhr->preQuery($sql, NULL, FALSE, FALSE);
        $scores = [];

        foreach ($rows as $row) {
            $scores[$row['keyword']] = $row['count'];
        }

        $max = 1;

        if (count($scores)) {
            $max = max($scores);

            # Normalise to less than 100.  This hides the click numbers and also allows us to score
            # our own clicks higher.
            foreach ($scores as $keyword => $score) {
                $scores[$keyword] = 100 * $scores[$keyword] / $max;
            }
        }

        $mykeywords = [];

        if ($userid) {
            # Find keywords I've clicked on.
            $logs = $this->dbhr->preQuery("SELECT * FROM logs_jobs WHERE userid = ?;", [
                $userid
            ]);

            foreach ($logs as $log) {
                if (preg_match('/.*\/(.*)\?/', $log['link'], $matches)) {
                    $words = explode('-', $matches[1]);

                    foreach ($words as $word) {
                        if (!is_numeric($word) && !in_array($word, self::COMMONWORDS)) {
                            if (array_key_exists($word, $mykeywords)) {
                                $mykeywords[$word]++;
                            } else {
                                $mykeywords[$word] = 1;
                            }
                        }
                    }
                }
            }
        }

        # Score the jobs.
        foreach ($jobs as &$job) {
            if (preg_match('/.*\/(.*)\?/', $job['url'], $matches)) {
                $words = explode('-', $matches[1]);
                $score = 0;

                foreach ($words as $word) {
                    if (!is_numeric($word)) {
                        $score += Utils::presdef($word, $scores, 0);

                        if (Utils::pres($word, $mykeywords)) {
                            $score += 100 * $mykeywords[$word];
                        }
                    }
                }

                $job['score'] = 100 * $score / $max;
            }
        }

        usort($jobs, function($a, $b) {
            return Utils::presdef('score', $b, 0) - Utils::presdef('score', $a, 0);
        });

        return $jobs;
    }
}


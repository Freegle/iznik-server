<?php
namespace Freegle\Iznik;



class Item extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'popularity', 'weight', 'updated', 'suggestfromphoto', 'suggestfromtypeahead');
    var $settableatts = array('name', 'popularity', 'weight');

    /** @var  $log Log */
    private $log;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'items', 'item', $this->publicatts);
        $this->s = new Search($dbhr, $dbhm, 'items_index', 'itemid', 'popularity', 'words', 'categoryid', NULL, 'words_cache');
    }

    /**
     * @param LoggedPDO $dbhm
     */
    public function setDbhm($dbhm)
    {
        $this->dbhm = $dbhm;
    }

    public function create($name) {
        if (!$name || trim($name) === '') {
            return NULL;
        }

        $name = trim($name);

        try {
            # If we have a dup, update the name.  This is because the unique index is case insensitive, and it might be
            # that someone is correcting the case.
            $rc = $this->dbhm->preExec("INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), name = ?;", [ $name, $name ]);
            $id = $this->dbhm->lastInsertId();
        } catch (\Exception $e) {
            $id = NULL;
            $rc = 0;
        }

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'items', 'item', $this->publicatts);

            # Add into the search index.
            $this->index();

            # Create a weight estimate for this.
            $weight = $this->estimateWeight();
            $this->setWeight($weight);

            return($id);
        } else {
            return(NULL);
        }
    }

    public function index() {
        if ($this->id) {
            $this->s->delete($this->id);
            $this->s->add($this->id, $this->item['name'], $this->item['popularity'], NULL);
        }
    }

    public function typeahead($query, $minpop = 5) {
        $ctx = NULL;

        # Only look for exact words to make typeahead fast.
        $results = $this->s->search($query, $ctx, 10, NULL, NULL, TRUE, $minpop);
        foreach ($results as &$result) {
            $i = new Item($this->dbhr, $this->dbhm, $result['id']);

            if ($i->getPrivate('suggestfromtypeahead')) {
                $result['item'] = $i->getPublic();
            }
        }
        return($results);
    }

    public function estimateWeight() {
        # We scan the standard weights, looking for the entry with the most words in common with this one.
        $name = $this->item['name'];

        $weights = $this->dbhr->preQuery("SELECT CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END AS name, weight FROM weights");
        $bestweight = NULL;
        $bestwic = NULL;
        $bestname = NULL;

        foreach ($weights as $weight) {
            $wic = Utils::wordsInCommon($name, $weight['name']);

            #error_log("$name vs {$weight['name']} = $wic");
            if (is_null($bestwic) || $wic > $bestwic) {
                $bestweight = $weight['weight'];
                $bestwic = $wic;
                $bestname = $weight['name'];
            }
        }

        $bestweight = $bestwic > 10 ? $bestweight : NULL;

        return($bestweight);
    }

    public function setWeight($weight) {
        if ($this->id) {
            $this->dbhm->preExec("UPDATE items SET weight = ? WHERE id = ?;", [
                $weight,
                $this->id
            ]);
        }
    }

    public function findByName($query) {
        $items = $this->dbhr->preQuery("SELECT * FROM items WHERE name LIKE ? AND suggestfromphoto = 1 ORDER BY popularity DESC limit 1;", [ $query ]);
        return($items);
    }

    public function delete() {
        $rc = FALSE;
        if ($this->id) {
            # Remove from the search index.
            $this->s->delete($this->id);

            $rc = $this->dbhm->preExec("DELETE FROM items WHERE id = ?;", [$this->id]);
        }

        return($rc);
    }
}
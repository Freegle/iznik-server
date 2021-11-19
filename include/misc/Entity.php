<?php
namespace Freegle\Iznik;

use Redis;



# Base class used for groups, users, messages, with some basic fetching and attribute manipulation.
class Entity
{
    /** @public  $dbhr LoggedPDO */
    public $dbhr;
    /** @public  $dbhm LoggedPDO */
    public $dbhm;
    public $id;
    public $publicatts = array();
    public $name, $table;
    public $redis;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    function fetch(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL, $table, $name, $publicatts, $fetched = NULL, $allowcache = TRUE, $sql = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->name = $name;
        $this->$name = NULL;
        $this->id = NULL;
        $this->publicatts = $publicatts;
        $this->table = $table;

        if ($id) {
            $sql = $sql ? $sql : "SELECT * FROM `$table` WHERE id = ?;";
            $entities = $fetched ? [ $fetched ] : $dbhr->preQuery($sql,
                [
                    $id
                ],
                FALSE,
                $allowcache);

            foreach ($entities as $entity) {
                $this->$name = $entity;
                $this->id = $id;
            }
        }
    }

    public function getAtts($list) {
        $ret = array();
        foreach ($list as $att) {
            if ($this->{$this->name} && array_key_exists($att, $this->{$this->name})) {
                $ret[$att] = $this->{$this->name}[$att];
            } else {
                $ret[$att] = NULL;
            }
        }

        return($ret);
    }

    public function getPublic() {
        $ret = $this->getAtts($this->publicatts);
        return($ret);
    }

    public function getPrivate($att) {
        if (Utils::pres($att, $this->{$this->name})) {
            return($this->{$this->name}[$att]);
        } else {
            return(NULL);
        }
    }

    public function getEditLog($new) {
        $old = $this->{$this->name};

        $edit = [];
        foreach ($new as $att => $val) {
            $oldval = json_encode(Utils::pres($att, $old) ? $old[$att] : NULL);
            if ($oldval != json_encode($val)) {
                $edit[] = [
                    $att => [
                        'old' => Utils::pres($att, $old) ? $old[$att] : NULL,
                        'new' => $val
                    ]
                ];
            }
        }

        $str = json_encode($edit);

        return($str);
    }

    public function setPrivate($att, $val) {
        if (!array_key_exists($att, $this->{$this->name}) || $this->{$this->name}[$att] !== $val) {
            $rc = $this->dbhm->preExec("UPDATE `{$this->table}` SET `$att` = ? WHERE id = {$this->id};", [$val]);
            #error_log("RC $rc for set of $att = $val for {$this->id} in {$this->table}");
            if ($rc) {
                $this->{$this->name}[$att] = $val;
            }
        }
    }

    public function setAttributes($settings) {
        foreach ($this->settableatts as $att) {
            if (array_key_exists($att, $settings)) {
                $this->setPrivate($att, $settings[$att]);
            }
        }
    }

    public function getRedis() {
        if (!$this->redis) {
            $this->redis = new \Redis();
            @$this->redis->pconnect(REDIS_CONNECT);
        }

        return($this->redis);
    }
}
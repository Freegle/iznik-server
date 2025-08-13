<?php
namespace Freegle\Iznik;



class Shortlink extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'name', 'type', 'groupid', 'url', 'clicks', 'created');
    var $settableatts = array('name');

    const TYPE_GROUP = 'Group';
    const TYPE_OTHER = 'Other';

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'shortlinks', 'shortlink', $this->publicatts);
    }

    public function create($name, $type, $groupid = NULL, $url = NULL) {
        $ret = NULL;

        $rc = $this->dbhm->preExec("INSERT INTO shortlinks (name, type, groupid, url) VALUES (?,?,?,?);", [
            $name,
            $type,
            $groupid,
            $url
        ]);

        $id = $this->dbhm->lastInsertId();

        if ($rc && $id) {
            $this->fetch($this->dbhm, $this->dbhm, $id, 'shortlinks', 'shortlink', $this->publicatts);
            $ret = $id;
        }

        return($ret);
    }

    public function resolve($name, $countclicks = TRUE) {
        $url = NULL;
        $id = NULL;
        $links = $this->dbhr->preQuery("SELECT * FROM shortlinks WHERE name LIKE ?;", [ $name ]);
        foreach ($links as $link) {
            $id = $link['id'];
            if ($link['type'] == Shortlink::TYPE_GROUP) {
                $g = new Group($this->dbhr, $this->dbhm, $link['groupid']);

                # Where we redirect to depends on the group settings.
                $external = $g->getPrivate('external');

                if ($external) {
                    $url = $external;
                } else {
                    $url = $g->getPrivate('onhere') ? ('https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort')) : ('https://groups.yahoo.com/' . $g->getPrivate('nameshort'));
                }
            } else {
                $url = $link['url'];

                if (strpos($url, 'http://groups.yahoo.com/group/') === 0) {
                    $url = str_replace('http://groups.yahoo.com/group/', 'http://groups.yahoo.com/neo/groups/', $url);
                }
            }

            if ($countclicks) {
                $this->dbhm->background("UPDATE shortlinks SET clicks = clicks + 1 WHERE id = {$link['id']};");
                $this->dbhm->background("INSERT INTO shortlink_clicks (shortlinkid) VALUES ({$link['id']});");
            }
        }

        return([$id, $url]);
    }

    public function listAll($groupid = NULL) {
        $groupq = $groupid ? " WHERE groupid = $groupid " : "";
        $links = $this->dbhr->preQuery("SELECT * FROM shortlinks $groupq ORDER BY LOWER(name) ASC;");
        foreach ($links as &$link) {
            if ($link['type'] == Shortlink::TYPE_GROUP) {
                $g = new Group($this->dbhr, $this->dbhm, $link['groupid']);
                $link['nameshort'] = $g->getPrivate('nameshort');

                # Where we redirect to depends on the group settings.
                $link['url'] = $g->getPrivate('onhere') ? ('https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort')) : ('https://groups.yahoo.com/neo/groups' . $g->getPrivate('nameshort'));
            }
        }

        return($links);
    }

    public function getPublic() {
        $ret = $this->getAtts($this->publicatts);
        $ret['created'] = Utils::ISODate($ret['created']);

        if ($ret['type'] == Shortlink::TYPE_GROUP) {
            $g = new Group($this->dbhr, $this->dbhm, $ret['groupid']);
            $ret['nameshort'] = $g->getPrivate('nameshort');

            # Where we redirect to depends on the group settings.
            $ret['url'] = $g->getPrivate('onhere') ? ('https://' . USER_SITE . '/explore/' . $g->getPrivate('nameshort')) : ('https://groups.yahoo.com/neo/groups' . $g->getPrivate('nameshort'));
        }

        $clickhistory = $this->dbhr->preQuery("SELECT DATE(timestamp) AS date, COUNT(*) AS count FROM `shortlink_clicks` WHERE shortlinkid = ? GROUP BY date ORDER BY date ASC", [
            $this->id
        ]);
        foreach ($clickhistory as &$c) {
            $c['date'] = Utils::ISODate($c['date']);
        }
        $ret['clickhistory'] = $clickhistory;
        return($ret);
    }

    public function delete() {
        $rc = $this->dbhm->preExec("DELETE FROM shortlinks WHERE id = ?;", [$this->id]);
        return($rc);
    }

    public function expandExternal($url, $depth = 1) {
        $ret = Spam::URL_REMOVED;

        if ($depth > 10) {
            # Redirect loop?
            error_log("Loop in $url at $depth");
            return $ret;
        }

        if (strpos($url, 'https://' . USER_SITE) === 0 || strpos($url, USER_TEST_SITE) === 0) {
            # Ours - so no need to expand.
            error_log("URL $url is our domain " . USER_SITE . " or " . USER_TEST_SITE);
            return $url;
        }

        if (stripos($url, 'www.facebook.com')) {
            # Facebook bounces us with unsupported browser, but we don't need to expand them anyway.
            error_log("URL $url is Facebook");
            return $url;
        }

        if (stripos($url, 'http') !== 0) {
            # We don't want to follow http links.
            error_log("Add http:// to $url");
            $url = "http://$url";
        }

        try {
            # Timeout - if a shortener doesn't return in time we'll filter out the URL.
            $opts['http']['timeout'] = 5;
            # Show warnings - e.g. from get_headers.
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

            $context = stream_context_create($opts);

            $retries = 10;
            do {
                $response = @get_headers($url, 1, $context);

                if (!$response) {
                    $retries--;

                    if (!$retries) {
                        break;
                    }

                    usleep(100000);
                }
            } while (!$response);

            if ($response) {
                # The location property of the response header is used for redirect.
                if (array_key_exists('Location', $response)) {
                    $location = $response["Location"];

                    if (is_array($location)) {
                        # Find the first entry  in the array starting with http
                        $newloc = null;

                        foreach ($location as $l) {
                            if (strpos($l, 'http') === 0) {
                                $newloc = $l;
                                break;
                            }
                        }

                        if ($newloc) {
                            $ret = $this->expandExternal($newloc, $depth + 1);
                        } else {
                            error_log("Redirect not handled for $url: " . json_encode($location));
                            $ret = Spam::URL_REMOVED;
                        }
                    } else if (stripos($location, 'http') === FALSE) {
                        // Not a link - probably redirecting to relative path.
                        $ret = $url;
                    } else {
                        $ret = $this->expandExternal($location, $depth + 1);
                    }
                } else {
                    $ret = $url;
                }
            } else {
                error_log("$url returned no response");
            }
        } catch (\Exception $e) {
            error_log("Failed to expand $url: " . $e->getMessage());
        } finally {
            error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
        }

        return $ret;
    }

    function expandAllUrls($str) {
        $urls = [];

        if (preg_match_all(Utils::URL_PATTERN, $str, $matches)) {
            foreach ($matches as $val) {
                foreach ($val as $url) {
                    // If url contains ] then use everything before it.  This seems common and wrong, and the regex is
                    // impenetrable.
                    $url = strstr($url, ']', TRUE) ?: $url;
                    $urls[] = $url;
                }
            }

            $urls = array_unique($urls);

            foreach ($urls as $url) {
                if ($url) {
                    $newurl = $this->expandExternal($url);

                    if ($newurl != $url) {
                        $str = str_replace($url, $newurl, $str);
                        error_log("Expand $url to $newurl");
                    }
                }
            }
        }

        return $str;
    }
}
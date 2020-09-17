<?php

require_once(IZNIK_BASE . '/include/utils.php');

use LinkPreview\LinkPreview;

class Preview extends Entity
{
    /** @var  $dbhm LoggedPDO */
    public $publicatts = [ 'id', 'url', 'title', 'description', 'image', 'invalid', 'spam'];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->fetch($dbhr, $dbhm, $id, 'link_previews', 'link', $this->publicatts);
    }

    public function create($url, $forcespam = FALSE) {
        $id = NULL;

        if (checkSpamhaus($url) || $forcespam) {
            $rc = $this->dbhm->preExec("INSERT INTO link_previews(`url`, `spam`) VALUES (?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);", [
                $url
            ]);
        } else {
            try {
                # We should fetch the HTTPS variant even if asked for the HTTP one.  This is because when we later
                # display the preview, we will have to fetch the image, and if we have an HTTP link then it will
                # be blocked on our page for being insecure.
                #
                # Any sites which don't support HTTPS won't get previews.  Or much traffic either, nowadays.
                $url = str_replace('http://', 'https://', $url);
                $linkPreview = new LinkPreview($url);
                $parsed = $linkPreview->getParsed();
                $rc = NULL;

                if (count($parsed) == 0) {
                    $rc = $this->dbhm->preExec("INSERT INTO link_previews(`url`, `invalid`) VALUES (?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);", [
                        $url
                    ]);
                } else {
                    foreach ($parsed as $parserName => $link) {
                        $title = $link->getTitle();
                        $title = preg_replace('/[[:^print:]]/', '', $title);
                        $desc = $link->getDescription();
                        $desc = preg_replace('/[[:^print:]]/', '', $desc);
                        $pic = $link->getImage();
                        $realurl = $link->getRealUrl();

                        if (stripos($pic, 'http') === FALSE) {
                            # We have a relative URL.
                            $pic = $realurl . $pic;
                        }

                        $rc = $this->dbhm->preExec("INSERT INTO link_previews(`url`, `title`, `description`, `image`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);", [
                            $url,
                            $title ? $title : NULL,
                            $desc ? $desc : NULL,
                            $pic ? $pic : NULL
                        ]);

                    }
                }
            } catch (Exception $e) {
                $rc = $this->dbhm->preExec("INSERT INTO link_previews(`url`, `invalid`) VALUES (?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);", [
                    $url
                ]);
            }
        }

        if ($rc) {
            $id = $this->dbhm->lastInsertId();
            $this->fetch($this->dbhm, $this->dbhm, $id, 'link_previews', 'link', $this->publicatts);
        }

        return($id);
    }

    public function get($url) {
        # Doing a select first allows caching and previews DB locks.
        $links = $this->dbhr->preQuery("SELECT id FROM link_previews WHERE url = ?;", [
            $url
        ]);

        if (count($links) > 0) {
            $this->fetch($this->dbhm, $this->dbhm, $links[0]['id'], 'link_previews', 'link', $this->publicatts);
            $id = $links[0]['id'];
        } else {
            $id = $this->create($url);
        }

        # Make any relative urls absolute to help app.
        $this->link['url'] = substr($this->link['url'], 0, 1) == '/' ? ('https://' . HTTP_HOST . "/$this->link['url']") :  $this->link['url'];

        # Ensure title is not numeric
        if (pres('title', $this->link) && is_numeric($this->link['title'])) {
            $this->link['title'] .= '...';
        }

        return($id);
    }
}


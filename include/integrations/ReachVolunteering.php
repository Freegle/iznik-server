<?php

namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

class ReachVolunteering {
    private $dbhr;
    private $dbhm;

    public function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function processFeed() {
        $added = 0;
        $updated = 0;
        $deleted = 0;

        #error_log("$url");
        $auth = base64_encode(REACH_USER . ":" . REACH_PASSWORD);
        $ctx = stream_context_create(array('http'=> [
            'timeout' => 120,
            "method" => "GET",
            "header" => "Authorization: Basic $auth"
        ]));

        $return = file_get_contents(REACH_FEED, FALSE, $ctx);

        error_log("Got feed len " . strlen($return));
        error_log(substr($return, 0, 100));

        if ($return) {
            $opps = json_decode($return, TRUE, 512, JSON_INVALID_UTF8_IGNORE);

            if (Utils::pres('Opportunities', $opps)) {
                $opps = $opps['Opportunities'];
                error_log("Found " . count($opps) . " opportunies");

                $externalsSeen = [];

                foreach ($opps as $opp) {
                    $opp = $opp['Opportunity'];
                    $loc = $opp['Location'];

                    $externalid = "reach-" . $opp['Job id'];

                    $externalsSeen[$externalid] = TRUE;

                    if (preg_match(Utils::POSTCODE_PATTERN, $loc, $matches)) {
                        $pc = strtoupper($matches[0]);
                        error_log("...postcode $pc");

                        $l = new Location($this->dbhr, $this->dbhm);
                        $pc = $l->findByName($pc);

                        if ($pc) {
                            $l = new Location($this->dbhr, $this->dbhm, $pc);

                            if ($l->getPrivate('type') == 'Postcode') {
                                $groups = $l->groupsNear(Location::QUITENEARBY);

                                if (count($groups)) {
                                    $g = Group::get($this->dbhr, $this->dbhm, $groups[0]);
                                    error_log("...on #{$groups[0]} " . $g->getName());

                                    if ($g->getSetting('volunteering', 1)) {
                                        $existing = $this->dbhr->preQuery("SELECT * FROM volunteering WHERE externalid = ?", [ $externalid ]);

                                        $title = $opp['title'];
                                        $description = $opp['Job description'];
                                        $url = $opp['Apply url'];
                                        $location = $opp['Location'];
                                        $commitment = Utils::presdef('Time commitment', $opp, NULL);

                                        if (count($existing)) {
                                            # Make sure the info is up to date.
                                            #
                                            # We don't update the photo as there is no good way to
                                            # check it hasn't changed.
                                            error_log("...updated existing " . $existing[0]['id']);
                                            $v = new Volunteering($this->dbhr, $this->dbhm, $existing[0]['id']);
                                            $v->setPrivate('title', $title);
                                            $v->setPrivate('location', $location);
                                            $v->setPrivate('description', $description);
                                            $v->setPrivate('contacturl', $url);
                                            $v->setPrivate('timecommitment', $commitment);
                                            $updated++;
                                        } else {
                                            $added++;

                                            # We don't - create it.
                                            $v = new Volunteering($this->dbhr, $this->dbhm);
                                            $vid = $v->create(
                                                null,
                                                $title,
                                                FALSE,
                                                $location,
                                                NULL,
                                                NULL,
                                                NULL,
                                                $url,
                                                $description,
                                                $commitment,
                                                $externalid
                                            );

                                            error_log("...created as $vid");
                                            $added++;

                                            $v->addGroup($g->getId());

                                            # Get an image if we can.
                                            $image = Utils::presdef('Logo url', $opp, NULL);

                                            if ($image) {
                                                $t = new Tus($this->dbhr, $this->dbhm);
                                                $url = $t->upload($image);

                                                if ($url) {
                                                    $uid = 'freegletusd-' . basename($url);
                                                    $this->dbhm->preExec("INSERT INTO volunteering_images (opportunityid, externaluid) VALUES (?,?);", [
                                                        $vid,
                                                        $uid
                                                    ]);
                                                }
                                            }
                                        }
                                    } else {
                                        error_log("Volunteering not allowed on " . $g->getName());
                                    }
                                } else {
                                    error_log("No groups near $pc");
                                }
                            } else {
                                error_log("Not a postcode $pc");
                            }
                        } else {
                            error_log("Can't find postcode $pc");
                        }
                    } else {
                        error_log("No postcode in $loc");
                    }
                }
            } else {
                throw new Exception("JSON is unexpected " . json_last_error_msg());
            }

        } else {
            throw new Exception("Failed to get " . REACH_FEED . " with $http_response_header");
        }

        error_log("Added $added");

        # Look for ops which need removing because they aren't on Reach any more.
        $existings = $this->dbhr->preQuery("SELECT id, externalid FROM volunteering WHERE externalid LIKE 'reach-%';");

        foreach ($existings as $e) {
            if (!array_key_exists($e['externalid'], $externalsSeen)) {
                error_log("...deleting old {$e['id']}, {$e['externalid']}");
                $cv = new Volunteering($this->dbhr, $this->dbhm, $e['id']);
                $cv->setPrivate('deleted', 1);
                $deleted++;
            }
        }

        error_log("Added $added, updated $updated, deleted $deleted");
    }
}
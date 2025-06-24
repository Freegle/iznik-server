<?php

namespace Freegle\Iznik;

use PhpMimeMailParser\Exception;

class ReachVolunteering {
    private $dbhr;
    private $dbhm;
    private $useNewFieldNames;

    public function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $useNewFieldNames = false) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->useNewFieldNames = $useNewFieldNames;
    }

    private function getFieldMapping() {
        if ($this->useNewFieldNames) {
            return [
                'title' => 'title',
                'date_posted' => 'date_posted',
                'job_id' => 'job_id',
                'summary' => 'summary',
                'description' => 'description',
                'person_description' => 'person_description',
                'person_impact' => 'person_impact',
                'other_details' => 'other_details',
                'town' => 'town',
                'postcode' => 'postcode',
                'skills' => 'skills',
                'organisation' => 'organisation',
                'causes' => 'causes',
                'activities' => 'activities',
                'objectives' => 'objectives',
                'url' => 'url'
            ];
        } else {
            return [
                'title' => 'title',
                'date_posted' => 'Posting date',
                'job_id' => 'Job id',
                'summary' => 'summary',
                'description' => 'Job description',
                'person_description' => 'Person specification',
                'person_impact' => 'What impact the opportunity will have',
                'other_details' => 'Other details',
                'town' => 'Location',
                'postcode' => 'Location',
                'skills' => 'Required skills',
                'organisation' => 'Organisation',
                'causes' => 'Charity sector',
                'activities' => 'Organisation activities',
                'objectives' => 'Organisation objective',
                'url' => 'Apply url'
            ];
        }
    }

    private function processOpportunity($opp, $fieldMap, &$externalsSeen, &$added, &$updated) {
        $postingDate = $opp[$fieldMap['date_posted']];
        $postingAgeInDays = (time() - strtotime($postingDate)) / (60 * 60 * 24);

        if ($postingAgeInDays > Volunteering::EXPIRE_AGE) {
            error_log("...skipping as too old $postingDate");
            return;
        }

        $externalid = "reach-" . $opp[$fieldMap['job_id']];
        $externalsSeen[$externalid] = TRUE;

        if ($this->useNewFieldNames) {
            // New format: get postcode directly from postcode field
            $pc = $opp[$fieldMap['postcode']];
            $loc = $opp[$fieldMap['town']] . ' ' . $pc;
        } else {
            // Old format: extract postcode from Location field using regex
            $loc = $opp[$fieldMap['town']]; // This is 'Location' field in old format
            if (preg_match(Utils::POSTCODE_PATTERN, $loc, $matches)) {
                $pc = strtoupper($matches[0]);
            } else {
                error_log("No postcode in $loc");
                return;
            }
        }

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

                        $title = $opp[$fieldMap['title']];
                        $description = $opp[$fieldMap['description']];
                        $url = $opp[$fieldMap['url']];
                        $location = $loc;
                        $commitment = Utils::presdef($fieldMap['other_details'], $opp, NULL);

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
    }

    protected function fetchFeedData($feedUrl) {
        $auth = base64_encode(REACH_USER . ":" . REACH_PASSWORD);
        $ctx = stream_context_create(array('http'=> [
            'timeout' => 120,
            "method" => "GET",
            "header" => "Authorization: Basic $auth"
        ]));

        $return = file_get_contents($feedUrl, FALSE, $ctx);

        error_log("Got feed len " . strlen($return));
        error_log(substr($return, 0, 100));

        return $return;
    }

    public function processFeed($feedUrl) {
        $added = 0;
        $updated = 0;
        $deleted = 0;

        $fieldMap = $this->getFieldMapping();

        $return = $this->fetchFeedData($feedUrl);

        if ($return) {
            $data = json_decode($return, TRUE, 512, JSON_INVALID_UTF8_IGNORE);
            $externalsSeen = [];

            if ($this->useNewFieldNames) {
                // New format: top level is array of opportunities
                $opps = $data;
                error_log("Found " . count($opps) . " opportunities");

                foreach ($opps as $opp) {
                    // Each item is directly an opportunity object
                    $this->processOpportunity($opp, $fieldMap, $externalsSeen, $added, $updated);
                }
            } else {
                // Old format: { "Opportunities": [ { "Opportunity": {} } ] }
                if (Utils::pres('Opportunities', $data)) {
                    $opps = $data['Opportunities'];
                    error_log("Found " . count($opps) . " opportunities");

                    foreach ($opps as $oppWrapper) {
                        $opp = $oppWrapper['Opportunity'];
                        $this->processOpportunity($opp, $fieldMap, $externalsSeen, $added, $updated);
                    }
                } else {
                    throw new Exception("JSON is unexpected " . json_last_error_msg());
                }
            }

        } else {
            throw new Exception("Failed to get " . $feedUrl . " with $http_response_header");
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
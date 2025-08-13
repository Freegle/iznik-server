<?php

namespace Freegle\Iznik;

class RestartProject {
    private $dbhr;
    private $dbhm;

    public function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    private function request($url) {
        #error_log("$url");
        $context = stream_context_create(['http' => ['ignore_errors' => TRUE]]);

        do {
            $ret = file_get_contents($url, FALSE, $context);
            $throttle = strpos($ret, '<title>Too Many Requests</title>') !== FALSE;

            if ($throttle) {
                error_log("Throttled on $url, sleeping");
                sleep(5);
            }
        } while ($throttle);

        if ($ret) {
            $ret2 = json_decode($ret, TRUE);
            if ($ret2 && array_key_exists('data', $ret2)) {
                return $ret2['data'];
            } else {
                error_log("No data found in response from $url " . json_encode($ret));
            }
        } else {
            error_log("Failed to get $url with $http_response_header");
            return null;
        }
    }

    public function getUpcomingEvents() {
        $added = 0;
        $latest = Utils::ISODate(date("Y-m-d", strtotime("+31 days")));
        $now = Utils::ISODate(date("Y-m-d"));
        $externalsSeen = [];

        $groups = $this->request("https://restarters.net/api/v2/groups/names");

        if ($groups) {
            #error_log("Found " . count($groups) . " groups");

            if (count($groups)) {
                foreach ($groups as $group) {
                    if (strpos($group['name'], "[INACTIVE]") === FALSE) {
                        $group_details = $this->request("https://restarters.net/api/v2/groups/" . $group['id']);

                        if ($group_details) {
                            # We only care about GB groups.
                            if ($group_details['location']['country_code'] == 'GB') {
                                // Only interested in these.  Check the location is inside a DPA/CGA
                                $lat = $group_details['location']['lat'];
                                $lng = $group_details['location']['lng'];
                                $l = new Location($this->dbhr, $this->dbhm);
                                $pc = $l->closestPostcode($lat, $lng);
                                $groupsnear = Utils::pres('groupsnear', $pc);

                                if ($groupsnear && count($groupsnear) > 0) {
                                    $g = new Group($this->dbhr, $this->dbhm, $groupsnear[0]['id']);
                                    error_log(
                                        "{$group['name']} => {$g->getName()} near {$lat},{$lng}"
                                    );

                                    if ($g->getSetting('communityevents', 1)) {
                                        $events = $this->request(
                                            "https://restarters.net/api/v2/groups/{$group['id']}/events?start=$now&end=$latest"
                                        );

                                        if ($events) {
                                            #error_log("Found " . count($events) . " events for group " . $group['name']);

                                            foreach ($events as $event) {
                                                if ($event['approved']) {
                                                    # Get full details as we need the description.
                                                    $event_details = $this->request(
                                                        "https://restarters.net/api/v2/events/{$event['id']}"
                                                    );

                                                    if ($event_details) {
                                                        error_log(
                                                            "...{$event['id']} {$event['start']} {$event['end']} {$event['title']}"
                                                        );
                                                        $externalid = "Restart-{$event['id']}";

                                                        # Description may contain HTML - strip.
                                                        $html = new \Html2Text\Html2Text($event_details['description']);
                                                        $description = $html->getText();

                                                        $title = $event['title'];

                                                        # Add Repair Cafe if not there
                                                        if (strpos($title, "Repair Cafe") === FALSE) {
                                                            $title = "Repair Cafe: " . $title;
                                                        }

                                                        $url = Utils::presdef('link', $event_details, NULL);

                                                        if (!$url) {
                                                            $url = Utils::presdef('website', $group_details, NULL);
                                                        }

                                                        $email = Utils::presdef('email', $group_details, NULL);

                                                        $externalsSeen[$externalid] = TRUE;

                                                        # See if we already have the event.
                                                        $existing = $this->dbhr->preQuery("SELECT * FROM communityevents WHERE externalid = ?", [ $externalid ]);

                                                        if (count($existing)) {
                                                            # Make sure the info is up to date.
                                                            #
                                                            # We don't update the photo as there is no good way to
                                                            # check it hasn't changed.
                                                            error_log("...updated existing " . $existing[0]['id']);
                                                            $e = new CommunityEvent($this->dbhr, $this->dbhm, $existing[0]['id']);

                                                            $pending = $e->getPrivate('title') != $title ||
                                                                $e->getPrivate('location') != $event['location'] ||
                                                                $e->getPrivate('description') != $description;

                                                            if ($pending && !$e->getPrivate('pending')) {
                                                                # Return to pending for re-review, in case the mod
                                                                # edited these.
                                                                $e->setPrivate('pending', 1);
                                                            }

                                                            $e->setPrivate('title', $title);
                                                            $e->setPrivate('location', $event['location']);
                                                            $e->setPrivate('description', $description);
                                                            $e->setPrivate('contacturl', $url);
                                                            $e->setPrivate('contactemail', $email);

                                                            # Replace dates in case they have changed.
                                                            $e->removeDates();
                                                            $e->addDate($event['start'], $event['end']);
                                                        } else {
                                                            $added++;
                                                            # We don't - create it.
                                                            $e = new CommunityEvent($this->dbhr, $this->dbhm);
                                                            #create($userid, $title, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $photo = NULL, $externalid = NULL) {
                                                            $eid = $e->create(
                                                                null,
                                                                $title,
                                                                $event['location'],
                                                                $event_details['group']['name'],
                                                                NULL,
                                                                $email,
                                                                $url,
                                                                $description,
                                                                NULL,
                                                                $externalid
                                                            );
                                                            error_log("...created as $eid");

                                                            $e->addGroup($groupsnear[0]['id']);
                                                            $e->addDate($event['start'], $event['end']);

                                                            # Get an image if we can.
                                                            $image = NULL;

                                                            if (Utils::pres('image', $event_details['group'])) {
                                                                $image = "https://restarters.net/uploads/" . $event_details['group']['image'];
                                                            } else if (Utils::pres('networks', $event_details['group'])) {
                                                                $image = $event_details['group']['networks'][0]['logo'];
                                                            }

                                                            if ($image) {
                                                                $t = new Tus($this->dbhr, $this->dbhm);
                                                                $url = $t->upload($image);

                                                                if ($url) {
                                                                    $uid = 'freegletusd-' . basename($url);
                                                                    $this->dbhm->preExec("INSERT INTO communityevents_images (eventid, externaluid) VALUES (?,?);", [
                                                                        $eid,
                                                                        $uid
                                                                    ]);
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        error_log("No details found for event " . $event['id']);
                                                        \Sentry\captureMessage("No details found for event " . $event['id']);
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        error_log(
                                            "...Skip group " . $group['name'] . " because community events are disabled"
                                        );
                                    }
                                } else {
                                    error_log("No groups found for {$group_details['name']} {$lat},{$lng}");
                                    \Sentry\captureMessage("No postcode found for {$group_details['name']} {$lat},{$lng}");
                                }
                            } else {
                                #error_log("Skip group " . $group['name'] . " because " . $group_details['location']['country_code'] . " {$group_details['location']['lat']},{$group_details['location']['lng']}");
                            }
                        } else {
                            error_log("No details found for group " . $group['name']);
                            \Sentry\captureMessage("No details found for group " . $group['name']);
                        }
                    }
                }

                # Look for events which need removing because they aren't on Restart any more.
                $existings = $this->dbhr->preQuery("SELECT externalid FROM communityevents 
                    INNER JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid 
                    WHERE externalid LIKE 'Restart-%' AND start >= ?", [ $now ]);

                foreach ($existings as $e) {
                    if (!array_key_exists($e['externalid'], $externalsSeen)) {
                        error_log("...deleting old " . $e['externalid']);
                        $ce = new CommunityEvent($this->dbhr, $this->dbhm, $e['id']);
                        $ce->setPrivate('deleted', 1);
                    }
                }
            } else {
                error_log("No groups found");
                \Sentry\captureMessage("No groups found");
            }
        } else {
            error_log("Groups not found");
            \Sentry\captureMessage("Groups not found");
        }

        error_log("Added $added new events");
    }
}
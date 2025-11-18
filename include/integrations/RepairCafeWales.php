<?php

namespace Freegle\Iznik;

use ICal\ICal;

class RepairCafeWales {
    private $dbhr;
    private $dbhm;

    public function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm) {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function getUpcomingEvents() {
        $added = 0;
        $externalsSeen = [];
        $now = Utils::ISODate(date("Y-m-d"));

        try {
            $ical = new ICal();
            $ical->initUrl("https://repaircafewales.org/events/list/?ical=1");
            error_log("Got Repair Cafe Wales events");

            $events = $ical->eventsFromRange(date("Y-m-d 00:00:00"), date("Y-m-d 00:00:00", strtotime("+31 days")));

            if (!count($events)) {
                \Sentry\captureMessage("No Repair Cafe Wales events found");
            }

            foreach ($events as $event) {
                $externalid = $event->uid;
                $externalsSeen[$externalid] = TRUE;
                $title = $event->summary;
                $description = $event->description;
                $location = $event->location;
                $url = $event->url;
                $start = $ical->iCalDateToDateTime($event->dtstart_array[3]);
                $start = $start->format(\DateTime::ISO8601);
                $start = str_replace('+0000', 'Z', $start);
                $end = $ical->iCalDateToDateTime($event->dtend_array[3]);
                $end = $end->format(\DateTime::ISO8601);
                $end = str_replace('+0000', 'Z', $end);

                if (preg_match(Utils::POSTCODE_PATTERN, $location, $matches)) {
                    $postcode = strtoupper($matches[0]);
                    error_log("Found postcode $postcode");

                    // Find group near postcode.
                    $l = new Location($this->dbhr, $this->dbhm);
                    $lid = $l->findByName($postcode);
                    $l = new Location($this->dbhr, $this->dbhm, $lid);

                    if ($lid && $l->getPrivate('type') == 'Postcode') {
                        $groups = $l->groupsNear(Location::QUITENEARBY);

                        if (count($groups)) {
                            $g = Group::get($this->dbhr, $this->dbhm, $groups[0]);
                            error_log("Nearby group " . $g->getName());

                            // If group has community events enabled.
                            if ($g->getSetting('communityevent', 1)) {
                                $existing = $this->dbhr->preQuery(
                                    "SELECT * FROM communityevents WHERE externalid = ?",
                                    [$externalid]
                                );

                                if (count($existing)) {
                                    # Make sure the info is up to date.
                                    #
                                    # We don't update the photo as there is no good way to
                                    # check it hasn't changed.
                                    error_log("...updated existing " . $existing[0]['id']);
                                    $e = new CommunityEvent($this->dbhr, $this->dbhm, $existing[0]['id']);

                                    $pending = $e->getPrivate('title') != $title ||
                                        $e->getPrivate('location') != $location ||
                                        $e->getPrivate('description') != $description;

                                    if ($pending && !$e->getPrivate('pending')) {
                                        # Return to pending for re-review, in case the mod
                                        # edited these.
                                        $e->setPrivate('pending', 1);
                                    }

                                    $e->setPrivate('title', $title);
                                    $e->setPrivate('location', $location);
                                    $e->setPrivate('description', $description);
                                    $e->setPrivate('contacturl', $url);

                                    # Replace dates in case they have changed.
                                    $e->removeDates();
                                    $e->addDate($start, $end);
                                } else {
                                    $added++;
                                    # We don't - create it.
                                    $e = new CommunityEvent($this->dbhr, $this->dbhm);
                                    #create($userid, $title, $location, $contactname, $contactphone, $contactemail, $contacturl, $description, $photo = NULL, $externalid = NULL) {
                                    $eid = $e->create(
                                        null,
                                        $title,
                                        $location,
                                        null,
                                        null,
                                        null,
                                        $url,
                                        $description,
                                        null,
                                        $externalid
                                    );
                                    error_log("...created as $eid");

                                    $added++;

                                    $e->addGroup($g->getId());
                                    $e->addDate($start, $end);

                                    # Get an image if we can.
                                    $image = Utils::presdef('attach', $event->additionalProperties, NULL);

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
                            }
                        }
                    }
                }
            }


            # Look for events which need removing because they aren't on Restart any more.
            $existings = $this->dbhr->preQuery("SELECT communityevents.id, externalid FROM communityevents 
                    INNER JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid 
                    WHERE externalid LIKE '%repaircafewales%' AND start >= ?", [ $now ]);

            foreach ($existings as $e) {
                if (!array_key_exists($e['externalid'], $externalsSeen)) {
                    error_log("...deleting old " . $e['externalid']);
                    $ce = new CommunityEvent($this->dbhr, $this->dbhm, $e['id']);
                    $ce->setPrivate('deleted', 1);
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to process Repair Cafe Wales events " . $e->getMessage());
            \Sentry\captureException($e);
        }

        error_log("Added $added new events");
    }
}
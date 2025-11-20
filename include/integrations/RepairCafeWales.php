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

                $added += $this->processEvent($event, $ical, $externalid);
            }

            $this->removeOutdatedEvents($now, $externalsSeen);
        } catch (\Exception $e) {
            error_log("Failed to process Repair Cafe Wales events " . $e->getMessage());
            \Sentry\captureException($e);
        }

        error_log("Added $added new events");
    }

    private function processEvent($event, $ical, $externalid) {
        $eventData = $this->extractEventData($event, $ical);
        $postcode = $this->extractPostcode($eventData['location']);

        if (!$postcode) {
            return 0;
        }

        error_log("Found postcode $postcode");
        $group = $this->findNearbyGroup($postcode);

        if (!$group || !$group->getSetting('communityevent', 1)) {
            return 0;
        }

        return $this->createOrUpdateEvent(
            $externalid,
            $eventData['title'],
            $eventData['description'],
            $eventData['location'],
            $eventData['url'],
            $eventData['start'],
            $eventData['end'],
            $group,
            $event
        );
    }

    private function extractEventData($event, $ical) {
        return [
            'title' => $event->summary,
            'description' => $event->description,
            'location' => $event->location,
            'url' => $event->url,
            'start' => $this->formatDateTime($ical->iCalDateToDateTime($event->dtstart_array[3])),
            'end' => $this->formatDateTime($ical->iCalDateToDateTime($event->dtend_array[3]))
        ];
    }

    private function extractPostcode($location) {
        if (preg_match(Utils::POSTCODE_PATTERN, $location, $matches)) {
            return strtoupper($matches[0]);
        }
        return NULL;
    }

    private function formatDateTime($dateTime) {
        $formatted = $dateTime->format(\DateTime::ISO8601);
        return str_replace('+0000', 'Z', $formatted);
    }

    private function findNearbyGroup($postcode) {
        $l = new Location($this->dbhr, $this->dbhm);
        $lid = $l->findByName($postcode);
        $l = new Location($this->dbhr, $this->dbhm, $lid);

        if ($lid && $l->getPrivate('type') == 'Postcode') {
            $groups = $l->groupsNear(Location::QUITENEARBY);

            if (count($groups)) {
                $g = Group::get($this->dbhr, $this->dbhm, $groups[0]);
                error_log("Nearby group " . $g->getName());
                return $g;
            }
        }

        return NULL;
    }

    private function createOrUpdateEvent($externalid, $title, $description, $location, $url, $start, $end, $group, $event) {
        $existing = $this->dbhr->preQuery(
            "SELECT * FROM communityevents WHERE externalid = ?",
            [$externalid]
        );

        if (count($existing)) {
            $this->updateExistingEvent($existing[0]['id'], $title, $description, $location, $url, $start, $end);
            return 0;
        } else {
            return $this->createNewEvent($title, $description, $location, $url, $start, $end, $externalid, $group, $event);
        }
    }

    private function updateExistingEvent($eventId, $title, $description, $location, $url, $start, $end) {
        error_log("...updated existing " . $eventId);
        $e = new CommunityEvent($this->dbhr, $this->dbhm, $eventId);

        if ($this->hasEventChanged($e, $title, $description, $location) && !$e->getPrivate('pending')) {
            $e->setPrivate('pending', 1);
        }

        $e->setPrivate('title', $title);
        $e->setPrivate('location', $location);
        $e->setPrivate('description', $description);
        $e->setPrivate('contacturl', $url);

        $e->removeDates();
        $e->addDate($start, $end);
    }

    private function createNewEvent($title, $description, $location, $url, $start, $end, $externalid, $group, $event) {
        $e = new CommunityEvent($this->dbhr, $this->dbhm);
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

        $e->addGroup($group->getId());
        $e->addDate($start, $end);

        $this->addEventImage($eid, $event);

        return 1;
    }

    private function addEventImage($eid, $event) {
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

    private function hasEventChanged($event, $title, $description, $location) {
        return $event->getPrivate('title') != $title ||
               $event->getPrivate('location') != $location ||
               $event->getPrivate('description') != $description;
    }

    private function removeOutdatedEvents($now, $externalsSeen) {
        $existings = $this->dbhr->preQuery(
            "SELECT communityevents.id, externalid FROM communityevents
             INNER JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid
             WHERE externalid LIKE '%repaircafewales%' AND start >= ?",
            [$now]
        );

        foreach ($existings as $e) {
            if (array_key_exists($e['externalid'], $externalsSeen)) {
                continue;
            }

            error_log("...deleting old " . $e['externalid']);
            $ce = new CommunityEvent($this->dbhr, $this->dbhm, $e['id']);
            $ce->setPrivate('deleted', 1);
        }
    }
}
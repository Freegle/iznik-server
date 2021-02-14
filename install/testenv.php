<?php

namespace Freegle\Iznik;

# Set up gridids for locations already in the locations table; you might do this after importing a bunch of locations
# from a source such as OpenStreetMap (OSM).
require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$g = new Group($dbhr, $dbhm);
$gid = $g->findByShortName('FreeglePlayground');

if (!$gid) {
    # Not set up yet.
    error_log("Set up test environment");
    $gid = $g->create('FreeglePlayground', Group::GROUP_FREEGLE);
    $g->setPrivate('onhere', 1);
    $g->setPrivate('polyofficial', 'POLYGON((-3.1902622 55.9910847, -3.2472542 55.98263430000001, -3.2863922 55.9761038, -3.3159182 55.9522754, -3.3234712 55.9265089, -3.304932200000001 55.911888, -3.3742832 55.8880206, -3.361237200000001 55.8718436, -3.3282782 55.8729997, -3.2520602 55.8964911, -3.2177282 55.895336, -3.2060552 55.8903307, -3.1538702 55.88648049999999, -3.1305242 55.893411, -3.0989382 55.8972611, -3.0680392 55.9091938, -3.0584262 55.9215076, -3.0982522 55.928048, -3.1037452 55.9418938, -3.1236572 55.9649602, -3.168289199999999 55.9849393, -3.1902622 55.9910847))');
    $g->setPrivate('lat', 55.9533);
    $g->setPrivate('lng', 3.1883);

    $l = new Location($dbhr, $dbhm);
    $areaid = $l->create(NULL, 'Central', 'Polygon', 'POLYGON((-3.217620849609375 55.9565040997114,-3.151702880859375 55.9565040997114,-3.151702880859375 55.93304863776238,-3.217620849609375 55.93304863776238,-3.217620849609375 55.9565040997114))', 0);
    $pcid = $l->create(NULL, 'EH3 6SS', 'Postcode', 'POINT(-3.205333 55.957571)', 0);

    $u = new User($dbhr, $dbhm);
    $u->create('Test', 'User', 'Test User');
    $u->addEmail('test@test.com');
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

    $i = new Item($dbhr, $dbhm);
    $i->create('chair');

    $dbhm->preExec("INSERT ignore INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (8, 'viagra', NULL, 'Spam', 'Literal'), (76, 'weight loss', NULL, 'Spam', 'Literal'), (77, 'spamspamspam', NULL, 'Review', 'Literal');");
    $dbhm->preExec('REPLACE INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (272, \'(?<!\\\\bwater\\\\W)\\\\bbutt\\\\b(?!\\\\s+rd)\', NULL, \'Review\', \'Regex\');');
    $dbhm->preExec("INSERT INTO `locations` (`id`, `osm_id`, `name`, `type`, `osm_place`, `geometry`, `ourgeometry`, `gridid`, `postcodeid`, `areaid`, `canon`, `popularity`, `osm_amenity`, `osm_shop`, `maxdimension`, `lat`, `lng`, `timestamp`) VALUES
      (1687412, '189543628', 'SA65 9ET', 'Postcode', 0, GeomFromText('POINT(-4.939858 52.006292)'), NULL, NULL, NULL, NULL, 'sa659et', 0, 0, 0, '0.002916', '52.006292', '-4.939858', '2016-08-23 06:01:25');
      INSERT INTO `paf_addresses` (`id`, `postcodeid`) VALUES   (102367696, 1687412);
    ");
    $dbhm->preExec("INSERT INTO weights (name, simplename, weight, source) VALUES ('2 seater sofa', 'sofa', 37, 'FRN 2009');");
    $dbhm->preExec("INSERT INTO spam_countries (country) VALUES ('Cameroon');");
    $dbhm->preExec("INSERT INTO spam_whitelist_links (domain, count) VALUES ('users.ilovefreegle.org', 3);");
    $dbhm->preExec("INSERT INTO spam_whitelist_links (domain, count) VALUES ('freegle.in', 3);");

    $dbhm->preExec("INSERT INTO towns (name, lat, lng, position) VALUES ('Edinburgh', 55.9500,-3.2000, GeomFromText('POINT (-3.2000 55.9500)'));");

    $dbhm->preExec("INSERT INTO `engage_mails` (`id`, `engagement`, `template`, `subject`, `text`, `shown`, `action`, `rate`, `suggest`) VALUES
(1, 'AtRisk', 'inactive', 'We\'ll stop sending you emails soon...', 'It looks like you’ve not been active on Freegle for a while. So that we don’t clutter your inbox, and to reduce the load on our servers, we’ll stop sending you emails soon.\n\nIf you’d still like to get them, then just go to www.ilovefreegle.org and log in to keep your account active.\n\nMaybe you’ve got something lying around that someone else could use, or perhaps there’s something someone else might have?', 249, 14, '5.62', 1),
(4, 'Inactive', 'missing', 'We miss you!', 'We don\'t think you\'ve freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org', 4681, 63, '1.35', 1),
(7, 'AtRisk', 'inactive', 'Do you want to keep receiving Freegle mails?', 'It looks like you’ve not been active on Freegle for a while. So that we don’t clutter your inbox, and to reduce the load on our servers, we’ll stop sending you emails soon.\r\n\r\nIf you’d still like to get them, then just go to www.ilovefreegle.org and log in to keep your account active.\r\n\r\nMaybe you’ve got something lying around that someone else could use, or perhaps there’s something someone else might have?', 251, 8, '3.19', 1),
(10, 'Inactive', 'missing', 'Time for a declutter?', 'We don\'t think you\'ve freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org', 1257, 8, '0.64', 1),
(13, 'Inactive', 'missing', 'Anything Freegle can help you get?', 'We don\'t think you\'ve freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org', 1366, 5, '0.37', 1);
");

    $dbhm->preExec("INSERT INTO `search_terms` (`id`, `term`, `count`) VALUES
(3, '', 92),
(6, '_term', 1),
(9, '-', 1),
(12, '- offer: blue badge road atlas', 1),
(15, '-- end of posted message. the following text has been added by group moderators ', 2),
(18, '-wanted', 2),
(21, ',', 6),
(24, ', ,garden tools', 1),
(27, ', dinning table ,curtains', 1),
(30, ',:', 1),
(33, ',curtains', 1),
(36, ',guitar', 1),
(39, ',ixer', 1),
(42, ':', 1),
(45, ': offered: luxury xmas jigsaw (cv2)', 2),
(48, '?', 1),
(51, '?rollater', 2),
(54, '?rolletar', 1),
(57, '.', 16),
(60, '. beds', 1);");

    $dbhm->preExec("INSERT INTO jobs_keywords (keyword, count) VALUES ('worker', 1);");
} else {
    error_log("Test environment already set up.");
}


-- phpMyAdmin SQL Dump
-- version 4.5.4.1deb2ubuntu2
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: May 18, 2018 at 07:14 PM
-- Server version: 5.7.20-18-57
-- PHP Version: 7.0.25-0ubuntu0.16.04.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Database: `iznik`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`app2` PROCEDURE `ANALYZE_INVALID_FOREIGN_KEYS` (`checked_database_name` VARCHAR(64), `checked_table_name` VARCHAR(64), `temporary_result_table` ENUM('Y','N'))  READS SQL DATA
  BEGIN
    DECLARE TABLE_SCHEMA_VAR VARCHAR(64);
    DECLARE TABLE_NAME_VAR VARCHAR(64);
    DECLARE COLUMN_NAME_VAR VARCHAR(64);
    DECLARE CONSTRAINT_NAME_VAR VARCHAR(64);
    DECLARE REFERENCED_TABLE_SCHEMA_VAR VARCHAR(64);
    DECLARE REFERENCED_TABLE_NAME_VAR VARCHAR(64);
    DECLARE REFERENCED_COLUMN_NAME_VAR VARCHAR(64);
    DECLARE KEYS_SQL_VAR VARCHAR(1024);

    DECLARE done INT DEFAULT 0;

    DECLARE foreign_key_cursor CURSOR FOR
      SELECT
        `TABLE_SCHEMA`,
        `TABLE_NAME`,
        `COLUMN_NAME`,
        `CONSTRAINT_NAME`,
        `REFERENCED_TABLE_SCHEMA`,
        `REFERENCED_TABLE_NAME`,
        `REFERENCED_COLUMN_NAME`
      FROM
        information_schema.KEY_COLUMN_USAGE
      WHERE
        `CONSTRAINT_SCHEMA` LIKE checked_database_name AND
        `TABLE_NAME` LIKE checked_table_name AND
        `REFERENCED_TABLE_SCHEMA` IS NOT NULL;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    IF temporary_result_table = 'N' THEN
      DROP TEMPORARY TABLE IF EXISTS INVALID_FOREIGN_KEYS;
      DROP TABLE IF EXISTS INVALID_FOREIGN_KEYS;

      CREATE TABLE INVALID_FOREIGN_KEYS(
        `TABLE_SCHEMA` VARCHAR(64),
        `TABLE_NAME` VARCHAR(64),
        `COLUMN_NAME` VARCHAR(64),
        `CONSTRAINT_NAME` VARCHAR(64),
        `REFERENCED_TABLE_SCHEMA` VARCHAR(64),
        `REFERENCED_TABLE_NAME` VARCHAR(64),
        `REFERENCED_COLUMN_NAME` VARCHAR(64),
        `INVALID_KEY_COUNT` INT,
        `INVALID_KEY_SQL` VARCHAR(1024)
      );
    ELSEIF temporary_result_table = 'Y' THEN
      DROP TEMPORARY TABLE IF EXISTS INVALID_FOREIGN_KEYS;
      DROP TABLE IF EXISTS INVALID_FOREIGN_KEYS;

      CREATE TEMPORARY TABLE INVALID_FOREIGN_KEYS(
        `TABLE_SCHEMA` VARCHAR(64),
        `TABLE_NAME` VARCHAR(64),
        `COLUMN_NAME` VARCHAR(64),
        `CONSTRAINT_NAME` VARCHAR(64),
        `REFERENCED_TABLE_SCHEMA` VARCHAR(64),
        `REFERENCED_TABLE_NAME` VARCHAR(64),
        `REFERENCED_COLUMN_NAME` VARCHAR(64),
        `INVALID_KEY_COUNT` INT,
        `INVALID_KEY_SQL` VARCHAR(1024)
      );
    END IF;


    OPEN foreign_key_cursor;
    foreign_key_cursor_loop: LOOP
      FETCH foreign_key_cursor INTO
        TABLE_SCHEMA_VAR,
        TABLE_NAME_VAR,
        COLUMN_NAME_VAR,
        CONSTRAINT_NAME_VAR,
        REFERENCED_TABLE_SCHEMA_VAR,
        REFERENCED_TABLE_NAME_VAR,
        REFERENCED_COLUMN_NAME_VAR;
      IF done THEN
        LEAVE foreign_key_cursor_loop;
      END IF;


      SET @from_part = CONCAT('FROM ', '`', TABLE_SCHEMA_VAR, '`.`', TABLE_NAME_VAR, '`', ' AS REFERRING ',
                              'LEFT JOIN `', REFERENCED_TABLE_SCHEMA_VAR, '`.`', REFERENCED_TABLE_NAME_VAR, '`', ' AS REFERRED ',
                              'ON (REFERRING', '.`', COLUMN_NAME_VAR, '`', ' = ', 'REFERRED', '.`', REFERENCED_COLUMN_NAME_VAR, '`', ') ',
                              'WHERE REFERRING', '.`', COLUMN_NAME_VAR, '`', ' IS NOT NULL ',
                              'AND REFERRED', '.`', REFERENCED_COLUMN_NAME_VAR, '`', ' IS NULL');
      SET @full_query = CONCAT('SELECT COUNT(*) ', @from_part, ' INTO @invalid_key_count;');
      PREPARE stmt FROM @full_query;

      EXECUTE stmt;
      IF @invalid_key_count > 0 THEN
        INSERT INTO
          INVALID_FOREIGN_KEYS
        SET
          `TABLE_SCHEMA` = TABLE_SCHEMA_VAR,
          `TABLE_NAME` = TABLE_NAME_VAR,
          `COLUMN_NAME` = COLUMN_NAME_VAR,
          `CONSTRAINT_NAME` = CONSTRAINT_NAME_VAR,
          `REFERENCED_TABLE_SCHEMA` = REFERENCED_TABLE_SCHEMA_VAR,
          `REFERENCED_TABLE_NAME` = REFERENCED_TABLE_NAME_VAR,
          `REFERENCED_COLUMN_NAME` = REFERENCED_COLUMN_NAME_VAR,
          `INVALID_KEY_COUNT` = @invalid_key_count,
          `INVALID_KEY_SQL` = CONCAT('SELECT ',
                                     'REFERRING.', '`', COLUMN_NAME_VAR, '` ', 'AS "Invalid: ', COLUMN_NAME_VAR, '", ',
                                     'REFERRING.* ',
                                     @from_part, ';');
      END IF;
      DEALLOCATE PREPARE stmt;

    END LOOP foreign_key_cursor_loop;
  END$$

CREATE DEFINER=`root`@`app2` PROCEDURE `ANALYZE_INVALID_UNIQUE_KEYS` (`checked_database_name` VARCHAR(64), `checked_table_name` VARCHAR(64))  READS SQL DATA
  BEGIN
    DECLARE TABLE_SCHEMA_VAR VARCHAR(64);
    DECLARE TABLE_NAME_VAR VARCHAR(64);
    DECLARE COLUMN_NAMES_VAR VARCHAR(1000);
    DECLARE CONSTRAINT_NAME_VAR VARCHAR(64);

    DECLARE done INT DEFAULT 0;

    DECLARE unique_key_cursor CURSOR FOR
      select kcu.table_schema sch,
             kcu.table_name tbl,
             group_concat(kcu.column_name) colName,
             kcu.constraint_name constName
      from
        information_schema.table_constraints tc
        join
        information_schema.key_column_usage kcu
          on
            kcu.constraint_name=tc.constraint_name
            and kcu.constraint_schema=tc.constraint_schema
            and kcu.table_name=tc.table_name
      where
        kcu.table_schema like checked_database_name
        and kcu.table_name like checked_table_name
        and tc.constraint_type="UNIQUE" group by sch, tbl, constName;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DROP TEMPORARY TABLE IF EXISTS INVALID_UNIQUE_KEYS;
    CREATE TEMPORARY TABLE INVALID_UNIQUE_KEYS(
      `TABLE_SCHEMA` VARCHAR(64),
      `TABLE_NAME` VARCHAR(64),
      `COLUMN_NAMES` VARCHAR(1000),
      `CONSTRAINT_NAME` VARCHAR(64),
      `INVALID_KEY_COUNT` INT
    );



    OPEN unique_key_cursor;
    unique_key_cursor_loop: LOOP
      FETCH unique_key_cursor INTO
        TABLE_SCHEMA_VAR,
        TABLE_NAME_VAR,
        COLUMN_NAMES_VAR,
        CONSTRAINT_NAME_VAR;
      IF done THEN
        LEAVE unique_key_cursor_loop;
      END IF;

      SET @from_part = CONCAT('FROM (SELECT COUNT(*) counter FROM', '`', TABLE_SCHEMA_VAR, '`.`', TABLE_NAME_VAR, '`',
                              ' GROUP BY ', COLUMN_NAMES_VAR , ') as s where s.counter > 1');
      SET @full_query = CONCAT('SELECT COUNT(*) ', @from_part, ' INTO @invalid_key_count;');
      PREPARE stmt FROM @full_query;
      EXECUTE stmt;
      IF @invalid_key_count > 0 THEN
        INSERT INTO
          INVALID_UNIQUE_KEYS
        SET
          `TABLE_SCHEMA` = TABLE_SCHEMA_VAR,
          `TABLE_NAME` = TABLE_NAME_VAR,
          `COLUMN_NAMES` = COLUMN_NAMES_VAR,
          `CONSTRAINT_NAME` = CONSTRAINT_NAME_VAR,
          `INVALID_KEY_COUNT` = @invalid_key_count;
      END IF;
      DEALLOCATE PREPARE stmt;

    END LOOP unique_key_cursor_loop;
  END$$

--
-- Functions
--
CREATE DEFINER=`root`@`app2` FUNCTION `GetCenterPoint` (`g` GEOMETRY) RETURNS POINT NO SQL
DETERMINISTIC
  BEGIN
    DECLARE envelope POLYGON;
    DECLARE sw, ne POINT;
    DECLARE lat, lng DOUBLE;

    SET envelope = ExteriorRing(Envelope(g));
    SET sw = PointN(envelope, 1);
    SET ne = PointN(envelope, 3);
    SET lat = X(sw) + (X(ne)-X(sw))/2;
    SET lng = Y(sw) + (Y(ne)-Y(sw))/2;
    RETURN POINT(lat, lng);
  END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `GetMaxDimension` (`g` GEOMETRY) RETURNS DOUBLE NO SQL
DETERMINISTIC
  BEGIN
    DECLARE area, radius, diag DOUBLE;

    SET area = AREA(g);
    SET radius = SQRT(area / PI());
    SET diag = SQRT(radius * radius * 2);
    RETURN(diag);

    /* Previous implementation returns odd geometry exceptions
    DECLARE envelope POLYGON;
    DECLARE sw, ne POINT;
    DECLARE xsize, ysize DOUBLE;

    DECLARE EXIT HANDLER FOR 1416
      RETURN(10000);

    SET envelope = ExteriorRing(Envelope(g));
    SET sw = PointN(envelope, 1);
    SET ne = PointN(envelope, 3);
    SET xsize = X(ne) - X(sw);
    SET ysize = Y(ne) - Y(sw);
    RETURN(GREATEST(xsize, ysize)); */
  END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `GetMaxDimensionT` (`g` GEOMETRY) RETURNS DOUBLE NO SQL
  BEGIN
    DECLARE area, radius, diag DOUBLE;

    SET area = AREA(g);
    SET radius = SQRT(area / PI());
    SET diag = SQRT(radius * radius * 2);
    RETURN(diag);
  END$$

CREATE DEFINER=`root`@`app2` FUNCTION `haversine` (`lat1` FLOAT, `lon1` FLOAT, `lat2` FLOAT, `lon2` FLOAT) RETURNS FLOAT NO SQL
DETERMINISTIC
  COMMENT 'Returns the distance in degrees on the Earth\n             between two known points of latitude and longitude'
  BEGIN
    RETURN 69 * DEGREES(ACOS(
                            COS(RADIANS(lat1)) *
                            COS(RADIANS(lat2)) *
                            COS(RADIANS(lon2) - RADIANS(lon1)) +
                            SIN(RADIANS(lat1)) * SIN(RADIANS(lat2))
                        ));
  END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `ST_IntersectionSafe` (`a` GEOMETRY, `b` GEOMETRY) RETURNS GEOMETRY BEGIN
  DECLARE ret GEOMETRY;
  DECLARE CONTINUE HANDLER FOR SQLSTATE '22023'
  BEGIN
    SET ret = POINT(0.0000,90.0000);
  END;
  SELECT ST_Intersection(a, b) INTO ret;
  RETURN ret;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `abtest`
--

CREATE TABLE `abtest` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uid` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shown` bigint(20) UNSIGNED NOT NULL,
  `action` bigint(20) UNSIGNED NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `suggest` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For testing site changes to see which work';

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `createdby` bigint(20) UNSIGNED DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `complete` timestamp NULL DEFAULT NULL,
  `subject` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Try all means to reach people with these';

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `createdby` bigint(20) UNSIGNED DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `from` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to` enum('Users','Mods') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Mods',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `groupprogress` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'For alerts to multiple groups',
  `complete` timestamp NULL DEFAULT NULL,
  `subject` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `askclick` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether to ask them to click to confirm receipt',
  `tryhard` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Whether to mail all mods addresses too'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Try all means to reach people with these';

-- --------------------------------------------------------

--
-- Table structure for table `alerts_tracking`
--

CREATE TABLE `alerts_tracking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `alertid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `emailid` bigint(20) UNSIGNED DEFAULT NULL,
  `type` enum('ModEmail','OwnerEmail','PushNotif','ModToolsNotif') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL,
  `response` enum('Read','Clicked','Bounce','Unsubscribe') COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `authorities`
--

CREATE TABLE `authorities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `polygon` geometry NOT NULL,
  `area_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `simplified` geometry DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Counties and Unitary Authorities.  May be multigeometries';

-- --------------------------------------------------------

--
-- Table structure for table `aviva_history`
--

CREATE TABLE `aviva_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `position` int(11) NOT NULL,
  `votes` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `aviva_votes`
--

CREATE TABLE `aviva_votes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `project` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `votes` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bounces`
--

CREATE TABLE `bounces` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `to` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `msg` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bounce messages received by email';

-- --------------------------------------------------------

--
-- Table structure for table `bounces_emails`
--

CREATE TABLE `bounces_emails` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `emailid` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `permanent` tinyint(1) NOT NULL DEFAULT '0',
  `reset` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'If we have reset bounces for this email'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_images`
--

CREATE TABLE `chat_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chatmsgid` bigint(20) UNSIGNED DEFAULT NULL,
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chatid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL COMMENT 'From',
  `type` enum('Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Default',
  `reportreason` enum('Spam','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refmsgid` bigint(20) UNSIGNED DEFAULT NULL,
  `refchatid` bigint(20) UNSIGNED DEFAULT NULL,
  `imageid` bigint(20) UNSIGNED DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `message` text COLLATE utf8mb4_unicode_ci,
  `platform` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Whether this was created on the platform vs email',
  `seenbyall` tinyint(1) NOT NULL DEFAULT '0',
  `mailedtoall` tinyint(1) NOT NULL DEFAULT '0',
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether a volunteer should review before it''s passed on',
  `reviewedby` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User id of volunteer who reviewed it',
  `reviewrejected` tinyint(1) NOT NULL DEFAULT '0',
  `spamscore` int(11) DEFAULT NULL COMMENT 'SpamAssassin score for mail replies',
  `facebookid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduleid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages_byemail`
--

CREATE TABLE `chat_messages_byemail` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chatmsgid` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages_held`
--

CREATE TABLE `chat_messages_held` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_rooms`
--

CREATE TABLE `chat_rooms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chattype` enum('Mod2Mod','User2Mod','User2User','Group') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User2User',
  `groupid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Restricted to a group',
  `user1` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'For DMs',
  `user2` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'For DMs',
  `description` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `synctofacebook` enum('Dont','RepliedOnFacebook','RepliedOnPlatform','PostedLink') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dont',
  `synctofacebookgroupid` bigint(20) UNSIGNED DEFAULT NULL,
  `latestmessage` timestamp NULL DEFAULT NULL COMMENT 'Loosely up to date - cron',
  `msgvalid` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `msginvalid` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `flaggedspam` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_roster`
--

CREATE TABLE `chat_roster` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chatid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Online','Away','Offline','Closed','Blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Online',
  `lastmsgseen` bigint(20) UNSIGNED DEFAULT NULL,
  `lastemailed` timestamp NULL DEFAULT NULL,
  `lastmsgemailed` bigint(20) UNSIGNED DEFAULT NULL,
  `lastip` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `communityevents`
--

CREATE TABLE `communityevents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `pending` tinyint(1) NOT NULL DEFAULT '0',
  `title` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `contactname` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactphone` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactemail` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacturl` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted` tinyint(4) NOT NULL DEFAULT '0',
  `legacyid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'For migration from FDv1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `communityevents_dates`
--

CREATE TABLE `communityevents_dates` (
  `id` bigint(20) NOT NULL,
  `eventid` bigint(20) UNSIGNED NOT NULL,
  `start` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `end` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `communityevents_groups`
--

CREATE TABLE `communityevents_groups` (
  `eventid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `communityevents_images`
--

CREATE TABLE `communityevents_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `eventid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the community events table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `ebay_favourites`
--

CREATE TABLE `ebay_favourites` (
  `id` bigint(20) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `count` int(11) NOT NULL,
  `rival` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique ID of group',
  `legacyid` bigint(20) UNSIGNED DEFAULT NULL COMMENT '(Freegle) Groupid on old system',
  `nameshort` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A short name for the group',
  `namefull` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A longer name for the group',
  `nameabbr` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'An abbreviated name for the group',
  `namealt` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Alternative name, e.g. as used by GAT',
  `settings` longtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON-encoded settings for group',
  `type` set('Reuse','Freegle','Other','UnitTest') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other' COMMENT 'High-level characteristics of the group',
  `region` enum('East','East Midlands','West Midlands','North East','North West','Northern Ireland','South East','South West','London','Wales','Yorkshire and the Humber','Scotland') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Freegle only',
  `authorityid` bigint(20) UNSIGNED DEFAULT NULL,
  `onyahoo` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether this group is also on Yahoo Groups',
  `onhere` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Whether this group is available on this platform',
  `ontn` tinyint(1) NOT NULL DEFAULT '0',
  `showonyahoo` tinyint(1) NOT NULL DEFAULT '1' COMMENT '(Freegle) Whether to show Yahoo links',
  `lastyahoomembersync` timestamp NULL DEFAULT NULL COMMENT 'When we last synced approved members',
  `lastyahoomessagesync` timestamp NULL DEFAULT NULL COMMENT 'When we last synced approved messages',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `poly` longtext COLLATE utf8mb4_unicode_ci COMMENT 'Any polygon defining core area',
  `polyofficial` longtext COLLATE utf8mb4_unicode_ci COMMENT 'If present, GAT area and poly is catchment',
  `confirmkey` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Key used to verify some operations by email',
  `publish` tinyint(4) NOT NULL DEFAULT '1' COMMENT '(Freegle) Whether this group is visible to members',
  `listable` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether shows up in groups API call',
  `onmap` tinyint(4) NOT NULL DEFAULT '1' COMMENT '(Freegle) Whether to show on the map of groups',
  `licenserequired` tinyint(4) DEFAULT '1' COMMENT 'Whether a license is required for this group',
  `trial` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'For ModTools, when a trial was started',
  `licensed` date DEFAULT NULL COMMENT 'For ModTools, when a group was licensed',
  `licenseduntil` date DEFAULT NULL COMMENT 'For ModTools, when a group is licensed until',
  `membercount` int(11) NOT NULL DEFAULT '0' COMMENT 'Automatically refreshed',
  `modcount` int(11) NOT NULL DEFAULT '0',
  `profile` bigint(20) UNSIGNED DEFAULT NULL,
  `cover` bigint(20) UNSIGNED DEFAULT NULL,
  `tagline` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '(Freegle) One liner slogan for this group',
  `description` text COLLATE utf8mb4_unicode_ci,
  `founded` date DEFAULT NULL,
  `lasteventsroundup` timestamp NULL DEFAULT NULL COMMENT '(Freegle) Last event roundup sent',
  `lastvolunteeringroundup` timestamp NULL DEFAULT NULL,
  `external` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Link to some other system e.g. Norfolk',
  `contactmail` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For external sites',
  `welcomemail` text COLLATE utf8mb4_unicode_ci COMMENT '(Freegle) Text for welcome mail',
  `activitypercent` decimal(10,2) DEFAULT NULL COMMENT 'Within a group type, the proportion of overall activity that this group accounts for.',
  `fundingtarget` int(11) NOT NULL DEFAULT '0',
  `lastmoderated` timestamp NULL DEFAULT NULL COMMENT 'Last moderated inc Yahoo',
  `lastmodactive` timestamp NULL DEFAULT NULL COMMENT 'Last mod active on here',
  `activemodcount` int(11) DEFAULT NULL COMMENT 'How many currently active mods',
  `backupownersactive` int(11) NOT NULL DEFAULT '0',
  `backupmodsactive` int(11) NOT NULL DEFAULT '0',
  `lastautoapprove` timestamp NULL DEFAULT NULL,
  `affiliationconfirmed` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='The different groups that we host' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `groups_digests`
--

CREATE TABLE `groups_digests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `frequency` int(11) NOT NULL,
  `msgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Which message we got upto when sending',
  `msgdate` timestamp(6) NULL DEFAULT NULL COMMENT 'Arrival of message we have sent upto',
  `started` timestamp NULL DEFAULT NULL,
  `ended` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `groups_facebook`
--

CREATE TABLE `groups_facebook` (
  `uid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Page','Group') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Page',
  `id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text COLLATE utf8mb4_unicode_ci,
  `authdate` timestamp NULL DEFAULT NULL,
  `msgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Last message posted',
  `msgarrival` timestamp NULL DEFAULT NULL COMMENT 'Time of last message posted',
  `eventid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Last event tweeted',
  `valid` tinyint(4) NOT NULL DEFAULT '1',
  `lasterror` text COLLATE utf8mb4_unicode_ci,
  `lasterrortime` timestamp NULL DEFAULT NULL,
  `sharefrom` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT '134117207097' COMMENT 'Facebook page to republish from',
  `lastupdated` timestamp NULL DEFAULT NULL COMMENT 'From Graph API',
  `postablecount` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups_facebook_shares`
--

CREATE TABLE `groups_facebook_shares` (
  `uid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `postid` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Shared','Hidden','','') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Shared'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups_facebook_toshare`
--

CREATE TABLE `groups_facebook_toshare` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sharefrom` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Page to share from',
  `postid` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Facebook postid',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores central posts for sharing out to group pages';

-- --------------------------------------------------------

--
-- Table structure for table `groups_images`
--

CREATE TABLE `groups_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the groups table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `groups_twitter`
--

CREATE TABLE `groups_twitter` (
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `authdate` timestamp NULL DEFAULT NULL,
  `msgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Last message tweeted',
  `msgarrival` timestamp NULL DEFAULT NULL,
  `eventid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Last event tweeted',
  `valid` tinyint(4) NOT NULL DEFAULT '1',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `lasterror` text COLLATE utf8mb4_unicode_ci,
  `lasterrortime` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` int(11) NOT NULL DEFAULT '0',
  `weight` decimal(10,2) DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `suggestfromphoto` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'We can exclude from image recognition',
  `suggestfromtypeahead` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'We can exclude from typeahead'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `items_index`
--

CREATE TABLE `items_index` (
  `itemid` bigint(20) UNSIGNED NOT NULL,
  `wordid` bigint(20) UNSIGNED NOT NULL,
  `popularity` int(11) NOT NULL DEFAULT '0',
  `categoryid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `items_non`
--

CREATE TABLE `items_non` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` int(11) NOT NULL DEFAULT '1',
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastexample` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Not considered items by us, but by image recognition' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `link_previews`
--

CREATE TABLE `link_previews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invalid` tinyint(1) NOT NULL DEFAULT '0',
  `spam` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `osm_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Road','Polygon','Line','Point','Postcode') COLLATE utf8mb4_unicode_ci NOT NULL,
  `osm_place` tinyint(1) DEFAULT '0',
  `geometry` geometry DEFAULT NULL,
  `ourgeometry` geometry DEFAULT NULL COMMENT 'geometry comes from OSM; this comes from us',
  `gridid` bigint(20) UNSIGNED DEFAULT NULL,
  `postcodeid` bigint(20) UNSIGNED DEFAULT NULL,
  `areaid` bigint(20) UNSIGNED DEFAULT NULL,
  `canon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `popularity` bigint(20) UNSIGNED DEFAULT '0',
  `osm_amenity` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'For OSM locations, whether this is an amenity',
  `osm_shop` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'For OSM locations, whether this is a shop',
  `maxdimension` decimal(10,6) DEFAULT NULL COMMENT 'GetMaxDimension on geomtry',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Location data, the bulk derived from OSM' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `locations_excluded`
--

CREATE TABLE `locations_excluded` (
  `locationid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stops locations being suggested on a group' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `locations_grids`
--

CREATE TABLE `locations_grids` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `swlat` decimal(10,6) NOT NULL,
  `swlng` decimal(10,6) NOT NULL,
  `nelat` decimal(10,6) NOT NULL,
  `nelng` decimal(10,6) NOT NULL,
  `box` geometry NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to map lat/lng to gridid for location searches' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `locations_grids_touches`
--

CREATE TABLE `locations_grids_touches` (
  `gridid` bigint(20) UNSIGNED NOT NULL,
  `touches` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A record of which grid squares touch others' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `locations_spatial`
--

CREATE TABLE `locations_spatial` (
  `locationid` bigint(20) UNSIGNED NOT NULL,
  `geometry` geometry NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logos`
--

CREATE TABLE `logos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique ID',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Machine assumed set to GMT',
  `byuser` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User responsible for action, if any',
  `type` enum('Group','Message','User','Plugin','Config','StdMsg','Location','BulkOp') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtype` enum('Created','Deleted','Received','Sent','Failure','ClassifiedSpam','Joined','Left','Approved','Rejected','YahooDeliveryType','YahooPostingStatus','NotSpam','Login','Hold','Release','Edit','RoleChange','Merged','Split','Replied','Mailed','Applied','Suspect','Licensed','LicensePurchase','YahooApplied','YahooConfirmed','YahooJoined','MailOff','EventsOff','NewslettersOff','RelevantOff','Logout','Bounce','SuspendMail','Autoreposted','Outcome','OurPostingStatus','OurPostingStatus','VolunteersOff','Autoapproved','Unbounce') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Any group this log is for',
  `user` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Any user that this log is about',
  `msgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the messages table',
  `configid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the mod_configs table',
  `stdmsgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Any stdmsg for this log',
  `bulkopid` bigint(20) UNSIGNED DEFAULT NULL,
  `text` mediumtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs.  Not guaranteed against loss' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `logs_api`
--

CREATE TABLE `logs_api` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userid` bigint(20) DEFAULT NULL,
  `ip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` longtext COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log of all API requests and responses' KEY_BLOCK_SIZE=8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `logs_emails`
--

CREATE TABLE `logs_emails` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eximid` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `from` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messageid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logs_errors`
--

CREATE TABLE `logs_errors` (
  `id` bigint(20) NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Exception') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) DEFAULT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Errors from client';

-- --------------------------------------------------------

--
-- Table structure for table `logs_events`
--

CREATE TABLE `logs_events` (
  `id` bigint(20) NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `sessionid` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `logs_profile`
--

CREATE TABLE `logs_profile` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `caller` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `callee` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ct` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `wt` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `cpu` bigint(20) UNSIGNED NOT NULL,
  `mu` bigint(20) UNSIGNED NOT NULL,
  `pmu` bigint(20) UNSIGNED NOT NULL,
  `alloc` bigint(20) UNSIGNED NOT NULL,
  `free` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logs_sql`
--

CREATE TABLE `logs_sql` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duration` decimal(15,10) UNSIGNED DEFAULT '0.0000000000' COMMENT 'seconds',
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'rc:lastInsertId'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log of modification SQL operations' KEY_BLOCK_SIZE=8 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `logs_src`
--

CREATE TABLE `logs_src` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `src` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Record which mails we sent generated website traffic';

-- --------------------------------------------------------

--
-- Table structure for table `memberships`
--

CREATE TABLE `memberships` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `role` enum('Member','Moderator','Owner') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `collection` enum('Approved','Pending','Banned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `configid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Configuration used to moderate this group if a moderator',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settings` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Other group settings, e.g. for moderators',
  `syncdelete` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Used during member sync',
  `heldby` bigint(20) UNSIGNED DEFAULT NULL,
  `emailfrequency` int(11) NOT NULL DEFAULT '24' COMMENT 'In hours; -1 immediately, 0 never',
  `eventsallowed` tinyint(1) DEFAULT '1',
  `volunteeringallowed` bigint(20) NOT NULL DEFAULT '1',
  `ourPostingStatus` enum('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, NULL; for ours, the posting status'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Which groups users are members of' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `memberships_history`
--

CREATE TABLE `memberships_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `collection` enum('Approved','Pending','Banned') COLLATE utf8mb4_unicode_ci NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to spot multijoiners' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `memberships_yahoo`
--

CREATE TABLE `memberships_yahoo` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `membershipid` bigint(20) UNSIGNED NOT NULL,
  `role` enum('Member','Moderator','Owner') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `collection` enum('Approved','Pending','Banned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailid` bigint(20) UNSIGNED NOT NULL COMMENT 'Which of their emails they use on this group',
  `yahooAlias` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `yahooPostingStatus` enum('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Yahoo mod status if applicable',
  `yahooDeliveryType` enum('DIGEST','NONE','SINGLE','ANNOUNCEMENT') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Yahoo delivery settings if applicable',
  `syncdelete` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Used during member sync',
  `yahooapprove` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, email to approve member if known and relevant',
  `yahooreject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, email to reject member if known and relevant',
  `joincomment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any joining comment for this member'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Which groups users are members of' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `memberships_yahoo_dump`
--

CREATE TABLE `memberships_yahoo_dump` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `members` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `lastupdated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastprocessed` timestamp NULL DEFAULT NULL COMMENT 'When this was last processed into the main tables',
  `synctime` timestamp NULL DEFAULT NULL COMMENT 'Time on client when sync started',
  `backgroundok` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Copy of last member sync from Yahoo' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique iD',
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this message arrived at our server',
  `date` timestamp NULL DEFAULT NULL COMMENT 'When this message was created, e.g. Date header',
  `deleted` timestamp NULL DEFAULT NULL COMMENT 'When this message was deleted',
  `heldby` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'If this message is held by a moderator',
  `source` enum('Yahoo Approved','Yahoo Pending','Yahoo System','Platform','Email') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Source of incoming message',
  `sourceheader` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any source header, e.g. X-Freegle-Source',
  `fromip` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP we think this message came from',
  `fromcountry` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'fromip geocoded to country',
  `message` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The unparsed message',
  `fromuser` bigint(20) UNSIGNED DEFAULT NULL,
  `envelopefrom` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fromname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fromaddr` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `envelopeto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replyto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suggestedsubject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Offer','Taken','Wanted','Received','Admin','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For reuse groups, the message categorisation',
  `messageid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tnpostid` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'If this message came from Trash Nothing, the unique post ID',
  `textbody` longtext COLLATE utf8mb4_unicode_ci,
  `htmlbody` longtext COLLATE utf8mb4_unicode_ci,
  `retrycount` int(11) NOT NULL DEFAULT '0' COMMENT 'We might fail to route, and later retry',
  `retrylastfailure` timestamp NULL DEFAULT NULL,
  `spamtype` enum('CountryBlocked','IPUsedForDifferentUsers','IPUsedForDifferentGroups','SubjectUsedForDifferentGroups','SpamAssassin','NotSpam') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spamreason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Why we think this message may be spam',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `locationid` bigint(20) UNSIGNED DEFAULT NULL,
  `editedby` bigint(20) UNSIGNED DEFAULT NULL,
  `editedat` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='All our messages' KEY_BLOCK_SIZE=8 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `messages_attachments`
--

CREATE TABLE `messages_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the messages table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `messages_attachments_items`
--

CREATE TABLE `messages_attachments_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `attid` bigint(20) UNSIGNED NOT NULL,
  `itemid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `messages_deadlines`
--

CREATE TABLE `messages_deadlines` (
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `FOP` tinyint(4) NOT NULL DEFAULT '1',
  `mustgoby` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_drafts`
--

CREATE TABLE `messages_drafts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `messages_groups`
--

CREATE TABLE `messages_groups` (
  `msgid` bigint(20) UNSIGNED NOT NULL COMMENT 'id in the messages table',
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `collection` enum('Incoming','Pending','Approved','Spam','QueuedYahooUser','Rejected') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrival` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `messages_history`
--

CREATE TABLE `messages_history` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique iD',
  `msgid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the messages table',
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this message arrived at our server',
  `source` enum('Yahoo Approved','Yahoo Pending','Yahoo System','Platform') CHARACTER SET latin1 DEFAULT NULL COMMENT 'Source of incoming message',
  `fromip` varchar(40) CHARACTER SET latin1 DEFAULT NULL COMMENT 'IP we think this message came from',
  `fromhost` varchar(80) CHARACTER SET latin1 DEFAULT NULL COMMENT 'Hostname for fromip if resolvable, or NULL',
  `fromuser` bigint(20) UNSIGNED DEFAULT NULL,
  `envelopefrom` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `fromname` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `fromaddr` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `envelopeto` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Destination group, if identified',
  `subject` varchar(1024) CHARACTER SET latin1 DEFAULT NULL,
  `prunedsubject` varchar(1024) CHARACTER SET latin1 DEFAULT NULL COMMENT 'For spam detection',
  `messageid` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `repost` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Message arrivals, used for spam checking' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `messages_index`
--

CREATE TABLE `messages_index` (
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `wordid` bigint(20) UNSIGNED NOT NULL,
  `arrival` bigint(20) NOT NULL COMMENT 'We prioritise recent messages',
  `groupid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For indexing messages for search keywords' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `messages_items`
--

CREATE TABLE `messages_items` (
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `itemid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Where known, items for our message';

-- --------------------------------------------------------

--
-- Table structure for table `messages_likes`
--

CREATE TABLE `messages_likes` (
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `type` enum('Love','Laugh') COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_outcomes`
--

CREATE TABLE `messages_outcomes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `outcome` enum('Taken','Received','Withdrawn','Repost') COLLATE utf8mb4_unicode_ci NOT NULL,
  `happiness` enum('Happy','Fine','Unhappy') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `comments` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_outcomes_intended`
--

CREATE TABLE `messages_outcomes_intended` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `outcome` enum('Taken','Received','Withdrawn','Repost') COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='When someone starts telling us an outcome but doesn''t finish';

-- --------------------------------------------------------

--
-- Table structure for table `messages_postings`
--

CREATE TABLE `messages_postings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `repost` tinyint(1) NOT NULL DEFAULT '0',
  `autorepost` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_promises`
--

CREATE TABLE `messages_promises` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `promisedat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_related`
--

CREATE TABLE `messages_related` (
  `id1` bigint(20) UNSIGNED NOT NULL,
  `id2` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Messages which are related to each other' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `messages_reneged`
--

CREATE TABLE `messages_reneged` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages_spamham`
--

CREATE TABLE `messages_spamham` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `spamham` enum('Spam','Ham') COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User feedback on messages ' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `modnotifs`
--

CREATE TABLE `modnotifs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mod_bulkops`
--

CREATE TABLE `mod_bulkops` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `configid` bigint(20) UNSIGNED DEFAULT NULL,
  `set` enum('Members') COLLATE utf8mb4_unicode_ci NOT NULL,
  `criterion` enum('Bouncing','BouncingFor','WebOnly','All') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `runevery` int(11) NOT NULL DEFAULT '168' COMMENT 'In hours',
  `action` enum('Unbounce','Remove','ToGroup','ToSpecialNotices') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bouncingfor` int(11) NOT NULL DEFAULT '90'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `mod_bulkops_run`
--

CREATE TABLE `mod_bulkops_run` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bulkopid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `runstarted` timestamp NULL DEFAULT NULL,
  `runfinished` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `mod_configs`
--

CREATE TABLE `mod_configs` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique ID of config',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name of config set',
  `createdby` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Moderator ID who created it',
  `fromname` enum('My name','Groupname Moderator') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'My name',
  `ccrejectto` enum('Nobody','Me','Specific') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccrejectaddr` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccfollowupto` enum('Nobody','Me','Specific') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccfollowupaddr` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccrejmembto` enum('Nobody','Me','Specific') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccrejmembaddr` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccfollmembto` enum('Nobody','Me','Specific') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccfollmembaddr` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `protected` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Protect from edit?',
  `messageorder` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'CSL of ids of standard messages in order in which they should appear',
  `network` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `coloursubj` tinyint(1) NOT NULL DEFAULT '1',
  `subjreg` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '^(OFFER|WANTED|TAKEN|RECEIVED) *[\\:-].*\\(.*\\)',
  `subjlen` int(11) NOT NULL DEFAULT '68',
  `default` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Default configs are always visible'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurations for use by moderators' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `mod_stdmsgs`
--

CREATE TABLE `mod_stdmsgs` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique ID of standard message',
  `configid` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Title of standard message',
  `action` enum('Approve','Reject','Leave','Approve Member','Reject Member','Leave Member','Leave Approved Message','Delete Approved Message','Leave Approved Member','Delete Approved Member','Edit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Reject' COMMENT 'What action to take',
  `subjpref` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Subject prefix',
  `subjsuff` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Subject suffix',
  `body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `rarelyused` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Rarely used messages may be hidden in the UI',
  `autosend` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Send the message immediately rather than wait for user',
  `newmodstatus` enum('UNCHANGED','MODERATED','DEFAULT','PROHIBITED','UNMODERATED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNCHANGED' COMMENT 'Yahoo mod status afterwards',
  `newdelstatus` enum('UNCHANGED','DIGEST','NONE','SINGLE','ANNOUNCEMENT') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNCHANGED' COMMENT 'Yahoo delivery status afterwards',
  `edittext` enum('Unchanged','Correct Case') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unchanged',
  `insert` enum('Top','Bottom') COLLATE utf8mb4_unicode_ci DEFAULT 'Top'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `newsfeed`
--

CREATE TABLE `newsfeed` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Message','CommunityEvent','VolunteerOpportunity','CentralPublicity','Alert','Story','ReferToWanted','ReferToOffer','ReferToTaken','ReferToReceived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Message',
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `imageid` bigint(20) UNSIGNED DEFAULT NULL,
  `msgid` bigint(20) UNSIGNED DEFAULT NULL,
  `replyto` bigint(20) UNSIGNED DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `eventid` bigint(20) UNSIGNED DEFAULT NULL,
  `volunteeringid` bigint(20) UNSIGNED DEFAULT NULL,
  `publicityid` bigint(20) UNSIGNED DEFAULT NULL,
  `storyid` bigint(20) UNSIGNED DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `position` point NOT NULL,
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0',
  `deleted` timestamp NULL DEFAULT NULL,
  `deletedby` bigint(20) UNSIGNED DEFAULT NULL,
  `hidden` timestamp NULL DEFAULT NULL,
  `hiddenby` bigint(20) UNSIGNED DEFAULT NULL,
  `closed` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsfeed_images`
--

CREATE TABLE `newsfeed_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `newsfeedid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the community events table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `newsfeed_likes`
--

CREATE TABLE `newsfeed_likes` (
  `newsfeedid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsfeed_reports`
--

CREATE TABLE `newsfeed_reports` (
  `newsfeedid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reason` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsfeed_unfollow`
--

CREATE TABLE `newsfeed_unfollow` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `newsfeedid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsfeed_users`
--

CREATE TABLE `newsfeed_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `newsfeedid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsletters`
--

CREATE TABLE `newsletters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `subject` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `textbody` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'For people who don''t read HTML',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `uptouser` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User id we are upto, roughly',
  `type` enum('General','Stories','','') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsletters_articles`
--

CREATE TABLE `newsletters_articles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `newsletterid` bigint(20) UNSIGNED NOT NULL,
  `type` enum('Header','Article') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Article',
  `position` int(11) NOT NULL,
  `html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `photoid` bigint(20) UNSIGNED DEFAULT NULL,
  `width` int(11) NOT NULL DEFAULT '250'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsletters_images`
--

CREATE TABLE `newsletters_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `articleid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the groups table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `os_county_electoral_division_region`
--

CREATE TABLE `os_county_electoral_division_region` (
  `OGR_FID` int(11) NOT NULL,
  `SHAPE` geometry NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_addresses`
--

CREATE TABLE `paf_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `postcodeid` bigint(20) UNSIGNED DEFAULT NULL,
  `posttownid` bigint(20) UNSIGNED DEFAULT NULL,
  `dependentlocalityid` bigint(20) UNSIGNED DEFAULT NULL,
  `doubledependentlocalityid` bigint(20) UNSIGNED DEFAULT NULL,
  `thoroughfaredescriptorid` bigint(20) UNSIGNED DEFAULT NULL,
  `dependentthoroughfaredescriptorid` bigint(20) UNSIGNED DEFAULT NULL,
  `buildingnumber` int(11) DEFAULT NULL,
  `buildingnameid` bigint(20) UNSIGNED DEFAULT NULL,
  `subbuildingnameid` bigint(20) UNSIGNED DEFAULT NULL,
  `poboxid` bigint(20) UNSIGNED DEFAULT NULL,
  `departmentnameid` bigint(20) UNSIGNED DEFAULT NULL,
  `organisationnameid` bigint(20) UNSIGNED DEFAULT NULL,
  `udprn` bigint(20) UNSIGNED DEFAULT NULL,
  `postcodetype` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suorganisationindicator` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deliverypointsuffix` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_buildingname`
--

CREATE TABLE `paf_buildingname` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `buildingname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_departmentname`
--

CREATE TABLE `paf_departmentname` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `departmentname` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_dependentlocality`
--

CREATE TABLE `paf_dependentlocality` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dependentlocality` varchar(35) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_dependentthoroughfaredescriptor`
--

CREATE TABLE `paf_dependentthoroughfaredescriptor` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dependentthoroughfaredescriptor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_doubledependentlocality`
--

CREATE TABLE `paf_doubledependentlocality` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `doubledependentlocality` varchar(35) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_organisationname`
--

CREATE TABLE `paf_organisationname` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `organisationname` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_pobox`
--

CREATE TABLE `paf_pobox` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `pobox` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_posttown`
--

CREATE TABLE `paf_posttown` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `posttown` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_subbuildingname`
--

CREATE TABLE `paf_subbuildingname` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `subbuildingname` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paf_thoroughfaredescriptor`
--

CREATE TABLE `paf_thoroughfaredescriptor` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `thoroughfaredescriptor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partners_keys`
--

CREATE TABLE `partners_keys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `partner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For site-to-site integration' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `plugin`
--

CREATE TABLE `plugin` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `data` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Outstanding work required to be performed by the plugin' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `polls`
--

CREATE TABLE `polls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `template` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `logintype` enum('Facebook','Google','Yahoo','Native') COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `polls_users`
--

CREATE TABLE `polls_users` (
  `pollid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(10) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `shown` tinyint(4) DEFAULT '1',
  `response` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prerender`
--

CREATE TABLE `prerender` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `url` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci,
  `head` longtext COLLATE utf8mb4_unicode_ci,
  `retrieved` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `timeout` int(11) NOT NULL DEFAULT '60' COMMENT 'In minutes',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Saved copies of HTML for logged out view of pages' ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `agreed` timestamp NULL DEFAULT NULL,
  `schedule` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedules_users`
--

CREATE TABLE `schedules_users` (
  `userid` bigint(20) UNSIGNED NOT NULL,
  `scheduleid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `search_history`
--

CREATE TABLE `search_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `term` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locationid` bigint(20) UNSIGNED DEFAULT NULL,
  `groups` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `series` bigint(20) UNSIGNED NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastactive` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `shortlinks`
--

CREATE TABLE `shortlinks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Group','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clicks` bigint(20) NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `spam_countries`
--

CREATE TABLE `spam_countries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `country` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'A country we want to block'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `spam_keywords`
--

CREATE TABLE `spam_keywords` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `word` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exclude` text COLLATE utf8mb4_unicode_ci,
  `action` enum('Review','Spam') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Review'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Keywords often used by spammers';

-- --------------------------------------------------------

--
-- Table structure for table `spam_users`
--

CREATE TABLE `spam_users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `byuserid` bigint(20) UNSIGNED DEFAULT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `collection` enum('Spammer','Whitelisted','PendingAdd','PendingRemove') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Spammer',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Users who are spammers or trusted' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `spam_whitelist_ips`
--

CREATE TABLE `spam_whitelist_ips` (
  `id` bigint(20) NOT NULL,
  `ip` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted IP addresses' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `spam_whitelist_links`
--

CREATE TABLE `spam_whitelist_links` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `domain` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `count` int(11) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted domains for URLs';

-- --------------------------------------------------------

--
-- Table structure for table `spam_whitelist_subjects`
--

CREATE TABLE `spam_whitelist_subjects` (
  `id` bigint(20) NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted subjects' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `spatial_ref_sys`
--

CREATE TABLE `spatial_ref_sys` (
  `SRID` int(11) NOT NULL,
  `AUTH_NAME` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `AUTH_SRID` int(11) DEFAULT NULL,
  `SRTEXT` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stats`
--

CREATE TABLE `stats` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `type` enum('ApprovedMessageCount','SpamMessageCount','MessageBreakdown','SpamMemberCount','PostMethodBreakdown','YahooDeliveryBreakdown','YahooPostingBreakdown','ApprovedMemberCount','SupportQueries','Happy','Fine','Unhappy','Searches','Activity','Weight','Outcomes') COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` bigint(20) UNSIGNED DEFAULT NULL,
  `breakdown` mediumtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stats information used for dashboard' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `supporters`
--

CREATE TABLE `supporters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Wowzer','Front Page','Supporter','Buyer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voucher` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Voucher code',
  `vouchercount` int(11) NOT NULL DEFAULT '1' COMMENT 'Number of licenses in this voucher',
  `voucheryears` int(11) NOT NULL DEFAULT '1' COMMENT 'Number of years voucher licenses are valid for',
  `anonymous` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='People who have supported this site' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `yahooUserId` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unique ID of user on Yahoo if known',
  `firstname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fullname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `systemrole` set('User','Moderator','Support','Admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User' COMMENT 'System-wide roles',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastaccess` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settings` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON-encoded settings',
  `gotrealemail` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Until migrated, whether polled FD/TN to get real email',
  `suspectcount` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Number of reports of this user as suspicious',
  `suspectreason` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last reason for suspecting this user',
  `yahooid` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any known YahooID for this user',
  `licenses` int(11) NOT NULL DEFAULT '0' COMMENT 'Any licenses not added to groups',
  `newslettersallowed` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Central mails',
  `relevantallowed` tinyint(4) NOT NULL DEFAULT '1',
  `onholidaytill` date DEFAULT NULL,
  `ripaconsent` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether we have consent for humans to vet their messages',
  `publishconsent` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Can we republish posts to non-members?',
  `lastlocation` bigint(20) UNSIGNED DEFAULT NULL,
  `lastrelevantcheck` timestamp NULL DEFAULT NULL,
  `lastidlechaseup` timestamp NULL DEFAULT NULL,
  `bouncing` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether preferred email has been determined to be bouncing',
  `permissions` set('BusinessCardsAdmin','Newsletter','NationalVolunteers') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitesleft` int(10) UNSIGNED DEFAULT '10',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users_addresses`
--

CREATE TABLE `users_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `pafid` bigint(20) UNSIGNED DEFAULT NULL,
  `to` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instructions` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_banned`
--

CREATE TABLE `users_banned` (
  `userid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `byuser` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users_chatlists`
--

CREATE TABLE `users_chatlists` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chatlist` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `background` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache of lists of chats for performance';

-- --------------------------------------------------------

--
-- Table structure for table `users_chatlists_index`
--

CREATE TABLE `users_chatlists_index` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chatid` bigint(20) UNSIGNED NOT NULL,
  `chatlistid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_comments`
--

CREATE TABLE `users_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `byuserid` bigint(20) UNSIGNED DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user1` mediumtext COLLATE utf8mb4_unicode_ci,
  `user2` mediumtext COLLATE utf8mb4_unicode_ci,
  `user3` mediumtext COLLATE utf8mb4_unicode_ci,
  `user4` mediumtext COLLATE utf8mb4_unicode_ci,
  `user5` mediumtext COLLATE utf8mb4_unicode_ci,
  `user6` mediumtext COLLATE utf8mb4_unicode_ci,
  `user7` mediumtext COLLATE utf8mb4_unicode_ci,
  `user8` mediumtext COLLATE utf8mb4_unicode_ci,
  `user9` mediumtext COLLATE utf8mb4_unicode_ci,
  `user10` mediumtext COLLATE utf8mb4_unicode_ci,
  `user11` mediumtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comments from mods on members' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users_dashboard`
--

CREATE TABLE `users_dashboard` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('Reuse','Freegle','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `systemwide` tinyint(1) DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL,
  `start` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` longtext COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached copy of mod dashboard, gen in cron';

-- --------------------------------------------------------

--
-- Table structure for table `users_donations`
--

CREATE TABLE `users_donations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('PayPal','External') COLLATE utf8mb4_unicode_ci NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `Payer` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `PayerDisplayName` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `TransactionID` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `GrossAmount` decimal(10,2) NOT NULL,
  `source` enum('DonateWithPayPal','PayPalGivingFund') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DonateWithPayPal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_donations_asks`
--

CREATE TABLE `users_donations_asks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_emails`
--

CREATE TABLE `users_emails` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Unique ID in users table',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The email',
  `preferred` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Preferred email for this user',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `validatekey` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `validated` timestamp NULL DEFAULT NULL,
  `canon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For spotting duplicates',
  `backwards` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Allows domain search',
  `bounced` timestamp NULL DEFAULT NULL,
  `viewed` timestamp NULL DEFAULT NULL,
  `md5hash` varchar(32) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (md5(lower(`email`))) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users_exports`
--

CREATE TABLE `users_exports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `requested` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started` timestamp NULL DEFAULT NULL,
  `completed` timestamp NULL DEFAULT NULL,
  `tag` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` longblob
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_images`
--

CREATE TABLE `users_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the users table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `users_invitations`
--

CREATE TABLE `users_invitations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `outcome` enum('Pending','Accepted','Declined','') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `outcometimestamp` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_kudos`
--

CREATE TABLE `users_kudos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `kudos` int(11) NOT NULL DEFAULT '0',
  `userid` bigint(20) UNSIGNED NOT NULL,
  `posts` int(11) NOT NULL DEFAULT '0',
  `chats` int(11) NOT NULL DEFAULT '0',
  `newsfeed` int(11) NOT NULL DEFAULT '0',
  `events` int(11) NOT NULL DEFAULT '0',
  `vols` int(11) NOT NULL DEFAULT '0',
  `facebook` tinyint(1) NOT NULL DEFAULT '0',
  `platform` tinyint(1) NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_logins`
--

CREATE TABLE `users_logins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique ID in users table',
  `type` enum('Yahoo','Facebook','Google','Native','Link') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unique identifier for login',
  `credentials` text COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastaccess` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `credentials2` text COLLATE utf8mb4_unicode_ci COMMENT 'For Link logins',
  `credentialsrotated` timestamp NULL DEFAULT NULL COMMENT 'For Link logins'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users_modmails`
--

CREATE TABLE `users_modmails` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `logid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `groupid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_nearby`
--

CREATE TABLE `users_nearby` (
  `userid` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_notifications`
--

CREATE TABLE `users_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fromuser` bigint(20) UNSIGNED DEFAULT NULL,
  `touser` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('CommentOnYourPost','CommentOnCommented','LovedPost','LovedComment','TryFeed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `newsfeedid` bigint(20) UNSIGNED DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seen` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_nudges`
--

CREATE TABLE `users_nudges` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fromuser` bigint(20) UNSIGNED NOT NULL,
  `touser` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_phones`
--

CREATE TABLE `users_phones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_push_notifications`
--

CREATE TABLE `users_push_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Google','Firefox','Test','Android','IOS') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Google',
  `lastsent` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subscription` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apptype` enum('User','ModTools') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For sending push notifications to users' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `users_requests`
--

CREATE TABLE `users_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `type` enum('BusinessCards') COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `completedby` bigint(20) UNSIGNED DEFAULT NULL,
  `addressid` bigint(20) UNSIGNED DEFAULT NULL,
  `to` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notifiedmods` timestamp NULL DEFAULT NULL,
  `paid` tinyint(1) NOT NULL DEFAULT '0',
  `amount` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_searches`
--

CREATE TABLE `users_searches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `term` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `maxmsg` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT '0',
  `locationid` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_stories`
--

CREATE TABLE `users_stories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `public` tinyint(1) NOT NULL DEFAULT '1',
  `reviewed` tinyint(1) NOT NULL DEFAULT '0',
  `headline` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `story` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tweeted` tinyint(4) NOT NULL DEFAULT '0',
  `mailedtocentral` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Mailed to groups mailing list',
  `mailedtomembers` tinyint(1) DEFAULT '0',
  `newsletterreviewed` tinyint(1) NOT NULL DEFAULT '0',
  `newsletter` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_stories_likes`
--

CREATE TABLE `users_stories_likes` (
  `storyid` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_stories_requested`
--

CREATE TABLE `users_stories_requested` (
  `id` bigint(20) NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_thanks`
--

CREATE TABLE `users_thanks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visualise`
--

CREATE TABLE `visualise` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `msgid` bigint(20) UNSIGNED NOT NULL,
  `attid` bigint(20) UNSIGNED NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fromuser` bigint(20) UNSIGNED NOT NULL,
  `touser` bigint(20) UNSIGNED NOT NULL,
  `fromlat` decimal(10,6) NOT NULL,
  `fromlng` decimal(10,6) NOT NULL,
  `tolat` decimal(10,6) NOT NULL,
  `tolng` decimal(10,6) NOT NULL,
  `distance` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Data to allow us to visualise flows of items to people';

-- --------------------------------------------------------

--
-- Table structure for table `volunteering`
--

CREATE TABLE `volunteering` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` bigint(20) UNSIGNED DEFAULT NULL,
  `pending` tinyint(1) NOT NULL DEFAULT '0',
  `title` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `online` tinyint(1) NOT NULL DEFAULT '0',
  `location` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `contactname` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactphone` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactemail` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacturl` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted` tinyint(4) NOT NULL DEFAULT '0',
  `deletedby` bigint(20) UNSIGNED DEFAULT NULL,
  `askedtorenew` timestamp NULL DEFAULT NULL,
  `renewed` timestamp NULL DEFAULT NULL,
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  `timecommitment` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `volunteering_dates`
--

CREATE TABLE `volunteering_dates` (
  `id` bigint(20) NOT NULL,
  `volunteeringid` bigint(20) UNSIGNED NOT NULL,
  `start` timestamp NULL DEFAULT NULL,
  `end` timestamp NULL DEFAULT NULL,
  `applyby` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `volunteering_groups`
--

CREATE TABLE `volunteering_groups` (
  `volunteeringid` bigint(20) UNSIGNED NOT NULL,
  `groupid` bigint(20) UNSIGNED NOT NULL,
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `volunteering_images`
--

CREATE TABLE `volunteering_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `opportunityid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'id in the volunteering table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attachments parsed out from messages and resized' KEY_BLOCK_SIZE=16 ROW_FORMAT=COMPRESSED;

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `voucher` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used` timestamp NULL DEFAULT NULL,
  `groupid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Group that a voucher was used on',
  `userid` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'User who redeemed a voucher'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For licensing groups' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_donations`
--
CREATE TABLE `vw_donations` (
   `total` decimal(32,2)
  ,`date` date
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_ECC`
--
CREATE TABLE `vw_ECC` (
   `date` timestamp
  ,`count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `VW_Essex_Searches`
--
CREATE TABLE `VW_Essex_Searches` (
   `DATE` date
  ,`count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_freeglegroups_unreached`
--
CREATE TABLE `vw_freeglegroups_unreached` (
   `id` bigint(20) unsigned
  ,`nameshort` varchar(80)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_manyemails`
--
CREATE TABLE `vw_manyemails` (
   `id` bigint(20) unsigned
  ,`fullname` varchar(255)
  ,`email` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_membersyncpending`
--
CREATE TABLE `vw_membersyncpending` (
   `id` bigint(20) unsigned
  ,`groupid` bigint(20) unsigned
  ,`members` longtext
  ,`lastupdated` timestamp
  ,`lastprocessed` timestamp
  ,`synctime` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_multiemails`
--
CREATE TABLE `vw_multiemails` (
   `id` bigint(20) unsigned
  ,`fullname` varchar(255)
  ,`count` bigint(21)
  ,`GROUP_CONCAT(email SEPARATOR ', ')` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_recentgroupaccess`
--
CREATE TABLE `vw_recentgroupaccess` (
   `lastaccess` timestamp
  ,`nameshort` varchar(80)
  ,`id` bigint(20) unsigned
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_recentlogins`
--
CREATE TABLE `vw_recentlogins` (
   `timestamp` timestamp
  ,`id` bigint(20) unsigned
  ,`fullname` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_recentposts`
--
CREATE TABLE `vw_recentposts` (
   `id` bigint(20) unsigned
  ,`date` timestamp
  ,`fromaddr` varchar(255)
  ,`subject` varchar(255)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `VW_recentqueries`
--
CREATE TABLE `VW_recentqueries` (
   `id` bigint(20) unsigned
  ,`chatid` bigint(20) unsigned
  ,`userid` bigint(20) unsigned
  ,`type` enum('Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated')
  ,`reportreason` enum('Spam','Other')
  ,`refmsgid` bigint(20) unsigned
  ,`refchatid` bigint(20) unsigned
  ,`date` timestamp
  ,`message` text
  ,`platform` tinyint(4)
  ,`seenbyall` tinyint(1)
  ,`reviewrequired` tinyint(1)
  ,`reviewedby` bigint(20) unsigned
  ,`reviewrejected` tinyint(1)
  ,`spamscore` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `VW_routes`
--
CREATE TABLE `VW_routes` (
   `route` varchar(255)
  ,`count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_src`
--
CREATE TABLE `vw_src` (
   `count` bigint(21)
  ,`src` varchar(40)
);

-- --------------------------------------------------------

--
-- Table structure for table `weights`
--

CREATE TABLE `weights` (
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `simplename` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'The name in simpler terms',
  `weight` decimal(5,2) NOT NULL,
  `source` enum('FRN 2009','Freegle') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FRN 2009'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Standard weights, from FRN 2009';

-- --------------------------------------------------------

--
-- Table structure for table `words`
--

CREATE TABLE `words` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `word` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `firstthree` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `soundex` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Negative as DESC index not supported'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Unique words for searches' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Structure for view `vw_donations`
--
DROP TABLE IF EXISTS `vw_donations`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_donations`  AS  select sum(`users_donations`.`GrossAmount`) AS `total`,cast(`users_donations`.`timestamp` as date) AS `date` from `users_donations` where (((to_days(now()) - to_days(`users_donations`.`timestamp`)) < 31) and (`users_donations`.`Payer` <> 'ppgfukpay@paypalgivingfund.org')) group by `date` order by `date` desc ;

-- --------------------------------------------------------

--
-- Structure for view `vw_ECC`
--
DROP TABLE IF EXISTS `vw_ECC`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_ECC`  AS  select `logs_src`.`date` AS `date`,count(0) AS `count` from `logs_src` where (`logs_src`.`src` = 'ECC') group by cast(`logs_src`.`date` as date) order by `logs_src`.`date` ;

-- --------------------------------------------------------

--
-- Structure for view `VW_Essex_Searches`
--
DROP TABLE IF EXISTS `VW_Essex_Searches`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `VW_Essex_Searches`  AS  select cast(`users_searches`.`date` as date) AS `DATE`,count(0) AS `count` from (`users_searches` join `locations` on((`users_searches`.`locationid` = `locations`.`id`))) where (mbrwithin(`locations`.`geometry`,(select `authorities`.`polygon` from `authorities` where (`authorities`.`name` like '%essex%'))) and (`users_searches`.`date` > '2017-07-01')) group by cast(`users_searches`.`date` as date) order by `DATE` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_freeglegroups_unreached`
--
DROP TABLE IF EXISTS `vw_freeglegroups_unreached`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_freeglegroups_unreached`  AS  select `groups`.`id` AS `id`,`groups`.`nameshort` AS `nameshort` from `groups` where ((`groups`.`type` = 'Freegle') and (not((`groups`.`nameshort` like '%playground%'))) and (not((`groups`.`nameshort` like '%test%'))) and (not(`groups`.`id` in (select `alerts_tracking`.`groupid` from `alerts_tracking` where (`alerts_tracking`.`response` is not null))))) order by `groups`.`nameshort` ;

-- --------------------------------------------------------

--
-- Structure for view `vw_manyemails`
--
DROP TABLE IF EXISTS `vw_manyemails`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_manyemails`  AS  select `users`.`id` AS `id`,`users`.`fullname` AS `fullname`,`users_emails`.`email` AS `email` from (`users` join `users_emails` on((`users`.`id` = `users_emails`.`userid`))) where `users`.`id` in (select `users_emails`.`userid` from `users_emails` group by `users_emails`.`userid` having (count(0) > 4) order by count(0) desc) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_membersyncpending`
--
DROP TABLE IF EXISTS `vw_membersyncpending`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_membersyncpending`  AS  select `memberships_yahoo_dump`.`id` AS `id`,`memberships_yahoo_dump`.`groupid` AS `groupid`,`memberships_yahoo_dump`.`members` AS `members`,`memberships_yahoo_dump`.`lastupdated` AS `lastupdated`,`memberships_yahoo_dump`.`lastprocessed` AS `lastprocessed`,`memberships_yahoo_dump`.`synctime` AS `synctime` from `memberships_yahoo_dump` where (isnull(`memberships_yahoo_dump`.`lastprocessed`) or (`memberships_yahoo_dump`.`lastupdated` > `memberships_yahoo_dump`.`lastprocessed`)) ;

-- --------------------------------------------------------

--
-- Structure for view `vw_multiemails`
--
DROP TABLE IF EXISTS `vw_multiemails`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_multiemails`  AS  select `vw_manyemails`.`id` AS `id`,`vw_manyemails`.`fullname` AS `fullname`,count(0) AS `count`,group_concat(`vw_manyemails`.`email` separator ', ') AS `GROUP_CONCAT(email SEPARATOR ', ')` from `vw_manyemails` group by `vw_manyemails`.`id` order by `count` desc ;

-- --------------------------------------------------------

--
-- Structure for view `vw_recentgroupaccess`
--
DROP TABLE IF EXISTS `vw_recentgroupaccess`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_recentgroupaccess`  AS  select `users_logins`.`lastaccess` AS `lastaccess`,`groups`.`nameshort` AS `nameshort`,`groups`.`id` AS `id` from ((`users_logins` join `memberships` on(((`users_logins`.`userid` = `memberships`.`userid`) and (`memberships`.`role` in ('Owner','Moderator'))))) join `groups` on((`memberships`.`groupid` = `groups`.`id`))) order by `users_logins`.`lastaccess` desc ;

-- --------------------------------------------------------

--
-- Structure for view `vw_recentlogins`
--
DROP TABLE IF EXISTS `vw_recentlogins`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_recentlogins`  AS  select `logs`.`timestamp` AS `timestamp`,`users`.`id` AS `id`,`users`.`fullname` AS `fullname` from (`users` join `logs` on((`users`.`id` = `logs`.`byuser`))) where ((`logs`.`type` = 'User') and (`logs`.`subtype` = 'Login')) order by `logs`.`timestamp` desc ;

-- --------------------------------------------------------

--
-- Structure for view `vw_recentposts`
--
DROP TABLE IF EXISTS `vw_recentposts`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_recentposts`  AS  select `messages`.`id` AS `id`,`messages`.`date` AS `date`,`messages`.`fromaddr` AS `fromaddr`,`messages`.`subject` AS `subject` from (`messages` left join `messages_drafts` on((`messages_drafts`.`msgid` = `messages`.`id`))) where ((`messages`.`source` = 'Platform') and isnull(`messages_drafts`.`msgid`)) order by `messages`.`date` desc limit 20 ;

-- --------------------------------------------------------

--
-- Structure for view `VW_recentqueries`
--
DROP TABLE IF EXISTS `VW_recentqueries`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `VW_recentqueries`  AS  select `chat_messages`.`id` AS `id`,`chat_messages`.`chatid` AS `chatid`,`chat_messages`.`userid` AS `userid`,`chat_messages`.`type` AS `type`,`chat_messages`.`reportreason` AS `reportreason`,`chat_messages`.`refmsgid` AS `refmsgid`,`chat_messages`.`refchatid` AS `refchatid`,`chat_messages`.`date` AS `date`,`chat_messages`.`message` AS `message`,`chat_messages`.`platform` AS `platform`,`chat_messages`.`seenbyall` AS `seenbyall`,`chat_messages`.`reviewrequired` AS `reviewrequired`,`chat_messages`.`reviewedby` AS `reviewedby`,`chat_messages`.`reviewrejected` AS `reviewrejected`,`chat_messages`.`spamscore` AS `spamscore` from (`chat_messages` join `chat_rooms` on((`chat_messages`.`chatid` = `chat_rooms`.`id`))) where (`chat_rooms`.`chattype` = 'User2Mod') order by `chat_messages`.`date` desc ;

-- --------------------------------------------------------

--
-- Structure for view `VW_routes`
--
DROP TABLE IF EXISTS `VW_routes`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `VW_routes`  AS  select `logs_events`.`route` AS `route`,count(0) AS `count` from `logs_events` group by `logs_events`.`route` order by `count` desc ;

-- --------------------------------------------------------

--
-- Structure for view `vw_src`
--
DROP TABLE IF EXISTS `vw_src`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_src`  AS  select count(0) AS `count`,`logs_src`.`src` AS `src` from `logs_src` group by `logs_src`.`src` order by `count` desc ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `abtest`
--
ALTER TABLE `abtest`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid_2` (`uid`,`variant`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `createdby` (`createdby`);

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `createdby` (`createdby`);

--
-- Indexes for table `alerts_tracking`
--
ALTER TABLE `alerts_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `alertid` (`alertid`),
  ADD KEY `emailid` (`emailid`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `authorities`
--
ALTER TABLE `authorities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`,`area_code`),
  ADD SPATIAL KEY `polygon` (`polygon`),
  ADD KEY `name_2` (`name`);

--
-- Indexes for table `aviva_history`
--
ALTER TABLE `aviva_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `aviva_votes`
--
ALTER TABLE `aviva_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `project` (`project`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `votes` (`votes`);

--
-- Indexes for table `bounces`
--
ALTER TABLE `bounces`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bounces_emails`
--
ALTER TABLE `bounces_emails`
  ADD PRIMARY KEY (`id`),
  ADD KEY `emailid` (`emailid`,`date`);

--
-- Indexes for table `chat_images`
--
ALTER TABLE `chat_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`chatmsgid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chatid` (`chatid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `chatid_2` (`chatid`,`date`),
  ADD KEY `msgid` (`refmsgid`),
  ADD KEY `date` (`date`,`seenbyall`),
  ADD KEY `reviewedby` (`reviewedby`),
  ADD KEY `reviewrequired` (`reviewrequired`),
  ADD KEY `refchatid` (`refchatid`),
  ADD KEY `refchatid_2` (`refchatid`),
  ADD KEY `imageid` (`imageid`),
  ADD KEY `scheduleid` (`scheduleid`);

--
-- Indexes for table `chat_messages_byemail`
--
ALTER TABLE `chat_messages_byemail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chatmsgid` (`chatmsgid`),
  ADD KEY `msgid` (`msgid`);

--
-- Indexes for table `chat_messages_held`
--
ALTER TABLE `chat_messages_held`
  ADD PRIMARY KEY (`id`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user1_2` (`user1`,`user2`,`chattype`),
  ADD KEY `user1` (`user1`),
  ADD KEY `user2` (`user2`),
  ADD KEY `synctofacebook` (`synctofacebook`),
  ADD KEY `synctofacebookgroupid` (`synctofacebookgroupid`),
  ADD KEY `chattype` (`chattype`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `chattype_2` (`chattype`),
  ADD KEY `chattype_3` (`chattype`),
  ADD KEY `chattype_4` (`chattype`);

--
-- Indexes for table `chat_roster`
--
ALTER TABLE `chat_roster`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chatid_2` (`chatid`,`userid`),
  ADD KEY `chatid` (`chatid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `date` (`date`),
  ADD KEY `lastmsg` (`lastmsgseen`),
  ADD KEY `lastip` (`lastip`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `communityevents`
--
ALTER TABLE `communityevents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `title` (`title`),
  ADD KEY `legacyid` (`legacyid`);

--
-- Indexes for table `communityevents_dates`
--
ALTER TABLE `communityevents_dates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `start` (`start`),
  ADD KEY `eventid` (`eventid`);

--
-- Indexes for table `communityevents_groups`
--
ALTER TABLE `communityevents_groups`
  ADD UNIQUE KEY `eventid_2` (`eventid`,`groupid`),
  ADD KEY `eventid` (`eventid`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `communityevents_images`
--
ALTER TABLE `communityevents_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`eventid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `ebay_favourites`
--
ALTER TABLE `ebay_favourites`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `nameshort` (`nameshort`),
  ADD UNIQUE KEY `namefull` (`namefull`),
  ADD KEY `lat` (`lat`,`lng`),
  ADD KEY `lng` (`lng`),
  ADD KEY `namealt` (`namealt`),
  ADD KEY `profile` (`profile`),
  ADD KEY `cover` (`cover`),
  ADD KEY `legacyid` (`legacyid`),
  ADD KEY `authorityid` (`authorityid`);

--
-- Indexes for table `groups_digests`
--
ALTER TABLE `groups_digests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `groupid_2` (`groupid`,`frequency`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `msggrpid` (`msgid`);

--
-- Indexes for table `groups_facebook`
--
ALTER TABLE `groups_facebook`
  ADD PRIMARY KEY (`uid`),
  ADD UNIQUE KEY `groupid_2` (`groupid`,`id`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `eventid` (`eventid`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `groups_facebook_shares`
--
ALTER TABLE `groups_facebook_shares`
  ADD UNIQUE KEY `groupid` (`uid`,`postid`),
  ADD KEY `date` (`date`),
  ADD KEY `postid` (`postid`),
  ADD KEY `uid` (`uid`),
  ADD KEY `groupid_2` (`groupid`);

--
-- Indexes for table `groups_facebook_toshare`
--
ALTER TABLE `groups_facebook_toshare`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `postid` (`postid`);

--
-- Indexes for table `groups_images`
--
ALTER TABLE `groups_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`groupid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `groups_twitter`
--
ALTER TABLE `groups_twitter`
  ADD UNIQUE KEY `groupid` (`groupid`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `eventid` (`eventid`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `items_index`
--
ALTER TABLE `items_index`
  ADD UNIQUE KEY `itemid` (`itemid`,`wordid`),
  ADD KEY `itemid_2` (`itemid`),
  ADD KEY `wordid` (`wordid`);

--
-- Indexes for table `items_non`
--
ALTER TABLE `items_non`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `link_previews`
--
ALTER TABLE `link_previews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `url` (`url`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `name` (`name`),
  ADD KEY `osm_id` (`osm_id`),
  ADD KEY `canon` (`canon`),
  ADD KEY `areaid` (`areaid`),
  ADD KEY `postcodeid` (`postcodeid`),
  ADD KEY `lat` (`lat`),
  ADD KEY `lng` (`lng`),
  ADD KEY `gridid` (`gridid`,`osm_place`),
  ADD KEY `timestamp` (`timestamp`);

--
-- Indexes for table `locations_excluded`
--
ALTER TABLE `locations_excluded`
  ADD UNIQUE KEY `locationid_2` (`locationid`,`groupid`),
  ADD KEY `locationid` (`locationid`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `by` (`userid`);

--
-- Indexes for table `locations_grids`
--
ALTER TABLE `locations_grids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `swlat` (`swlat`,`swlng`,`nelat`,`nelng`);

--
-- Indexes for table `locations_grids_touches`
--
ALTER TABLE `locations_grids_touches`
  ADD UNIQUE KEY `gridid` (`gridid`,`touches`),
  ADD KEY `touches` (`touches`);

--
-- Indexes for table `locations_spatial`
--
ALTER TABLE `locations_spatial`
  ADD UNIQUE KEY `locationid` (`locationid`),
  ADD SPATIAL KEY `geometry` (`geometry`);

--
-- Indexes for table `logos`
--
ALTER TABLE `logos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `date` (`date`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group` (`groupid`),
  ADD KEY `type` (`type`,`subtype`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `byuser` (`byuser`),
  ADD KEY `user` (`user`),
  ADD KEY `msgid` (`msgid`);

--
-- Indexes for table `logs_api`
--
ALTER TABLE `logs_api`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `session` (`session`),
  ADD KEY `date` (`date`),
  ADD KEY `userid` (`userid`),
  ADD KEY `ip` (`ip`);

--
-- Indexes for table `logs_emails`
--
ALTER TABLE `logs_emails`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `timestamp_2` (`eximid`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `logs_errors`
--
ALTER TABLE `logs_errors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `logs_profile`
--
ALTER TABLE `logs_profile`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `caller` (`caller`,`callee`);

--
-- Indexes for table `logs_sql`
--
ALTER TABLE `logs_sql`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `session` (`session`),
  ADD KEY `date` (`date`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `logs_src`
--
ALTER TABLE `logs_src`
  ADD PRIMARY KEY (`id`),
  ADD KEY `date` (`date`);

--
-- Indexes for table `memberships`
--
ALTER TABLE `memberships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid_groupid` (`userid`,`groupid`),
  ADD KEY `groupid_2` (`groupid`,`role`),
  ADD KEY `userid` (`userid`,`role`),
  ADD KEY `role` (`role`),
  ADD KEY `configid` (`configid`),
  ADD KEY `groupid` (`groupid`,`collection`),
  ADD KEY `heldby` (`heldby`);

--
-- Indexes for table `memberships_history`
--
ALTER TABLE `memberships_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `date` (`added`),
  ADD KEY `userid` (`userid`,`groupid`);

--
-- Indexes for table `memberships_yahoo`
--
ALTER TABLE `memberships_yahoo`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `membershipid_2` (`membershipid`,`emailid`),
  ADD KEY `role` (`role`),
  ADD KEY `emailid` (`emailid`),
  ADD KEY `groupid` (`collection`),
  ADD KEY `yahooPostingStatus` (`yahooPostingStatus`),
  ADD KEY `yahooDeliveryType` (`yahooDeliveryType`),
  ADD KEY `yahooAlias` (`yahooAlias`);

--
-- Indexes for table `memberships_yahoo_dump`
--
ALTER TABLE `memberships_yahoo_dump`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `groupid` (`groupid`),
  ADD KEY `lastprocessed` (`lastprocessed`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `message-id` (`messageid`) KEY_BLOCK_SIZE=16,
  ADD KEY `envelopefrom` (`envelopefrom`),
  ADD KEY `envelopeto` (`envelopeto`),
  ADD KEY `retrylastfailure` (`retrylastfailure`),
  ADD KEY `fromup` (`fromip`),
  ADD KEY `tnpostid` (`tnpostid`),
  ADD KEY `type` (`type`),
  ADD KEY `sourceheader` (`sourceheader`),
  ADD KEY `arrival` (`arrival`,`sourceheader`),
  ADD KEY `arrival_2` (`arrival`,`fromaddr`),
  ADD KEY `arrival_3` (`arrival`),
  ADD KEY `fromaddr` (`fromaddr`,`subject`),
  ADD KEY `date` (`date`),
  ADD KEY `subject` (`subject`),
  ADD KEY `fromuser` (`fromuser`),
  ADD KEY `deleted` (`deleted`),
  ADD KEY `heldby` (`heldby`),
  ADD KEY `lat` (`lat`) KEY_BLOCK_SIZE=16,
  ADD KEY `lng` (`lng`) KEY_BLOCK_SIZE=16,
  ADD KEY `locationid` (`locationid`) KEY_BLOCK_SIZE=16;

--
-- Indexes for table `messages_attachments`
--
ALTER TABLE `messages_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`msgid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `messages_attachments_items`
--
ALTER TABLE `messages_attachments_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `msgid` (`attid`),
  ADD KEY `itemid` (`itemid`);

--
-- Indexes for table `messages_deadlines`
--
ALTER TABLE `messages_deadlines`
  ADD UNIQUE KEY `msgid` (`msgid`);

--
-- Indexes for table `messages_drafts`
--
ALTER TABLE `messages_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msgid` (`msgid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `session` (`session`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `messages_history`
--
ALTER TABLE `messages_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `msgid` (`msgid`,`groupid`),
  ADD KEY `fromaddr` (`fromaddr`),
  ADD KEY `envelopefrom` (`envelopefrom`),
  ADD KEY `envelopeto` (`envelopeto`),
  ADD KEY `message-id` (`messageid`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `fromup` (`fromip`),
  ADD KEY `incomingid` (`msgid`),
  ADD KEY `fromhost` (`fromhost`),
  ADD KEY `arrival` (`arrival`),
  ADD KEY `subject` (`subject`(767)),
  ADD KEY `prunedsubject` (`prunedsubject`(767)),
  ADD KEY `fromname` (`fromname`),
  ADD KEY `fromuser` (`fromuser`);

--
-- Indexes for table `messages_index`
--
ALTER TABLE `messages_index`
  ADD UNIQUE KEY `msgid` (`msgid`,`wordid`),
  ADD KEY `arrival` (`arrival`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `wordid` (`wordid`,`groupid`);

--
-- Indexes for table `messages_items`
--
ALTER TABLE `messages_items`
  ADD UNIQUE KEY `msgid` (`msgid`,`itemid`),
  ADD KEY `itemid` (`itemid`);

--
-- Indexes for table `messages_likes`
--
ALTER TABLE `messages_likes`
  ADD UNIQUE KEY `msgid_2` (`msgid`,`userid`,`type`),
  ADD KEY `userid` (`userid`),
  ADD KEY `msgid` (`msgid`,`type`);

--
-- Indexes for table `messages_outcomes`
--
ALTER TABLE `messages_outcomes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `timestamp_2` (`timestamp`,`outcome`);

--
-- Indexes for table `messages_outcomes_intended`
--
ALTER TABLE `messages_outcomes_intended`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msgid_2` (`msgid`,`outcome`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `timestamp_2` (`timestamp`,`outcome`),
  ADD KEY `msgid_3` (`msgid`);

--
-- Indexes for table `messages_postings`
--
ALTER TABLE `messages_postings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `messages_promises`
--
ALTER TABLE `messages_promises`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msgid` (`msgid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `promisedat` (`promisedat`);

--
-- Indexes for table `messages_related`
--
ALTER TABLE `messages_related`
  ADD UNIQUE KEY `id1_2` (`id1`,`id2`),
  ADD KEY `id1` (`id1`),
  ADD KEY `id2` (`id2`);

--
-- Indexes for table `messages_reneged`
--
ALTER TABLE `messages_reneged`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `timestamp` (`timestamp`);

--
-- Indexes for table `messages_spamham`
--
ALTER TABLE `messages_spamham`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msgid` (`msgid`);

--
-- Indexes for table `modnotifs`
--
ALTER TABLE `modnotifs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`);

--
-- Indexes for table `mod_bulkops`
--
ALTER TABLE `mod_bulkops`
  ADD UNIQUE KEY `uniqueid` (`id`),
  ADD KEY `configid` (`configid`);

--
-- Indexes for table `mod_bulkops_run`
--
ALTER TABLE `mod_bulkops_run`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bulkopid_2` (`bulkopid`,`groupid`),
  ADD KEY `bulkopid` (`bulkopid`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `mod_configs`
--
ALTER TABLE `mod_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `uniqueid` (`id`,`createdby`),
  ADD KEY `createdby` (`createdby`),
  ADD KEY `default` (`default`);

--
-- Indexes for table `mod_stdmsgs`
--
ALTER TABLE `mod_stdmsgs`
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `configid` (`configid`);

--
-- Indexes for table `newsfeed`
--
ALTER TABLE `newsfeed`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `eventid` (`eventid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `imageid` (`imageid`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `replyto` (`replyto`),
  ADD SPATIAL KEY `position` (`position`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `volunteeringid` (`volunteeringid`),
  ADD KEY `publicityid` (`publicityid`),
  ADD KEY `timestamp` (`timestamp`),
  ADD KEY `storyid` (`storyid`);

--
-- Indexes for table `newsfeed_images`
--
ALTER TABLE `newsfeed_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`newsfeedid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `newsfeed_likes`
--
ALTER TABLE `newsfeed_likes`
  ADD UNIQUE KEY `newsfeedid_2` (`newsfeedid`,`userid`),
  ADD KEY `newsfeedid` (`newsfeedid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `newsfeed_reports`
--
ALTER TABLE `newsfeed_reports`
  ADD UNIQUE KEY `newsfeedid_2` (`newsfeedid`,`userid`),
  ADD KEY `newsfeedid` (`newsfeedid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `newsfeed_unfollow`
--
ALTER TABLE `newsfeed_unfollow`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid_2` (`userid`,`newsfeedid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `newsfeedid` (`newsfeedid`);

--
-- Indexes for table `newsfeed_users`
--
ALTER TABLE `newsfeed_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`),
  ADD KEY `newsfeedid` (`newsfeedid`);

--
-- Indexes for table `newsletters`
--
ALTER TABLE `newsletters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `newsletters_articles`
--
ALTER TABLE `newsletters_articles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mailid` (`newsletterid`),
  ADD KEY `photo` (`photoid`);

--
-- Indexes for table `newsletters_images`
--
ALTER TABLE `newsletters_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`articleid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `os_county_electoral_division_region`
--
ALTER TABLE `os_county_electoral_division_region`
  ADD UNIQUE KEY `OGR_FID` (`OGR_FID`);

--
-- Indexes for table `paf_addresses`
--
ALTER TABLE `paf_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `udprn` (`udprn`),
  ADD KEY `postcodeid` (`postcodeid`);

--
-- Indexes for table `paf_buildingname`
--
ALTER TABLE `paf_buildingname`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `buildingname` (`buildingname`);

--
-- Indexes for table `paf_departmentname`
--
ALTER TABLE `paf_departmentname`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `departmentname` (`departmentname`);

--
-- Indexes for table `paf_dependentlocality`
--
ALTER TABLE `paf_dependentlocality`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dependentlocality` (`dependentlocality`);

--
-- Indexes for table `paf_dependentthoroughfaredescriptor`
--
ALTER TABLE `paf_dependentthoroughfaredescriptor`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dependentthoroughfaredescriptor` (`dependentthoroughfaredescriptor`);

--
-- Indexes for table `paf_doubledependentlocality`
--
ALTER TABLE `paf_doubledependentlocality`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `doubledependentlocality` (`doubledependentlocality`);

--
-- Indexes for table `paf_organisationname`
--
ALTER TABLE `paf_organisationname`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `organisationname` (`organisationname`);

--
-- Indexes for table `paf_pobox`
--
ALTER TABLE `paf_pobox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pobox` (`pobox`);

--
-- Indexes for table `paf_posttown`
--
ALTER TABLE `paf_posttown`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `posttown` (`posttown`);

--
-- Indexes for table `paf_subbuildingname`
--
ALTER TABLE `paf_subbuildingname`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subbuildingname` (`subbuildingname`);

--
-- Indexes for table `paf_thoroughfaredescriptor`
--
ALTER TABLE `paf_thoroughfaredescriptor`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `thoroughfaredescriptor` (`thoroughfaredescriptor`);

--
-- Indexes for table `partners_keys`
--
ALTER TABLE `partners_keys`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `plugin`
--
ALTER TABLE `plugin`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `polls`
--
ALTER TABLE `polls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `polls_users`
--
ALTER TABLE `polls_users`
  ADD UNIQUE KEY `pollid` (`pollid`,`userid`),
  ADD KEY `pollid_2` (`pollid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `prerender`
--
ALTER TABLE `prerender`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `url` (`url`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `schedules_users`
--
ALTER TABLE `schedules_users`
  ADD UNIQUE KEY `userid_2` (`userid`,`scheduleid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `scheduleid` (`scheduleid`);

--
-- Indexes for table `search_history`
--
ALTER TABLE `search_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `date` (`date`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `id_3` (`id`,`series`,`token`),
  ADD KEY `date` (`date`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `shortlinks`
--
ALTER TABLE `shortlinks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `name` (`name`);

--
-- Indexes for table `spam_countries`
--
ALTER TABLE `spam_countries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `country` (`country`);

--
-- Indexes for table `spam_keywords`
--
ALTER TABLE `spam_keywords`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spam_users`
--
ALTER TABLE `spam_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`),
  ADD KEY `byuserid` (`byuserid`),
  ADD KEY `added` (`added`),
  ADD KEY `collection` (`collection`);

--
-- Indexes for table `spam_whitelist_ips`
--
ALTER TABLE `spam_whitelist_ips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `ip` (`ip`);

--
-- Indexes for table `spam_whitelist_links`
--
ALTER TABLE `spam_whitelist_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `domain` (`domain`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `spam_whitelist_subjects`
--
ALTER TABLE `spam_whitelist_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `ip` (`subject`);

--
-- Indexes for table `stats`
--
ALTER TABLE `stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`,`type`,`groupid`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `type` (`type`,`date`,`groupid`);

--
-- Indexes for table `supporters`
--
ALTER TABLE `supporters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `name` (`name`,`type`,`email`),
  ADD KEY `display` (`display`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `yahooUserId` (`yahooUserId`),
  ADD UNIQUE KEY `yahooid` (`yahooid`),
  ADD KEY `systemrole` (`systemrole`),
  ADD KEY `added` (`added`,`lastaccess`),
  ADD KEY `fullname` (`fullname`),
  ADD KEY `firstname` (`firstname`),
  ADD KEY `lastname` (`lastname`),
  ADD KEY `firstname_2` (`firstname`,`lastname`),
  ADD KEY `gotrealemail` (`gotrealemail`),
  ADD KEY `suspectcount` (`suspectcount`),
  ADD KEY `suspectcount_2` (`suspectcount`),
  ADD KEY `lastlocation` (`lastlocation`),
  ADD KEY `lastrelevantcheck` (`lastrelevantcheck`);

--
-- Indexes for table `users_addresses`
--
ALTER TABLE `users_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid_2` (`userid`,`pafid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `pafid` (`pafid`);

--
-- Indexes for table `users_banned`
--
ALTER TABLE `users_banned`
  ADD UNIQUE KEY `userid_2` (`userid`,`groupid`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `date` (`date`),
  ADD KEY `byuser` (`byuser`);

--
-- Indexes for table `users_chatlists`
--
ALTER TABLE `users_chatlists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid_2` (`userid`,`key`),
  ADD KEY `userid` (`userid`) USING BTREE;

--
-- Indexes for table `users_chatlists_index`
--
ALTER TABLE `users_chatlists_index`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chatid_3` (`chatid`,`chatlistid`,`userid`),
  ADD KEY `chatid` (`chatlistid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `chatid_2` (`chatid`);

--
-- Indexes for table `users_comments`
--
ALTER TABLE `users_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `modid` (`byuserid`),
  ADD KEY `userid` (`userid`,`groupid`);

--
-- Indexes for table `users_dashboard`
--
ALTER TABLE `users_dashboard`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `systemwide` (`systemwide`);

--
-- Indexes for table `users_donations`
--
ALTER TABLE `users_donations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `TransactionID` (`TransactionID`),
  ADD KEY `userid` (`userid`),
  ADD KEY `GrossAmount` (`GrossAmount`),
  ADD KEY `timestamp` (`timestamp`,`GrossAmount`),
  ADD KEY `timestamp_2` (`timestamp`,`userid`,`GrossAmount`);

--
-- Indexes for table `users_donations_asks`
--
ALTER TABLE `users_donations_asks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `users_emails`
--
ALTER TABLE `users_emails`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `validatekey` (`validatekey`),
  ADD KEY `userid` (`userid`),
  ADD KEY `validated` (`validated`),
  ADD KEY `canon` (`canon`),
  ADD KEY `backwards` (`backwards`),
  ADD KEY `bounced` (`bounced`),
  ADD KEY `viewed` (`viewed`),
  ADD KEY `md5hash` (`md5hash`);

--
-- Indexes for table `users_exports`
--
ALTER TABLE `users_exports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `completed` (`completed`);

--
-- Indexes for table `users_images`
--
ALTER TABLE `users_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`userid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `users_invitations`
--
ALTER TABLE `users_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid_2` (`userid`,`email`),
  ADD KEY `userid` (`userid`),
  ADD KEY `email` (`email`);

--
-- Indexes for table `users_kudos`
--
ALTER TABLE `users_kudos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`);

--
-- Indexes for table `users_logins`
--
ALTER TABLE `users_logins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`uid`,`type`),
  ADD UNIQUE KEY `userid_3` (`userid`,`type`,`uid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `validated` (`lastaccess`);

--
-- Indexes for table `users_modmails`
--
ALTER TABLE `users_modmails`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `logid` (`logid`),
  ADD KEY `userid_2` (`userid`,`groupid`);

--
-- Indexes for table `users_nearby`
--
ALTER TABLE `users_nearby`
  ADD UNIQUE KEY `userid_2` (`userid`,`msgid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `msgid` (`msgid`),
  ADD KEY `timestamp` (`timestamp`);

--
-- Indexes for table `users_notifications`
--
ALTER TABLE `users_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `newsfeedid` (`newsfeedid`),
  ADD KEY `touser` (`touser`),
  ADD KEY `fromuser` (`fromuser`),
  ADD KEY `userid` (`touser`,`id`,`seen`),
  ADD KEY `touser_2` (`timestamp`,`seen`);

--
-- Indexes for table `users_nudges`
--
ALTER TABLE `users_nudges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fromuser` (`fromuser`),
  ADD KEY `touser` (`touser`);

--
-- Indexes for table `users_phones`
--
ALTER TABLE `users_phones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `users_push_notifications`
--
ALTER TABLE `users_push_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subscription` (`subscription`),
  ADD KEY `userid` (`userid`,`type`),
  ADD KEY `type` (`type`);

--
-- Indexes for table `users_requests`
--
ALTER TABLE `users_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `addressid` (`addressid`),
  ADD KEY `userid` (`userid`),
  ADD KEY `completedby` (`completedby`),
  ADD KEY `completed` (`completed`);

--
-- Indexes for table `users_searches`
--
ALTER TABLE `users_searches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`,`term`),
  ADD KEY `locationid` (`locationid`),
  ADD KEY `userid_2` (`userid`),
  ADD KEY `maxmsg` (`maxmsg`),
  ADD KEY `userid_3` (`userid`,`date`);

--
-- Indexes for table `users_stories`
--
ALTER TABLE `users_stories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `date` (`date`),
  ADD KEY `reviewed` (`reviewed`,`public`,`newsletterreviewed`);

--
-- Indexes for table `users_stories_likes`
--
ALTER TABLE `users_stories_likes`
  ADD UNIQUE KEY `storyid_2` (`storyid`,`userid`),
  ADD KEY `storyid` (`storyid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `users_stories_requested`
--
ALTER TABLE `users_stories_requested`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `users_thanks`
--
ALTER TABLE `users_thanks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `userid` (`userid`);

--
-- Indexes for table `visualise`
--
ALTER TABLE `visualise`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `msgid_2` (`msgid`),
  ADD KEY `fromuser` (`fromuser`),
  ADD KEY `touser` (`touser`),
  ADD KEY `attid` (`attid`);

--
-- Indexes for table `volunteering`
--
ALTER TABLE `volunteering`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userid` (`userid`),
  ADD KEY `title` (`title`),
  ADD KEY `deletedby` (`deletedby`);

--
-- Indexes for table `volunteering_dates`
--
ALTER TABLE `volunteering_dates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `start` (`start`),
  ADD KEY `eventid` (`volunteeringid`);

--
-- Indexes for table `volunteering_groups`
--
ALTER TABLE `volunteering_groups`
  ADD UNIQUE KEY `eventid_2` (`volunteeringid`,`groupid`),
  ADD KEY `eventid` (`volunteeringid`),
  ADD KEY `groupid` (`groupid`);

--
-- Indexes for table `volunteering_images`
--
ALTER TABLE `volunteering_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `incomingid` (`opportunityid`),
  ADD KEY `hash` (`hash`);

--
-- Indexes for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `voucher` (`voucher`),
  ADD KEY `groupid` (`groupid`),
  ADD KEY `userid` (`userid`);

--
-- Indexes for table `weights`
--
ALTER TABLE `weights`
  ADD PRIMARY KEY (`name`);

--
-- Indexes for table `words`
--
ALTER TABLE `words`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `word_2` (`word`),
  ADD KEY `popularity` (`popularity`),
  ADD KEY `word` (`word`,`popularity`),
  ADD KEY `soundex` (`soundex`,`popularity`),
  ADD KEY `firstthree` (`firstthree`,`popularity`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `abtest`
--
ALTER TABLE `abtest`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4505815;
--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6211;
--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8191;
--
-- AUTO_INCREMENT for table `alerts_tracking`
--
ALTER TABLE `alerts_tracking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=173086;
--
-- AUTO_INCREMENT for table `authorities`
--
ALTER TABLE `authorities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=117370;
--
-- AUTO_INCREMENT for table `aviva_history`
--
ALTER TABLE `aviva_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24981;
--
-- AUTO_INCREMENT for table `aviva_votes`
--
ALTER TABLE `aviva_votes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2382421;
--
-- AUTO_INCREMENT for table `bounces`
--
ALTER TABLE `bounces`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15223075;
--
-- AUTO_INCREMENT for table `bounces_emails`
--
ALTER TABLE `bounces_emails`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20395528;
--
-- AUTO_INCREMENT for table `chat_images`
--
ALTER TABLE `chat_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72847;
--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13808950;
--
-- AUTO_INCREMENT for table `chat_messages_byemail`
--
ALTER TABLE `chat_messages_byemail`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1957132;
--
-- AUTO_INCREMENT for table `chat_messages_held`
--
ALTER TABLE `chat_messages_held`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=280;
--
-- AUTO_INCREMENT for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4324339;
--
-- AUTO_INCREMENT for table `chat_roster`
--
ALTER TABLE `chat_roster`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=332437348;
--
-- AUTO_INCREMENT for table `communityevents`
--
ALTER TABLE `communityevents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137401;
--
-- AUTO_INCREMENT for table `communityevents_dates`
--
ALTER TABLE `communityevents_dates`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137542;
--
-- AUTO_INCREMENT for table `communityevents_images`
--
ALTER TABLE `communityevents_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8692;
--
-- AUTO_INCREMENT for table `ebay_favourites`
--
ALTER TABLE `ebay_favourites`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27139;
--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of group', AUTO_INCREMENT=464881;
--
-- AUTO_INCREMENT for table `groups_digests`
--
ALTER TABLE `groups_digests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=776915506;
--
-- AUTO_INCREMENT for table `groups_facebook`
--
ALTER TABLE `groups_facebook`
  MODIFY `uid` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3784;
--
-- AUTO_INCREMENT for table `groups_facebook_toshare`
--
ALTER TABLE `groups_facebook_toshare`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27557023;
--
-- AUTO_INCREMENT for table `groups_images`
--
ALTER TABLE `groups_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4573;
--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2394487;
--
-- AUTO_INCREMENT for table `items_non`
--
ALTER TABLE `items_non`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3715108;
--
-- AUTO_INCREMENT for table `link_previews`
--
ALTER TABLE `link_previews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2272;
--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9491719;
--
-- AUTO_INCREMENT for table `locations_grids`
--
ALTER TABLE `locations_grids`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=771541;
--
-- AUTO_INCREMENT for table `logos`
--
ALTER TABLE `logos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=235;
--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID', AUTO_INCREMENT=201838831;
--
-- AUTO_INCREMENT for table `logs_api`
--
ALTER TABLE `logs_api`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22886143;
--
-- AUTO_INCREMENT for table `logs_emails`
--
ALTER TABLE `logs_emails`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=425404420;
--
-- AUTO_INCREMENT for table `logs_errors`
--
ALTER TABLE `logs_errors`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111385;
--
-- AUTO_INCREMENT for table `logs_events`
--
ALTER TABLE `logs_events`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `logs_profile`
--
ALTER TABLE `logs_profile`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `logs_sql`
--
ALTER TABLE `logs_sql`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=659530219;
--
-- AUTO_INCREMENT for table `logs_src`
--
ALTER TABLE `logs_src`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53219860;
--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44537164;
--
-- AUTO_INCREMENT for table `memberships_history`
--
ALTER TABLE `memberships_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34703929;
--
-- AUTO_INCREMENT for table `memberships_yahoo`
--
ALTER TABLE `memberships_yahoo`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27259747;
--
-- AUTO_INCREMENT for table `memberships_yahoo_dump`
--
ALTER TABLE `memberships_yahoo_dump`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1463284;
--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique iD', AUTO_INCREMENT=47727757;
--
-- AUTO_INCREMENT for table `messages_attachments`
--
ALTER TABLE `messages_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9753025;
--
-- AUTO_INCREMENT for table `messages_attachments_items`
--
ALTER TABLE `messages_attachments_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3170347;
--
-- AUTO_INCREMENT for table `messages_drafts`
--
ALTER TABLE `messages_drafts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1998211;
--
-- AUTO_INCREMENT for table `messages_history`
--
ALTER TABLE `messages_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique iD', AUTO_INCREMENT=3269716;
--
-- AUTO_INCREMENT for table `messages_outcomes`
--
ALTER TABLE `messages_outcomes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1414786;
--
-- AUTO_INCREMENT for table `messages_outcomes_intended`
--
ALTER TABLE `messages_outcomes_intended`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=359140;
--
-- AUTO_INCREMENT for table `messages_postings`
--
ALTER TABLE `messages_postings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2417368;
--
-- AUTO_INCREMENT for table `messages_promises`
--
ALTER TABLE `messages_promises`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=173737;
--
-- AUTO_INCREMENT for table `messages_reneged`
--
ALTER TABLE `messages_reneged`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14617;
--
-- AUTO_INCREMENT for table `messages_spamham`
--
ALTER TABLE `messages_spamham`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61189;
--
-- AUTO_INCREMENT for table `modnotifs`
--
ALTER TABLE `modnotifs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75655;
--
-- AUTO_INCREMENT for table `mod_bulkops`
--
ALTER TABLE `mod_bulkops`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30784;
--
-- AUTO_INCREMENT for table `mod_bulkops_run`
--
ALTER TABLE `mod_bulkops_run`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5080951;
--
-- AUTO_INCREMENT for table `mod_configs`
--
ALTER TABLE `mod_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of config', AUTO_INCREMENT=67162;
--
-- AUTO_INCREMENT for table `mod_stdmsgs`
--
ALTER TABLE `mod_stdmsgs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of standard message', AUTO_INCREMENT=193495;
--
-- AUTO_INCREMENT for table `newsfeed`
--
ALTER TABLE `newsfeed`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41803;
--
-- AUTO_INCREMENT for table `newsfeed_images`
--
ALTER TABLE `newsfeed_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1507;
--
-- AUTO_INCREMENT for table `newsfeed_unfollow`
--
ALTER TABLE `newsfeed_unfollow`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2383;
--
-- AUTO_INCREMENT for table `newsfeed_users`
--
ALTER TABLE `newsfeed_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22137211;
--
-- AUTO_INCREMENT for table `newsletters`
--
ALTER TABLE `newsletters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=505;
--
-- AUTO_INCREMENT for table `newsletters_articles`
--
ALTER TABLE `newsletters_articles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2044;
--
-- AUTO_INCREMENT for table `newsletters_images`
--
ALTER TABLE `newsletters_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=199;
--
-- AUTO_INCREMENT for table `os_county_electoral_division_region`
--
ALTER TABLE `os_county_electoral_division_region`
  MODIFY `OGR_FID` int(11) NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `paf_addresses`
--
ALTER TABLE `paf_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121995451;
--
-- AUTO_INCREMENT for table `paf_buildingname`
--
ALTER TABLE `paf_buildingname`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7910;
--
-- AUTO_INCREMENT for table `paf_departmentname`
--
ALTER TABLE `paf_departmentname`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64669;
--
-- AUTO_INCREMENT for table `paf_dependentlocality`
--
ALTER TABLE `paf_dependentlocality`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68932;
--
-- AUTO_INCREMENT for table `paf_dependentthoroughfaredescriptor`
--
ALTER TABLE `paf_dependentthoroughfaredescriptor`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74692;
--
-- AUTO_INCREMENT for table `paf_doubledependentlocality`
--
ALTER TABLE `paf_doubledependentlocality`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11827;
--
-- AUTO_INCREMENT for table `paf_organisationname`
--
ALTER TABLE `paf_organisationname`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48076;
--
-- AUTO_INCREMENT for table `paf_pobox`
--
ALTER TABLE `paf_pobox`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=418288;
--
-- AUTO_INCREMENT for table `paf_posttown`
--
ALTER TABLE `paf_posttown`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4414;
--
-- AUTO_INCREMENT for table `paf_subbuildingname`
--
ALTER TABLE `paf_subbuildingname`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4196584;
--
-- AUTO_INCREMENT for table `paf_thoroughfaredescriptor`
--
ALTER TABLE `paf_thoroughfaredescriptor`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1090978;
--
-- AUTO_INCREMENT for table `partners_keys`
--
ALTER TABLE `partners_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;
--
-- AUTO_INCREMENT for table `plugin`
--
ALTER TABLE `plugin`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5433892;
--
-- AUTO_INCREMENT for table `polls`
--
ALTER TABLE `polls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=268;
--
-- AUTO_INCREMENT for table `prerender`
--
ALTER TABLE `prerender`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14977411;
--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=295;
--
-- AUTO_INCREMENT for table `search_history`
--
ALTER TABLE `search_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35878660;
--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9422113;
--
-- AUTO_INCREMENT for table `shortlinks`
--
ALTER TABLE `shortlinks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11665;
--
-- AUTO_INCREMENT for table `spam_countries`
--
ALTER TABLE `spam_countries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
--
-- AUTO_INCREMENT for table `spam_keywords`
--
ALTER TABLE `spam_keywords`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=472;
--
-- AUTO_INCREMENT for table `spam_users`
--
ALTER TABLE `spam_users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23065;
--
-- AUTO_INCREMENT for table `spam_whitelist_ips`
--
ALTER TABLE `spam_whitelist_ips`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5071;
--
-- AUTO_INCREMENT for table `spam_whitelist_links`
--
ALTER TABLE `spam_whitelist_links`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=176407;
--
-- AUTO_INCREMENT for table `spam_whitelist_subjects`
--
ALTER TABLE `spam_whitelist_subjects`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14866;
--
-- AUTO_INCREMENT for table `stats`
--
ALTER TABLE `stats`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39799900;
--
-- AUTO_INCREMENT for table `supporters`
--
ALTER TABLE `supporters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=133569;
--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35934889;
--
-- AUTO_INCREMENT for table `users_addresses`
--
ALTER TABLE `users_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17698;
--
-- AUTO_INCREMENT for table `users_chatlists`
--
ALTER TABLE `users_chatlists`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27464374;
--
-- AUTO_INCREMENT for table `users_chatlists_index`
--
ALTER TABLE `users_chatlists_index`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=223555921;
--
-- AUTO_INCREMENT for table `users_comments`
--
ALTER TABLE `users_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=154207;
--
-- AUTO_INCREMENT for table `users_dashboard`
--
ALTER TABLE `users_dashboard`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=339211;
--
-- AUTO_INCREMENT for table `users_donations`
--
ALTER TABLE `users_donations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1842628;
--
-- AUTO_INCREMENT for table `users_donations_asks`
--
ALTER TABLE `users_donations_asks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;
--
-- AUTO_INCREMENT for table `users_emails`
--
ALTER TABLE `users_emails`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120464701;
--
-- AUTO_INCREMENT for table `users_exports`
--
ALTER TABLE `users_exports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=403;
--
-- AUTO_INCREMENT for table `users_images`
--
ALTER TABLE `users_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3041881;
--
-- AUTO_INCREMENT for table `users_invitations`
--
ALTER TABLE `users_invitations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11899;
--
-- AUTO_INCREMENT for table `users_kudos`
--
ALTER TABLE `users_kudos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39789;
--
-- AUTO_INCREMENT for table `users_logins`
--
ALTER TABLE `users_logins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7464271;
--
-- AUTO_INCREMENT for table `users_modmails`
--
ALTER TABLE `users_modmails`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=549496;
--
-- AUTO_INCREMENT for table `users_notifications`
--
ALTER TABLE `users_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1669027;
--
-- AUTO_INCREMENT for table `users_nudges`
--
ALTER TABLE `users_nudges`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64576;
--
-- AUTO_INCREMENT for table `users_phones`
--
ALTER TABLE `users_phones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `users_push_notifications`
--
ALTER TABLE `users_push_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8813623;
--
-- AUTO_INCREMENT for table `users_requests`
--
ALTER TABLE `users_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5170;
--
-- AUTO_INCREMENT for table `users_searches`
--
ALTER TABLE `users_searches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17727085;
--
-- AUTO_INCREMENT for table `users_stories`
--
ALTER TABLE `users_stories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4009;
--
-- AUTO_INCREMENT for table `users_stories_requested`
--
ALTER TABLE `users_stories_requested`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=116512;
--
-- AUTO_INCREMENT for table `users_thanks`
--
ALTER TABLE `users_thanks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10576;
--
-- AUTO_INCREMENT for table `visualise`
--
ALTER TABLE `visualise`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=446542;
--
-- AUTO_INCREMENT for table `volunteering`
--
ALTER TABLE `volunteering`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6760;
--
-- AUTO_INCREMENT for table `volunteering_dates`
--
ALTER TABLE `volunteering_dates`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2398;
--
-- AUTO_INCREMENT for table `volunteering_images`
--
ALTER TABLE `volunteering_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
--
-- AUTO_INCREMENT for table `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4000;
--
-- AUTO_INCREMENT for table `words`
--
ALTER TABLE `words`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12167641;
--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`createdby`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `alerts_tracking`
--
ALTER TABLE `alerts_tracking`
  ADD CONSTRAINT `_alerts_tracking_ibfk_3` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `alerts_tracking_ibfk_1` FOREIGN KEY (`alertid`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `alerts_tracking_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `alerts_tracking_ibfk_4` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bounces_emails`
--
ALTER TABLE `bounces_emails`
  ADD CONSTRAINT `bounces_emails_ibfk_1` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_images`
--
ALTER TABLE `chat_images`
  ADD CONSTRAINT `_chat_images_ibfk_1` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `_chat_messages_ibfk_1` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `_chat_messages_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `_chat_messages_ibfk_3` FOREIGN KEY (`refmsgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `_chat_messages_ibfk_4` FOREIGN KEY (`reviewedby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `_chat_messages_ibfk_5` FOREIGN KEY (`refchatid`) REFERENCES `chat_rooms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`imageid`) REFERENCES `chat_images` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`scheduleid`) REFERENCES `schedules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages_byemail`
--
ALTER TABLE `chat_messages_byemail`
  ADD CONSTRAINT `chat_messages_byemail_ibfk_1` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_messages_byemail_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages_held`
--
ALTER TABLE `chat_messages_held`
  ADD CONSTRAINT `chat_messages_held_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_messages_held_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD CONSTRAINT `chat_rooms_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_rooms_ibfk_2` FOREIGN KEY (`user1`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_rooms_ibfk_3` FOREIGN KEY (`user2`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_roster`
--
ALTER TABLE `chat_roster`
  ADD CONSTRAINT `chat_roster_ibfk_1` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_roster_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `communityevents`
--
ALTER TABLE `communityevents`
  ADD CONSTRAINT `communityevents_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `communityevents_dates`
--
ALTER TABLE `communityevents_dates`
  ADD CONSTRAINT `communityevents_dates_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `communityevents_groups`
--
ALTER TABLE `communityevents_groups`
  ADD CONSTRAINT `communityevents_groups_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `communityevents_groups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `communityevents_images`
--
ALTER TABLE `communityevents_images`
  ADD CONSTRAINT `communityevents_images_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `groups`
--
ALTER TABLE `groups`
  ADD CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`profile`) REFERENCES `groups_images` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `groups_ibfk_2` FOREIGN KEY (`cover`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `groups_ibfk_3` FOREIGN KEY (`authorityid`) REFERENCES `authorities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `groups_digests`
--
ALTER TABLE `groups_digests`
  ADD CONSTRAINT `groups_digests_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `groups_digests_ibfk_3` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `groups_facebook`
--
ALTER TABLE `groups_facebook`
  ADD CONSTRAINT `groups_facebook_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `groups_facebook_shares`
--
ALTER TABLE `groups_facebook_shares`
  ADD CONSTRAINT `groups_facebook_shares_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `groups_facebook_shares_ibfk_2` FOREIGN KEY (`uid`) REFERENCES `groups_facebook` (`uid`) ON DELETE CASCADE;

--
-- Constraints for table `groups_images`
--
ALTER TABLE `groups_images`
  ADD CONSTRAINT `groups_images_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `groups_twitter`
--
ALTER TABLE `groups_twitter`
  ADD CONSTRAINT `groups_twitter_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `groups_twitter_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `groups_twitter_ibfk_3` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `items_index`
--
ALTER TABLE `items_index`
  ADD CONSTRAINT `items_index_ibfk_1` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `items_index_ibfk_2` FOREIGN KEY (`wordid`) REFERENCES `words` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `locations`
--
ALTER TABLE `locations`
  ADD CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`gridid`) REFERENCES `locations_grids` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `locations_excluded`
--
ALTER TABLE `locations_excluded`
  ADD CONSTRAINT `_locations_excluded_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `locations_excluded_ibfk_3` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `locations_excluded_ibfk_4` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `locations_grids_touches`
--
ALTER TABLE `locations_grids_touches`
  ADD CONSTRAINT `locations_grids_touches_ibfk_1` FOREIGN KEY (`gridid`) REFERENCES `locations_grids` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `locations_grids_touches_ibfk_2` FOREIGN KEY (`touches`) REFERENCES `locations_grids` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `locations_spatial`
--
ALTER TABLE `locations_spatial`
  ADD CONSTRAINT `locations_spatial_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `logs_emails`
--
ALTER TABLE `logs_emails`
  ADD CONSTRAINT `logs_emails_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships`
--
ALTER TABLE `memberships`
  ADD CONSTRAINT `memberships_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `memberships_ibfk_3` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `memberships_ibfk_4` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `memberships_ibfk_5` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `memberships_history`
--
ALTER TABLE `memberships_history`
  ADD CONSTRAINT `memberships_history_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `memberships_history_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships_yahoo`
--
ALTER TABLE `memberships_yahoo`
  ADD CONSTRAINT `_memberships_yahoo_ibfk_1` FOREIGN KEY (`membershipid`) REFERENCES `memberships` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `memberships_yahoo_ibfk_1` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships_yahoo_dump`
--
ALTER TABLE `memberships_yahoo_dump`
  ADD CONSTRAINT `memberships_yahoo_dump_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `_messages_ibfk_1` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `_messages_ibfk_2` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `_messages_ibfk_3` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION;

--
-- Constraints for table `messages_attachments`
--
ALTER TABLE `messages_attachments`
  ADD CONSTRAINT `_messages_attachments_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_attachments_items`
--
ALTER TABLE `messages_attachments_items`
  ADD CONSTRAINT `messages_attachments_items_ibfk_1` FOREIGN KEY (`attid`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_attachments_items_ibfk_2` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_drafts`
--
ALTER TABLE `messages_drafts`
  ADD CONSTRAINT `messages_drafts_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_drafts_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_drafts_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `messages_history`
--
ALTER TABLE `messages_history`
  ADD CONSTRAINT `_messages_history_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `_messages_history_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_index`
--
ALTER TABLE `messages_index`
  ADD CONSTRAINT `_messages_index_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `_messages_index_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `messages_index_ibfk_1` FOREIGN KEY (`wordid`) REFERENCES `words` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_items`
--
ALTER TABLE `messages_items`
  ADD CONSTRAINT `messages_items_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_items_ibfk_2` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_likes`
--
ALTER TABLE `messages_likes`
  ADD CONSTRAINT `messages_likes_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_outcomes`
--
ALTER TABLE `messages_outcomes`
  ADD CONSTRAINT `messages_outcomes_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_outcomes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `messages_outcomes_intended`
--
ALTER TABLE `messages_outcomes_intended`
  ADD CONSTRAINT `messages_outcomes_intended_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_postings`
--
ALTER TABLE `messages_postings`
  ADD CONSTRAINT `messages_postings_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_postings_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_promises`
--
ALTER TABLE `messages_promises`
  ADD CONSTRAINT `messages_promises_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_promises_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_related`
--
ALTER TABLE `messages_related`
  ADD CONSTRAINT `messages_related_ibfk_1` FOREIGN KEY (`id1`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_related_ibfk_2` FOREIGN KEY (`id2`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_reneged`
--
ALTER TABLE `messages_reneged`
  ADD CONSTRAINT `messages_reneged_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_reneged_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages_spamham`
--
ALTER TABLE `messages_spamham`
  ADD CONSTRAINT `messages_spamham_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `modnotifs`
--
ALTER TABLE `modnotifs`
  ADD CONSTRAINT `modnotifs_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mod_bulkops`
--
ALTER TABLE `mod_bulkops`
  ADD CONSTRAINT `mod_bulkops_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mod_bulkops_run`
--
ALTER TABLE `mod_bulkops_run`
  ADD CONSTRAINT `mod_bulkops_run_ibfk_1` FOREIGN KEY (`bulkopid`) REFERENCES `mod_bulkops` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mod_bulkops_run_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mod_configs`
--
ALTER TABLE `mod_configs`
  ADD CONSTRAINT `mod_configs_ibfk_1` FOREIGN KEY (`createdby`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `mod_stdmsgs`
--
ALTER TABLE `mod_stdmsgs`
  ADD CONSTRAINT `mod_stdmsgs_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsfeed`
--
ALTER TABLE `newsfeed`
  ADD CONSTRAINT `newsfeed_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_ibfk_4` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_ibfk_5` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_ibfk_6` FOREIGN KEY (`publicityid`) REFERENCES `groups_facebook_toshare` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_ibfk_7` FOREIGN KEY (`storyid`) REFERENCES `users_stories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsfeed_likes`
--
ALTER TABLE `newsfeed_likes`
  ADD CONSTRAINT `newsfeed_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_likes_ibfk_3` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsfeed_reports`
--
ALTER TABLE `newsfeed_reports`
  ADD CONSTRAINT `newsfeed_reports_ibfk_1` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_reports_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsfeed_unfollow`
--
ALTER TABLE `newsfeed_unfollow`
  ADD CONSTRAINT `newsfeed_unfollow_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsfeed_unfollow_ibfk_2` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsfeed_users`
--
ALTER TABLE `newsfeed_users`
  ADD CONSTRAINT `newsfeed_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsletters`
--
ALTER TABLE `newsletters`
  ADD CONSTRAINT `newsletters_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `newsletters_articles`
--
ALTER TABLE `newsletters_articles`
  ADD CONSTRAINT `newsletters_articles_ibfk_1` FOREIGN KEY (`newsletterid`) REFERENCES `newsletters` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `newsletters_articles_ibfk_2` FOREIGN KEY (`photoid`) REFERENCES `newsletters_images` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `newsletters_images`
--
ALTER TABLE `newsletters_images`
  ADD CONSTRAINT `newsletters_images_ibfk_1` FOREIGN KEY (`articleid`) REFERENCES `newsletters_articles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `paf_addresses`
--
ALTER TABLE `paf_addresses`
  ADD CONSTRAINT `paf_addresses_ibfk_11` FOREIGN KEY (`postcodeid`) REFERENCES `locations` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `plugin`
--
ALTER TABLE `plugin`
  ADD CONSTRAINT `plugin_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `polls`
--
ALTER TABLE `polls`
  ADD CONSTRAINT `polls_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `polls_users`
--
ALTER TABLE `polls_users`
  ADD CONSTRAINT `polls_users_ibfk_1` FOREIGN KEY (`pollid`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `polls_users_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules_users`
--
ALTER TABLE `schedules_users`
  ADD CONSTRAINT `schedules_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedules_users_ibfk_2` FOREIGN KEY (`scheduleid`) REFERENCES `schedules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shortlinks`
--
ALTER TABLE `shortlinks`
  ADD CONSTRAINT `shortlinks_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `spam_users`
--
ALTER TABLE `spam_users`
  ADD CONSTRAINT `spam_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `spam_users_ibfk_2` FOREIGN KEY (`byuserid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `spam_whitelist_links`
--
ALTER TABLE `spam_whitelist_links`
  ADD CONSTRAINT `spam_whitelist_links_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `stats`
--
ALTER TABLE `stats`
  ADD CONSTRAINT `_stats_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`lastlocation`) REFERENCES `locations` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users_addresses`
--
ALTER TABLE `users_addresses`
  ADD CONSTRAINT `users_addresses_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_addresses_ibfk_3` FOREIGN KEY (`pafid`) REFERENCES `paf_addresses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_banned`
--
ALTER TABLE `users_banned`
  ADD CONSTRAINT `users_banned_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_banned_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_banned_ibfk_3` FOREIGN KEY (`byuser`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users_chatlists`
--
ALTER TABLE `users_chatlists`
  ADD CONSTRAINT `users_chatlists_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_chatlists_index`
--
ALTER TABLE `users_chatlists_index`
  ADD CONSTRAINT `users_chatlists_index_ibfk_1` FOREIGN KEY (`chatlistid`) REFERENCES `users_chatlists` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_chatlists_index_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_chatlists_index_ibfk_3` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_comments`
--
ALTER TABLE `users_comments`
  ADD CONSTRAINT `users_comments_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_comments_ibfk_2` FOREIGN KEY (`byuserid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_comments_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_dashboard`
--
ALTER TABLE `users_dashboard`
  ADD CONSTRAINT `users_dashboard_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_dashboard_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_donations`
--
ALTER TABLE `users_donations`
  ADD CONSTRAINT `users_donations_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users_donations_asks`
--
ALTER TABLE `users_donations_asks`
  ADD CONSTRAINT `users_donations_asks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_emails`
--
ALTER TABLE `users_emails`
  ADD CONSTRAINT `users_emails_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_exports`
--
ALTER TABLE `users_exports`
  ADD CONSTRAINT `users_exports_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_images`
--
ALTER TABLE `users_images`
  ADD CONSTRAINT `users_images_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_invitations`
--
ALTER TABLE `users_invitations`
  ADD CONSTRAINT `users_invitations_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_kudos`
--
ALTER TABLE `users_kudos`
  ADD CONSTRAINT `users_kudos_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_logins`
--
ALTER TABLE `users_logins`
  ADD CONSTRAINT `users_logins_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_nearby`
--
ALTER TABLE `users_nearby`
  ADD CONSTRAINT `users_nearby_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_nearby_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_notifications`
--
ALTER TABLE `users_notifications`
  ADD CONSTRAINT `users_notifications_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_notifications_ibfk_2` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_notifications_ibfk_3` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_nudges`
--
ALTER TABLE `users_nudges`
  ADD CONSTRAINT `users_nudges_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_nudges_ibfk_2` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_phones`
--
ALTER TABLE `users_phones`
  ADD CONSTRAINT `users_phones_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_push_notifications`
--
ALTER TABLE `users_push_notifications`
  ADD CONSTRAINT `users_push_notifications_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_requests`
--
ALTER TABLE `users_requests`
  ADD CONSTRAINT `users_requests_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_requests_ibfk_2` FOREIGN KEY (`addressid`) REFERENCES `users_addresses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_requests_ibfk_3` FOREIGN KEY (`completedby`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users_stories`
--
ALTER TABLE `users_stories`
  ADD CONSTRAINT `users_stories_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users_stories_likes`
--
ALTER TABLE `users_stories_likes`
  ADD CONSTRAINT `users_stories_likes_ibfk_1` FOREIGN KEY (`storyid`) REFERENCES `users_stories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_stories_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_stories_requested`
--
ALTER TABLE `users_stories_requested`
  ADD CONSTRAINT `users_stories_requested_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users_thanks`
--
ALTER TABLE `users_thanks`
  ADD CONSTRAINT `users_thanks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `visualise`
--
ALTER TABLE `visualise`
  ADD CONSTRAINT `_visualise_ibfk_4` FOREIGN KEY (`attid`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `visualise_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `visualise_ibfk_2` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `visualise_ibfk_3` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `volunteering`
--
ALTER TABLE `volunteering`
  ADD CONSTRAINT `volunteering_ibfk_1` FOREIGN KEY (`deletedby`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `volunteering_dates`
--
ALTER TABLE `volunteering_dates`
  ADD CONSTRAINT `volunteering_dates_ibfk_1` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `volunteering_groups`
--
ALTER TABLE `volunteering_groups`
  ADD CONSTRAINT `volunteering_groups_ibfk_1` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `volunteering_groups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `volunteering_images`
--
ALTER TABLE `volunteering_images`
  ADD CONSTRAINT `volunteering_images_ibfk_1` FOREIGN KEY (`opportunityid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD CONSTRAINT `vouchers_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vouchers_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `Delete Stranded Messages` ON SCHEDULE EVERY 1 DAY STARTS '2015-12-23 04:30:00' ON COMPLETION PRESERVE DISABLE ON SLAVE DO DELETE FROM messages WHERE id NOT IN (SELECT DISTINCT msgid FROM messages_groups)$$

CREATE DEFINER=`root`@`localhost` EVENT `Delete Non-Freegle Old Messages` ON SCHEDULE EVERY 1 DAY STARTS '2016-01-02 04:00:00' ON COMPLETION PRESERVE DISABLE ON SLAVE COMMENT 'Non-Freegle groups don''t have old messages preserved.' DO SELECT * FROM messages INNER JOIN messages_groups ON messages.id = messages_groups.msgid INNER JOIN groups ON messages_groups.groupid = groups.id WHERE  DATEDIFF(NOW(), `date`) > 31 AND groups.type != 'Freegle'$$

CREATE DEFINER=`root`@`localhost` EVENT `Delete Old Sessions` ON SCHEDULE EVERY 1 DAY STARTS '2016-01-29 04:00:00' ON COMPLETION PRESERVE DISABLE ON SLAVE DO DELETE FROM sessions WHERE DATEDIFF(NOW(), `date`) > 31$$

CREATE DEFINER=`root`@`localhost` EVENT `Delete Old API logs` ON SCHEDULE EVERY 1 DAY STARTS '2016-02-06 04:00:00' ON COMPLETION PRESERVE DISABLE ON SLAVE COMMENT 'Causes cluster hang - will replace with cron' DO DELETE FROM logs_api WHERE DATEDIFF(NOW(), `date`) > 2$$

CREATE DEFINER=`root`@`localhost` EVENT `Delete Old SQL Logs` ON SCHEDULE EVERY 1 DAY STARTS '2016-02-06 04:30:00' ON COMPLETION PRESERVE DISABLE ON SLAVE COMMENT 'Causes cluster hang - will replace with cron' DO DELETE FROM logs_sql WHERE DATEDIFF(NOW(), `date`) > 2$$

CREATE DEFINER=`root`@`localhost` EVENT `Update Member Counts` ON SCHEDULE EVERY 1 HOUR STARTS '2016-03-02 20:17:39' ON COMPLETION PRESERVE DISABLE ON SLAVE DO update groups set membercount = (select count(*) from memberships where groupid = groups.id)$$

CREATE DEFINER=`root`@`localhost` EVENT `Fix FBUser names` ON SCHEDULE EVERY 1 HOUR STARTS '2016-04-03 08:02:30' ON COMPLETION PRESERVE DISABLE ON SLAVE DO UPDATE users SET fullname = yahooid WHERE yahooid IS NOT NULL AND fullname LIKE  'fbuser%'$$

CREATE DEFINER=`root`@`localhost` EVENT `Delete Unlicensed Groups` ON SCHEDULE EVERY 1 DAY STARTS '2015-12-23 04:00:00' ON COMPLETION PRESERVE DISABLE ON SLAVE DO UPDATE groups SET publish = 0 WHERE licenserequired = 1 AND (licenseduntil IS NULL OR licenseduntil < NOW()) AND (trial IS NULL OR DATEDIFF(NOW(), trial) > 30)$$

DELIMITER ;

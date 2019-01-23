-- MySQL dump 10.13  Distrib 5.7.24, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: iznik
-- ------------------------------------------------------
-- Server version	5.7.21-20-57

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Temporary table structure for view `VW_Essex_Searches`
--

DROP TABLE IF EXISTS `VW_Essex_Searches`;
/*!50001 DROP VIEW IF EXISTS `VW_Essex_Searches`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `VW_Essex_Searches` AS SELECT 
 1 AS `DATE`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `VW_SupportCallAverage`
--

DROP TABLE IF EXISTS `VW_SupportCallAverage`;
/*!50001 DROP VIEW IF EXISTS `VW_SupportCallAverage`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `VW_SupportCallAverage` AS SELECT 
 1 AS `AVG(total)`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `VW_invalid_emails_by_month`
--

DROP TABLE IF EXISTS `VW_invalid_emails_by_month`;
/*!50001 DROP VIEW IF EXISTS `VW_invalid_emails_by_month`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `VW_invalid_emails_by_month` AS SELECT 
 1 AS `date`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `VW_not_on_central`
--

DROP TABLE IF EXISTS `VW_not_on_central`;
/*!50001 DROP VIEW IF EXISTS `VW_not_on_central`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `VW_not_on_central` AS SELECT 
 1 AS `id`,
 1 AS `nameshort`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `VW_recentqueries`
--

DROP TABLE IF EXISTS `VW_recentqueries`;
/*!50001 DROP VIEW IF EXISTS `VW_recentqueries`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `VW_recentqueries` AS SELECT 
 1 AS `id`,
 1 AS `chatid`,
 1 AS `userid`,
 1 AS `type`,
 1 AS `reportreason`,
 1 AS `refmsgid`,
 1 AS `refchatid`,
 1 AS `date`,
 1 AS `message`,
 1 AS `platform`,
 1 AS `seenbyall`,
 1 AS `reviewrequired`,
 1 AS `reviewedby`,
 1 AS `reviewrejected`,
 1 AS `spamscore`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `VW_routes`
--

DROP TABLE IF EXISTS `VW_routes`;
/*!50001 DROP VIEW IF EXISTS `VW_routes`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `VW_routes` AS SELECT 
 1 AS `route`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `abtest`
--

DROP TABLE IF EXISTS `abtest`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `abtest` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shown` bigint(20) unsigned NOT NULL,
  `action` bigint(20) unsigned NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `suggest` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_2` (`uid`,`variant`)
) ENGINE=InnoDB AUTO_INCREMENT=8313834 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For testing site changes to see which work';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `createdby` bigint(20) unsigned DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `complete` timestamp NULL DEFAULT NULL,
  `subject` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `createdby` (`createdby`)
) ENGINE=InnoDB AUTO_INCREMENT=7335 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Try all means to reach people with these';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `createdby` bigint(20) unsigned DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `from` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to` enum('Users','Mods') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Mods',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `groupprogress` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'For alerts to multiple groups',
  `complete` timestamp NULL DEFAULT NULL,
  `subject` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `askclick` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether to ask them to click to confirm receipt',
  `tryhard` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Whether to mail all mods addresses too',
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `createdby` (`createdby`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`createdby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9241 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Try all means to reach people with these';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alerts_tracking`
--

DROP TABLE IF EXISTS `alerts_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `alerts_tracking` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `alertid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `emailid` bigint(20) unsigned DEFAULT NULL,
  `type` enum('ModEmail','OwnerEmail','PushNotif','ModToolsNotif') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL,
  `response` enum('Read','Clicked','Bounce','Unsubscribe') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `alertid` (`alertid`),
  KEY `emailid` (`emailid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `_alerts_tracking_ibfk_3` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_tracking_ibfk_1` FOREIGN KEY (`alertid`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_tracking_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_tracking_ibfk_4` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=232897 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `authorities`
--

DROP TABLE IF EXISTS `authorities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `authorities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `polygon` geometry NOT NULL,
  `area_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `simplified` geometry DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`,`area_code`),
  SPATIAL KEY `polygon` (`polygon`),
  KEY `name_2` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=117424 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Counties and Unitary Authorities.  May be multigeometries';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `aviva_history`
--

DROP TABLE IF EXISTS `aviva_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aviva_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `position` int(11) NOT NULL,
  `votes` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24981 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `aviva_votes`
--

DROP TABLE IF EXISTS `aviva_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aviva_votes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `project` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `votes` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project` (`project`),
  KEY `timestamp` (`timestamp`),
  KEY `votes` (`votes`)
) ENGINE=InnoDB AUTO_INCREMENT=2382421 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bounces`
--

DROP TABLE IF EXISTS `bounces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bounces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `to` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `msg` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=49985868 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bounce messages received by email';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bounces_emails`
--

DROP TABLE IF EXISTS `bounces_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bounces_emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `emailid` bigint(20) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `permanent` tinyint(1) NOT NULL DEFAULT '0',
  `reset` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'If we have reset bounces for this email',
  PRIMARY KEY (`id`),
  KEY `emailid` (`emailid`,`date`),
  CONSTRAINT `bounces_emails_ibfk_1` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=83531259 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_images`
--

DROP TABLE IF EXISTS `chat_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chatmsgid` bigint(20) unsigned DEFAULT NULL,
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`chatmsgid`),
  KEY `hash` (`hash`),
  CONSTRAINT `_chat_images_ibfk_1` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=314394 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL COMMENT 'From',
  `type` enum('Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Default',
  `reportreason` enum('Spam','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refmsgid` bigint(20) unsigned DEFAULT NULL,
  `refchatid` bigint(20) unsigned DEFAULT NULL,
  `imageid` bigint(20) unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `message` text COLLATE utf8mb4_unicode_ci,
  `platform` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Whether this was created on the platform vs email',
  `seenbyall` tinyint(1) NOT NULL DEFAULT '0',
  `mailedtoall` tinyint(1) NOT NULL DEFAULT '0',
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether a volunteer should review before it''s passed on',
  `reviewedby` bigint(20) unsigned DEFAULT NULL COMMENT 'User id of volunteer who reviewed it',
  `reviewrejected` tinyint(1) NOT NULL DEFAULT '0',
  `spamscore` int(11) DEFAULT NULL COMMENT 'SpamAssassin score for mail replies',
  `facebookid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduleid` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chatid` (`chatid`),
  KEY `userid` (`userid`),
  KEY `chatid_2` (`chatid`,`date`),
  KEY `msgid` (`refmsgid`),
  KEY `date` (`date`,`seenbyall`),
  KEY `reviewedby` (`reviewedby`),
  KEY `reviewrequired` (`reviewrequired`),
  KEY `refchatid` (`refchatid`),
  KEY `refchatid_2` (`refchatid`),
  KEY `imageid` (`imageid`),
  KEY `scheduleid` (`scheduleid`),
  KEY `chatmax` (`chatid`,`id`,`userid`,`date`) USING BTREE,
  KEY `userid_2` (`userid`,`date`,`refmsgid`,`type`),
  CONSTRAINT `_chat_messages_ibfk_1` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_chat_messages_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_chat_messages_ibfk_3` FOREIGN KEY (`refmsgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_chat_messages_ibfk_4` FOREIGN KEY (`reviewedby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_chat_messages_ibfk_5` FOREIGN KEY (`refchatid`) REFERENCES `chat_rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`imageid`) REFERENCES `chat_images` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_ibfk_2` FOREIGN KEY (`scheduleid`) REFERENCES `users_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20009475 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages_byemail`
--

DROP TABLE IF EXISTS `chat_messages_byemail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_messages_byemail` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chatmsgid` bigint(20) unsigned NOT NULL,
  `msgid` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `chatmsgid` (`chatmsgid`),
  KEY `msgid` (`msgid`),
  CONSTRAINT `chat_messages_byemail_ibfk_1` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_byemail_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4198440 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages_held`
--

DROP TABLE IF EXISTS `chat_messages_held`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_messages_held` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `userid` (`userid`),
  CONSTRAINT `chat_messages_held_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_held_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=300 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_rooms`
--

DROP TABLE IF EXISTS `chat_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_rooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chattype` enum('Mod2Mod','User2Mod','User2User','Group') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User2User',
  `groupid` bigint(20) unsigned DEFAULT NULL COMMENT 'Restricted to a group',
  `user1` bigint(20) unsigned DEFAULT NULL COMMENT 'For DMs',
  `user2` bigint(20) unsigned DEFAULT NULL COMMENT 'For DMs',
  `description` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `synctofacebook` enum('Dont','RepliedOnFacebook','RepliedOnPlatform','PostedLink') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dont',
  `synctofacebookgroupid` bigint(20) unsigned DEFAULT NULL,
  `latestmessage` timestamp NULL DEFAULT NULL COMMENT 'Loosely up to date - cron',
  `msgvalid` int(10) unsigned NOT NULL DEFAULT '0',
  `msginvalid` int(10) unsigned NOT NULL DEFAULT '0',
  `flaggedspam` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user1_2` (`user1`,`user2`,`chattype`),
  KEY `user1` (`user1`),
  KEY `user2` (`user2`),
  KEY `synctofacebook` (`synctofacebook`),
  KEY `synctofacebookgroupid` (`synctofacebookgroupid`),
  KEY `chattype` (`chattype`),
  KEY `groupid` (`groupid`),
  KEY `typelatest` (`chattype`,`latestmessage`),
  CONSTRAINT `chat_rooms_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_rooms_ibfk_2` FOREIGN KEY (`user1`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_rooms_ibfk_3` FOREIGN KEY (`user2`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5764215 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_roster`
--

DROP TABLE IF EXISTS `chat_roster`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_roster` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Online','Away','Offline','Closed','Blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Online',
  `lastmsgseen` bigint(20) unsigned DEFAULT NULL,
  `lastemailed` timestamp NULL DEFAULT NULL,
  `lastmsgemailed` bigint(20) unsigned DEFAULT NULL,
  `lastip` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatid_2` (`chatid`,`userid`),
  KEY `chatid` (`chatid`),
  KEY `userid` (`userid`),
  KEY `date` (`date`),
  KEY `lastmsg` (`lastmsgseen`),
  KEY `lastip` (`lastip`),
  KEY `status` (`status`),
  CONSTRAINT `chat_roster_ibfk_1` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_roster_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=369899385 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents`
--

DROP TABLE IF EXISTS `communityevents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `communityevents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
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
  `legacyid` bigint(20) unsigned DEFAULT NULL COMMENT 'For migration from FDv1',
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `title` (`title`),
  KEY `legacyid` (`legacyid`),
  CONSTRAINT `communityevents_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=144369 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents_dates`
--

DROP TABLE IF EXISTS `communityevents_dates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `communityevents_dates` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `eventid` bigint(20) unsigned NOT NULL,
  `start` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `end` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `start` (`start`),
  KEY `eventid` (`eventid`),
  KEY `end` (`end`),
  CONSTRAINT `communityevents_dates_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=148407 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents_groups`
--

DROP TABLE IF EXISTS `communityevents_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `communityevents_groups` (
  `eventid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `eventid_2` (`eventid`,`groupid`),
  KEY `eventid` (`eventid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `communityevents_groups_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `communityevents_groups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents_images`
--

DROP TABLE IF EXISTS `communityevents_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `communityevents_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `eventid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the community events table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`eventid`),
  KEY `hash` (`hash`),
  CONSTRAINT `communityevents_images_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13020 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent` int(11) NOT NULL,
  `defers` int(11) NOT NULL,
  `avgdly` int(11) NOT NULL,
  `problem` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `problem` (`problem`)
) ENGINE=InnoDB AUTO_INCREMENT=46719 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Statistics on email domains we''ve sent to recently';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domains_common`
--

DROP TABLE IF EXISTS `domains_common`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `domains_common` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=InnoDB AUTO_INCREMENT=4378 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ebay_favourites`
--

DROP TABLE IF EXISTS `ebay_favourites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ebay_favourites` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `count` int(11) NOT NULL,
  `rival` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups`
--

DROP TABLE IF EXISTS `groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of group',
  `legacyid` bigint(20) unsigned DEFAULT NULL COMMENT '(Freegle) Groupid on old system',
  `nameshort` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A short name for the group',
  `namefull` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A longer name for the group',
  `nameabbr` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'An abbreviated name for the group',
  `namealt` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Alternative name, e.g. as used by GAT',
  `settings` longtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON-encoded settings for group',
  `type` set('Reuse','Freegle','Other','UnitTest') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other' COMMENT 'High-level characteristics of the group',
  `region` enum('East','East Midlands','West Midlands','North East','North West','Northern Ireland','South East','South West','London','Wales','Yorkshire and the Humber','Scotland') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Freegle only',
  `authorityid` bigint(20) unsigned DEFAULT NULL,
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
  `polyindex` geometry NOT NULL,
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
  `profile` bigint(20) unsigned DEFAULT NULL,
  `cover` bigint(20) unsigned DEFAULT NULL,
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
  `affiliationconfirmed` timestamp NULL DEFAULT NULL,
  `mentored` tinyint(1) NOT NULL DEFAULT '0',
  `seekingmods` tinyint(1) NOT NULL DEFAULT '0',
  `privategroup` tinyint(1) NOT NULL DEFAULT '0',
  `defaultlocation` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `nameshort` (`nameshort`),
  UNIQUE KEY `namefull` (`namefull`),
  KEY `lat` (`lat`,`lng`),
  KEY `lng` (`lng`),
  KEY `namealt` (`namealt`),
  KEY `profile` (`profile`),
  KEY `cover` (`cover`),
  KEY `legacyid` (`legacyid`),
  KEY `authorityid` (`authorityid`),
  KEY `type` (`type`),
  SPATIAL KEY `polyindex` (`polyindex`),
  KEY `mentored` (`mentored`),
  KEY `defaultlocation` (`defaultlocation`),
  CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`profile`) REFERENCES `groups_images` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_ibfk_2` FOREIGN KEY (`cover`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_ibfk_3` FOREIGN KEY (`authorityid`) REFERENCES `authorities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_ibfk_4` FOREIGN KEY (`defaultlocation`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=507942 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='The different groups that we host';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_digests`
--

DROP TABLE IF EXISTS `groups_digests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_digests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint(20) unsigned NOT NULL,
  `frequency` int(11) NOT NULL,
  `msgid` bigint(20) unsigned DEFAULT NULL COMMENT 'Which message we got upto when sending',
  `msgdate` timestamp(6) NULL DEFAULT NULL COMMENT 'Arrival of message we have sent upto',
  `started` timestamp NULL DEFAULT NULL,
  `ended` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid_2` (`groupid`,`frequency`),
  KEY `groupid` (`groupid`),
  KEY `msggrpid` (`msgid`),
  CONSTRAINT `groups_digests_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_digests_ibfk_3` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1022550111 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_facebook`
--

DROP TABLE IF EXISTS `groups_facebook`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_facebook` (
  `uid` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint(20) unsigned NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Page','Group') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Page',
  `id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text COLLATE utf8mb4_unicode_ci,
  `authdate` timestamp NULL DEFAULT NULL,
  `msgid` bigint(20) unsigned DEFAULT NULL COMMENT 'Last message posted',
  `msgarrival` timestamp NULL DEFAULT NULL COMMENT 'Time of last message posted',
  `eventid` bigint(20) unsigned DEFAULT NULL COMMENT 'Last event tweeted',
  `valid` tinyint(4) NOT NULL DEFAULT '1',
  `lasterror` text COLLATE utf8mb4_unicode_ci,
  `lasterrortime` timestamp NULL DEFAULT NULL,
  `sharefrom` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT '134117207097' COMMENT 'Facebook page to republish from',
  `lastupdated` timestamp NULL DEFAULT NULL COMMENT 'From Graph API',
  `postablecount` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`uid`),
  UNIQUE KEY `groupid_2` (`groupid`,`id`),
  KEY `msgid` (`msgid`),
  KEY `eventid` (`eventid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `groups_facebook_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4947 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_facebook_shares`
--

DROP TABLE IF EXISTS `groups_facebook_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_facebook_shares` (
  `uid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `postid` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Shared','Hidden','','') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Shared',
  UNIQUE KEY `groupid` (`uid`,`postid`),
  KEY `date` (`date`),
  KEY `postid` (`postid`),
  KEY `uid` (`uid`),
  KEY `groupid_2` (`groupid`),
  CONSTRAINT `groups_facebook_shares_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_facebook_shares_ibfk_2` FOREIGN KEY (`uid`) REFERENCES `groups_facebook` (`uid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_facebook_toshare`
--

DROP TABLE IF EXISTS `groups_facebook_toshare`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_facebook_toshare` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sharefrom` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Page to share from',
  `postid` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Facebook postid',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `postid` (`postid`),
  KEY `date` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=55749540 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores central posts for sharing out to group pages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_images`
--

DROP TABLE IF EXISTS `groups_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the groups table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`groupid`),
  KEY `hash` (`hash`),
  CONSTRAINT `groups_images_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5322 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_twitter`
--

DROP TABLE IF EXISTS `groups_twitter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `groups_twitter` (
  `groupid` bigint(20) unsigned NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `authdate` timestamp NULL DEFAULT NULL,
  `msgid` bigint(20) unsigned DEFAULT NULL COMMENT 'Last message tweeted',
  `msgarrival` timestamp NULL DEFAULT NULL,
  `eventid` bigint(20) unsigned DEFAULT NULL COMMENT 'Last event tweeted',
  `valid` tinyint(4) NOT NULL DEFAULT '1',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `lasterror` text COLLATE utf8mb4_unicode_ci,
  `lasterrortime` timestamp NULL DEFAULT NULL,
  UNIQUE KEY `groupid` (`groupid`),
  KEY `msgid` (`msgid`),
  KEY `eventid` (`eventid`),
  CONSTRAINT `groups_twitter_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_twitter_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_twitter_ibfk_3` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `items`
--

DROP TABLE IF EXISTS `items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` int(11) NOT NULL DEFAULT '0',
  `weight` decimal(10,2) DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `suggestfromphoto` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'We can exclude from image recognition',
  `suggestfromtypeahead` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'We can exclude from typeahead',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3151974 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `items_index`
--

DROP TABLE IF EXISTS `items_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `items_index` (
  `itemid` bigint(20) unsigned NOT NULL,
  `wordid` bigint(20) unsigned NOT NULL,
  `popularity` int(11) NOT NULL DEFAULT '0',
  `categoryid` bigint(20) unsigned DEFAULT NULL,
  UNIQUE KEY `itemid` (`itemid`,`wordid`),
  KEY `itemid_2` (`itemid`),
  KEY `wordid` (`wordid`),
  CONSTRAINT `items_index_ibfk_1` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `items_index_ibfk_2` FOREIGN KEY (`wordid`) REFERENCES `words` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `items_non`
--

DROP TABLE IF EXISTS `items_non`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `items_non` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` int(11) NOT NULL DEFAULT '1',
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastexample` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5522073 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Not considered items by us, but by image recognition';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `link_previews`
--

DROP TABLE IF EXISTS `link_previews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `link_previews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invalid` tinyint(1) NOT NULL DEFAULT '0',
  `spam` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB AUTO_INCREMENT=298683 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `osm_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Road','Polygon','Line','Point','Postcode') COLLATE utf8mb4_unicode_ci NOT NULL,
  `osm_place` tinyint(1) DEFAULT '0',
  `geometry` geometry DEFAULT NULL,
  `ourgeometry` geometry DEFAULT NULL COMMENT 'geometry comes from OSM; this comes from us',
  `gridid` bigint(20) unsigned DEFAULT NULL,
  `postcodeid` bigint(20) unsigned DEFAULT NULL,
  `areaid` bigint(20) unsigned DEFAULT NULL,
  `canon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `popularity` bigint(20) unsigned DEFAULT '0',
  `osm_amenity` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'For OSM locations, whether this is an amenity',
  `osm_shop` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'For OSM locations, whether this is a shop',
  `maxdimension` decimal(10,6) DEFAULT NULL COMMENT 'GetMaxDimension on geomtry',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name` (`name`),
  KEY `osm_id` (`osm_id`),
  KEY `canon` (`canon`),
  KEY `areaid` (`areaid`),
  KEY `postcodeid` (`postcodeid`),
  KEY `lat` (`lat`),
  KEY `lng` (`lng`),
  KEY `gridid` (`gridid`,`osm_place`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`gridid`) REFERENCES `locations_grids` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9545481 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Location data, the bulk derived from OSM';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_excluded`
--

DROP TABLE IF EXISTS `locations_excluded`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations_excluded` (
  `locationid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `locationid_2` (`locationid`,`groupid`),
  KEY `locationid` (`locationid`),
  KEY `groupid` (`groupid`),
  KEY `by` (`userid`),
  CONSTRAINT `_locations_excluded_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `locations_excluded_ibfk_3` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `locations_excluded_ibfk_4` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Stops locations being suggested on a group';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_grids`
--

DROP TABLE IF EXISTS `locations_grids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations_grids` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `swlat` decimal(10,6) NOT NULL,
  `swlng` decimal(10,6) NOT NULL,
  `nelat` decimal(10,6) NOT NULL,
  `nelng` decimal(10,6) NOT NULL,
  `box` geometry NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `swlat` (`swlat`,`swlng`,`nelat`,`nelng`)
) ENGINE=InnoDB AUTO_INCREMENT=841288 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Used to map lat/lng to gridid for location searches';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_grids_touches`
--

DROP TABLE IF EXISTS `locations_grids_touches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations_grids_touches` (
  `gridid` bigint(20) unsigned NOT NULL,
  `touches` bigint(20) unsigned NOT NULL,
  UNIQUE KEY `gridid` (`gridid`,`touches`),
  KEY `touches` (`touches`),
  CONSTRAINT `locations_grids_touches_ibfk_1` FOREIGN KEY (`gridid`) REFERENCES `locations_grids` (`id`) ON DELETE CASCADE,
  CONSTRAINT `locations_grids_touches_ibfk_2` FOREIGN KEY (`touches`) REFERENCES `locations_grids` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='A record of which grid squares touch others';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_spatial`
--

DROP TABLE IF EXISTS `locations_spatial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations_spatial` (
  `locationid` bigint(20) unsigned NOT NULL,
  `geometry` geometry NOT NULL,
  UNIQUE KEY `locationid` (`locationid`),
  SPATIAL KEY `geometry` (`geometry`),
  CONSTRAINT `locations_spatial_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logos`
--

DROP TABLE IF EXISTS `logos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=312 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Machine assumed set to GMT',
  `byuser` bigint(20) unsigned DEFAULT NULL COMMENT 'User responsible for action, if any',
  `type` enum('Group','Message','User','Plugin','Config','StdMsg','Location','BulkOp') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtype` enum('Created','Deleted','Received','Sent','Failure','ClassifiedSpam','Joined','Left','Approved','Rejected','YahooDeliveryType','YahooPostingStatus','NotSpam','Login','Hold','Release','Edit','RoleChange','Merged','Split','Replied','Mailed','Applied','Suspect','Licensed','LicensePurchase','YahooApplied','YahooConfirmed','YahooJoined','MailOff','EventsOff','NewslettersOff','RelevantOff','Logout','Bounce','SuspendMail','Autoreposted','Outcome','OurPostingStatus','OurPostingStatus','VolunteersOff','Autoapproved','Unbounce') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL COMMENT 'Any group this log is for',
  `user` bigint(20) unsigned DEFAULT NULL COMMENT 'Any user that this log is about',
  `msgid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the messages table',
  `configid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the mod_configs table',
  `stdmsgid` bigint(20) unsigned DEFAULT NULL COMMENT 'Any stdmsg for this log',
  `bulkopid` bigint(20) unsigned DEFAULT NULL,
  `text` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `group` (`groupid`),
  KEY `type` (`type`,`subtype`),
  KEY `timestamp` (`timestamp`),
  KEY `byuser` (`byuser`),
  KEY `user` (`user`),
  KEY `msgid` (`msgid`),
  KEY `timestamp_2` (`timestamp`,`type`,`subtype`)
) ENGINE=InnoDB AUTO_INCREMENT=270145260 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Logs.  Not guaranteed against loss';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_api`
--

DROP TABLE IF EXISTS `logs_api`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_api` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userid` bigint(20) DEFAULT NULL,
  `ip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `session` (`session`),
  KEY `date` (`date`),
  KEY `userid` (`userid`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB AUTO_INCREMENT=68381592 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC KEY_BLOCK_SIZE=8 COMMENT='Log of all API requests and responses';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_emails`
--

DROP TABLE IF EXISTS `logs_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eximid` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `from` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messageid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `timestamp_2` (`eximid`),
  KEY `timestamp` (`timestamp`),
  KEY `userid` (`userid`),
  CONSTRAINT `logs_emails_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1257681132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_errors`
--

DROP TABLE IF EXISTS `logs_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_errors` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Exception') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) DEFAULT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Errors from client';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_events`
--

DROP TABLE IF EXISTS `logs_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `sessionid` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `clienttimestamp` timestamp(3) NULL DEFAULT NULL,
  `posx` int(11) DEFAULT NULL,
  `posy` int(11) DEFAULT NULL,
  `viewx` int(11) DEFAULT NULL,
  `viewy` int(11) DEFAULT NULL,
  `data` mediumtext COLLATE utf8mb4_unicode_ci,
  `datasameas` bigint(20) unsigned DEFAULT NULL COMMENT 'Allows use to reuse data stored in table once for other rows',
  `datahash` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `route` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`,`timestamp`),
  KEY `sessionid` (`sessionid`),
  KEY `datasameas` (`datasameas`),
  KEY `datahash` (`datahash`,`datasameas`),
  KEY `ip` (`ip`),
  KEY `timestamp` (`timestamp`),
  KEY `sessionid_2` (`sessionid`,`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_profile`
--

DROP TABLE IF EXISTS `logs_profile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_profile` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caller` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `callee` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ct` bigint(20) unsigned NOT NULL DEFAULT '0',
  `wt` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cpu` bigint(20) unsigned NOT NULL,
  `mu` bigint(20) unsigned NOT NULL,
  `pmu` bigint(20) unsigned NOT NULL,
  `alloc` bigint(20) unsigned NOT NULL,
  `free` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caller` (`caller`,`callee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_sql`
--

DROP TABLE IF EXISTS `logs_sql`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_sql` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duration` decimal(15,10) unsigned DEFAULT '0.0000000000' COMMENT 'seconds',
  `userid` bigint(20) unsigned DEFAULT NULL,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'rc:lastInsertId',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `session` (`session`),
  KEY `date` (`date`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 COMMENT='Log of modification SQL operations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_src`
--

DROP TABLE IF EXISTS `logs_src`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_src` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `src` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=74129952 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Record which mails we sent generated website traffic';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships`
--

DROP TABLE IF EXISTS `memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `role` enum('Member','Moderator','Owner') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `collection` enum('Approved','Pending','Banned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `configid` bigint(20) unsigned DEFAULT NULL COMMENT 'Configuration used to moderate this group if a moderator',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settings` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'Other group settings, e.g. for moderators',
  `syncdelete` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Used during member sync',
  `heldby` bigint(20) unsigned DEFAULT NULL,
  `emailfrequency` int(11) NOT NULL DEFAULT '24' COMMENT 'In hours; -1 immediately, 0 never',
  `eventsallowed` tinyint(1) DEFAULT '1',
  `volunteeringallowed` bigint(20) NOT NULL DEFAULT '1',
  `ourPostingStatus` enum('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, NULL; for ours, the posting status',
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_groupid` (`userid`,`groupid`),
  KEY `groupid_2` (`groupid`,`role`),
  KEY `userid` (`userid`,`role`),
  KEY `role` (`role`),
  KEY `configid` (`configid`),
  KEY `groupid` (`groupid`,`collection`),
  KEY `heldby` (`heldby`),
  KEY `collection` (`collection`),
  CONSTRAINT `memberships_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_ibfk_3` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_ibfk_4` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memberships_ibfk_5` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=45952077 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Which groups users are members of';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships_history`
--

DROP TABLE IF EXISTS `memberships_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memberships_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `collection` enum('Approved','Pending','Banned') COLLATE utf8mb4_unicode_ci NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `date` (`added`),
  KEY `userid` (`userid`,`groupid`),
  CONSTRAINT `memberships_history_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_history_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35761773 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Used to spot multijoiners';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships_yahoo`
--

DROP TABLE IF EXISTS `memberships_yahoo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memberships_yahoo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `membershipid` bigint(20) unsigned NOT NULL,
  `role` enum('Member','Moderator','Owner') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `collection` enum('Approved','Pending','Banned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailid` bigint(20) unsigned NOT NULL COMMENT 'Which of their emails they use on this group',
  `yahooAlias` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `yahooPostingStatus` enum('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Yahoo mod status if applicable',
  `yahooDeliveryType` enum('DIGEST','NONE','SINGLE','ANNOUNCEMENT') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Yahoo delivery settings if applicable',
  `syncdelete` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Used during member sync',
  `yahooapprove` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, email to approve member if known and relevant',
  `yahooreject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, email to reject member if known and relevant',
  `joincomment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any joining comment for this member',
  PRIMARY KEY (`id`),
  UNIQUE KEY `membershipid_2` (`membershipid`,`emailid`),
  KEY `role` (`role`),
  KEY `emailid` (`emailid`),
  KEY `groupid` (`collection`),
  KEY `yahooPostingStatus` (`yahooPostingStatus`),
  KEY `yahooDeliveryType` (`yahooDeliveryType`),
  KEY `yahooAlias` (`yahooAlias`),
  CONSTRAINT `_memberships_yahoo_ibfk_1` FOREIGN KEY (`membershipid`) REFERENCES `memberships` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_yahoo_ibfk_1` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28126401 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Which groups users are members of';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships_yahoo_dump`
--

DROP TABLE IF EXISTS `memberships_yahoo_dump`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memberships_yahoo_dump` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint(20) unsigned NOT NULL,
  `members` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `lastupdated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastprocessed` timestamp NULL DEFAULT NULL COMMENT 'When this was last processed into the main tables',
  `synctime` timestamp NULL DEFAULT NULL COMMENT 'Time on client when sync started',
  `backgroundok` tinyint(4) NOT NULL DEFAULT '1',
  `needsprocessing` tinyint(1) GENERATED ALWAYS AS ((`lastupdated` > `lastprocessed`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid` (`groupid`),
  KEY `lastprocessed` (`lastprocessed`),
  KEY `lastupdated` (`lastupdated`,`lastprocessed`),
  KEY `needsprocessing` (`needsprocessing`),
  CONSTRAINT `memberships_yahoo_dump_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1638315 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Copy of last member sync from Yahoo';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique iD',
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this message arrived at our server',
  `date` timestamp NULL DEFAULT NULL COMMENT 'When this message was created, e.g. Date header',
  `deleted` timestamp NULL DEFAULT NULL COMMENT 'When this message was deleted',
  `heldby` bigint(20) unsigned DEFAULT NULL COMMENT 'If this message is held by a moderator',
  `source` enum('Yahoo Approved','Yahoo Pending','Yahoo System','Platform','Email') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Source of incoming message',
  `sourceheader` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any source header, e.g. X-Freegle-Source',
  `fromip` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP we think this message came from',
  `fromcountry` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'fromip geocoded to country',
  `message` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The unparsed message',
  `fromuser` bigint(20) unsigned DEFAULT NULL,
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
  `locationid` bigint(20) unsigned DEFAULT NULL,
  `editedby` bigint(20) unsigned DEFAULT NULL,
  `editedat` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message-id` (`messageid`) KEY_BLOCK_SIZE=16,
  KEY `envelopefrom` (`envelopefrom`),
  KEY `envelopeto` (`envelopeto`),
  KEY `retrylastfailure` (`retrylastfailure`),
  KEY `fromup` (`fromip`),
  KEY `tnpostid` (`tnpostid`),
  KEY `type` (`type`),
  KEY `sourceheader` (`sourceheader`),
  KEY `arrival` (`arrival`,`sourceheader`),
  KEY `arrival_2` (`arrival`,`fromaddr`),
  KEY `arrival_3` (`arrival`),
  KEY `fromaddr` (`fromaddr`,`subject`),
  KEY `date` (`date`),
  KEY `subject` (`subject`),
  KEY `fromuser` (`fromuser`),
  KEY `deleted` (`deleted`),
  KEY `heldby` (`heldby`),
  KEY `lat` (`lat`) KEY_BLOCK_SIZE=16,
  KEY `lng` (`lng`) KEY_BLOCK_SIZE=16,
  KEY `locationid` (`locationid`) KEY_BLOCK_SIZE=16,
  KEY `fromuser_2` (`fromuser`,`arrival`,`type`),
  CONSTRAINT `_messages_ibfk_1` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_messages_ibfk_2` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_messages_ibfk_3` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=55058094 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 COMMENT='All our messages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_attachments`
--

DROP TABLE IF EXISTS `messages_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the messages table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`msgid`),
  KEY `hash` (`hash`),
  CONSTRAINT `_messages_attachments_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11595768 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_attachments_items`
--

DROP TABLE IF EXISTS `messages_attachments_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_attachments_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attid` bigint(20) unsigned NOT NULL,
  `itemid` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `msgid` (`attid`),
  KEY `itemid` (`itemid`),
  CONSTRAINT `messages_attachments_items_ibfk_1` FOREIGN KEY (`attid`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_attachments_items_ibfk_2` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5186433 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_deadlines`
--

DROP TABLE IF EXISTS `messages_deadlines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_deadlines` (
  `msgid` bigint(20) unsigned NOT NULL,
  `FOP` tinyint(4) NOT NULL DEFAULT '1',
  `mustgoby` date DEFAULT NULL,
  UNIQUE KEY `msgid` (`msgid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_drafts`
--

DROP TABLE IF EXISTS `messages_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_drafts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `session` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`),
  KEY `userid` (`userid`),
  KEY `session` (`session`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `messages_drafts_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_drafts_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_drafts_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2822520 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_edits`
--

DROP TABLE IF EXISTS `messages_edits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_edits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `byuser` bigint(20) unsigned DEFAULT NULL,
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0',
  `revertedat` timestamp NULL DEFAULT NULL,
  `approvedat` timestamp NULL DEFAULT NULL,
  `oldtext` longtext COLLATE utf8mb4_unicode_ci,
  `newtext` longtext COLLATE utf8mb4_unicode_ci,
  `oldsubject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `newsubject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oldtype` enum('Offer','Taken','Wanted','Received','Admin','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `newtype` enum('Offer','Taken','Wanted','Received','Admin','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `olditems` text COLLATE utf8mb4_unicode_ci,
  `newitems` text COLLATE utf8mb4_unicode_ci,
  `oldimages` text COLLATE utf8mb4_unicode_ci,
  `newimages` text COLLATE utf8mb4_unicode_ci,
  `oldlocation` bigint(20) unsigned DEFAULT NULL,
  `newlocation` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `byuser` (`byuser`),
  KEY `timestamp` (`timestamp`,`reviewrequired`),
  CONSTRAINT `messages_edits_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_edits_ibfk_2` FOREIGN KEY (`byuser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15909 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_groups`
--

DROP TABLE IF EXISTS `messages_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_groups` (
  `msgid` bigint(20) unsigned NOT NULL COMMENT 'id in the messages table',
  `groupid` bigint(20) unsigned NOT NULL,
  `collection` enum('Incoming','Pending','Approved','Spam','QueuedYahooUser','Rejected','QueuedUser') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrival` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `autoreposts` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'How many times this message has been auto-reposted',
  `lastautopostwarning` timestamp NULL DEFAULT NULL,
  `lastchaseup` timestamp NULL DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `senttoyahoo` tinyint(1) NOT NULL DEFAULT '0',
  `yahoopendingid` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, pending id if relevant',
  `yahooapprovedid` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, approved id if relevant',
  `yahooapprove` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, email to trigger approve if relevant',
  `yahooreject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, email to trigger reject if relevant',
  `approvedby` bigint(20) unsigned DEFAULT NULL COMMENT 'Mod who approved this post (if any)',
  `approvedat` timestamp NULL DEFAULT NULL,
  `rejectedat` timestamp NULL DEFAULT NULL,
  `msgtype` enum('Offer','Taken','Wanted','Received','Admin','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'In here for performance optimisation',
  UNIQUE KEY `msgid` (`msgid`,`groupid`),
  UNIQUE KEY `groupid_3` (`groupid`,`yahooapprovedid`),
  UNIQUE KEY `groupid_2` (`groupid`,`yahoopendingid`),
  KEY `messageid` (`msgid`,`groupid`,`collection`,`arrival`),
  KEY `collection` (`collection`),
  KEY `approvedby` (`approvedby`),
  KEY `groupid` (`groupid`,`collection`,`deleted`,`arrival`),
  KEY `deleted` (`deleted`),
  KEY `arrival` (`arrival`,`groupid`,`msgtype`) USING BTREE,
  CONSTRAINT `_messages_groups_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_messages_groups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_messages_groups_ibfk_3` FOREIGN KEY (`approvedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='The state of the message on each group';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_history`
--

DROP TABLE IF EXISTS `messages_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique iD',
  `msgid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the messages table',
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this message arrived at our server',
  `source` enum('Yahoo Approved','Yahoo Pending','Yahoo System','Platform') CHARACTER SET latin1 DEFAULT NULL COMMENT 'Source of incoming message',
  `fromip` varchar(40) CHARACTER SET latin1 DEFAULT NULL COMMENT 'IP we think this message came from',
  `fromhost` varchar(80) CHARACTER SET latin1 DEFAULT NULL COMMENT 'Hostname for fromip if resolvable, or NULL',
  `fromuser` bigint(20) unsigned DEFAULT NULL,
  `envelopefrom` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `fromname` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `fromaddr` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `envelopeto` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL COMMENT 'Destination group, if identified',
  `subject` varchar(1024) CHARACTER SET latin1 DEFAULT NULL,
  `prunedsubject` varchar(1024) CHARACTER SET latin1 DEFAULT NULL COMMENT 'For spam detection',
  `messageid` varchar(255) CHARACTER SET latin1 DEFAULT NULL,
  `repost` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `msgid` (`msgid`,`groupid`),
  KEY `fromaddr` (`fromaddr`),
  KEY `envelopefrom` (`envelopefrom`),
  KEY `envelopeto` (`envelopeto`),
  KEY `message-id` (`messageid`),
  KEY `groupid` (`groupid`),
  KEY `fromup` (`fromip`),
  KEY `incomingid` (`msgid`),
  KEY `fromhost` (`fromhost`),
  KEY `arrival` (`arrival`),
  KEY `subject` (`subject`(767)),
  KEY `prunedsubject` (`prunedsubject`(767)),
  KEY `fromname` (`fromname`),
  KEY `fromuser` (`fromuser`),
  CONSTRAINT `_messages_history_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_messages_history_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9754404 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Message arrivals, used for spam checking';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_index`
--

DROP TABLE IF EXISTS `messages_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_index` (
  `msgid` bigint(20) unsigned NOT NULL,
  `wordid` bigint(20) unsigned NOT NULL,
  `arrival` bigint(20) NOT NULL COMMENT 'We prioritise recent messages',
  `groupid` bigint(20) unsigned DEFAULT NULL,
  UNIQUE KEY `msgid` (`msgid`,`wordid`),
  KEY `arrival` (`arrival`),
  KEY `groupid` (`groupid`),
  KEY `wordid` (`wordid`,`groupid`),
  CONSTRAINT `_messages_index_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_messages_index_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_index_ibfk_1` FOREIGN KEY (`wordid`) REFERENCES `words` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='For indexing messages for search keywords';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_items`
--

DROP TABLE IF EXISTS `messages_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_items` (
  `msgid` bigint(20) unsigned NOT NULL,
  `itemid` bigint(20) unsigned NOT NULL,
  UNIQUE KEY `msgid` (`msgid`,`itemid`),
  KEY `itemid` (`itemid`),
  CONSTRAINT `messages_items_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_items_ibfk_2` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Where known, items for our message';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_likes`
--

DROP TABLE IF EXISTS `messages_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_likes` (
  `msgid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  `type` enum('Love','Laugh') COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `msgid_2` (`msgid`,`userid`,`type`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`,`type`),
  CONSTRAINT `messages_likes_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_outcomes`
--

DROP TABLE IF EXISTS `messages_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_outcomes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint(20) unsigned NOT NULL,
  `outcome` enum('Taken','Received','Withdrawn','Repost') COLLATE utf8mb4_unicode_ci NOT NULL,
  `happiness` enum('Happy','Fine','Unhappy') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `comments` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  KEY `timestamp_2` (`timestamp`,`outcome`),
  CONSTRAINT `messages_outcomes_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_outcomes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2200980 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_outcomes_intended`
--

DROP TABLE IF EXISTS `messages_outcomes_intended`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_outcomes_intended` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint(20) unsigned NOT NULL,
  `outcome` enum('Taken','Received','Withdrawn','Repost') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid_2` (`msgid`,`outcome`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  KEY `timestamp_2` (`timestamp`,`outcome`),
  KEY `msgid_3` (`msgid`),
  CONSTRAINT `messages_outcomes_intended_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=536802 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='When someone starts telling us an outcome but doesn''t finish';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_postings`
--

DROP TABLE IF EXISTS `messages_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_postings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `repost` tinyint(1) NOT NULL DEFAULT '0',
  `autorepost` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `messages_postings_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_postings_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3679548 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_promises`
--

DROP TABLE IF EXISTS `messages_promises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_promises` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  `promisedat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`),
  KEY `userid` (`userid`),
  KEY `promisedat` (`promisedat`),
  CONSTRAINT `messages_promises_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_promises_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=268917 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_related`
--

DROP TABLE IF EXISTS `messages_related`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_related` (
  `id1` bigint(20) unsigned NOT NULL,
  `id2` bigint(20) unsigned NOT NULL,
  UNIQUE KEY `id1_2` (`id1`,`id2`),
  KEY `id1` (`id1`),
  KEY `id2` (`id2`),
  CONSTRAINT `messages_related_ibfk_1` FOREIGN KEY (`id1`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_related_ibfk_2` FOREIGN KEY (`id2`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Messages which are related to each other';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_reneged`
--

DROP TABLE IF EXISTS `messages_reneged`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_reneged` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `messages_reneged_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_reneged_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29346 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_spamham`
--

DROP TABLE IF EXISTS `messages_spamham`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages_spamham` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `spamham` enum('Spam','Ham') COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`),
  CONSTRAINT `messages_spamham_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=66048 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='User feedback on messages ';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_bulkops`
--

DROP TABLE IF EXISTS `mod_bulkops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mod_bulkops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `configid` bigint(20) unsigned DEFAULT NULL,
  `set` enum('Members') COLLATE utf8mb4_unicode_ci NOT NULL,
  `criterion` enum('Bouncing','BouncingFor','WebOnly','All') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `runevery` int(11) NOT NULL DEFAULT '168' COMMENT 'In hours',
  `action` enum('Unbounce','Remove','ToGroup','ToSpecialNotices') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bouncingfor` int(11) NOT NULL DEFAULT '90',
  UNIQUE KEY `uniqueid` (`id`),
  KEY `configid` (`configid`),
  CONSTRAINT `mod_bulkops_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32073 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_bulkops_run`
--

DROP TABLE IF EXISTS `mod_bulkops_run`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mod_bulkops_run` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bulkopid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `runstarted` timestamp NULL DEFAULT NULL,
  `runfinished` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bulkopid_2` (`bulkopid`,`groupid`),
  KEY `bulkopid` (`bulkopid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `mod_bulkops_run_ibfk_1` FOREIGN KEY (`bulkopid`) REFERENCES `mod_bulkops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mod_bulkops_run_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5084266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_configs`
--

DROP TABLE IF EXISTS `mod_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mod_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of config',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name of config set',
  `createdby` bigint(20) unsigned DEFAULT NULL COMMENT 'Moderator ID who created it',
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
  `default` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Default configs are always visible',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `uniqueid` (`id`,`createdby`),
  KEY `createdby` (`createdby`),
  KEY `default` (`default`),
  CONSTRAINT `mod_configs_ibfk_1` FOREIGN KEY (`createdby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=70890 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Configurations for use by moderators';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_stdmsgs`
--

DROP TABLE IF EXISTS `mod_stdmsgs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mod_stdmsgs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of standard message',
  `configid` bigint(20) unsigned DEFAULT NULL,
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
  `insert` enum('Top','Bottom') COLLATE utf8mb4_unicode_ci DEFAULT 'Top',
  UNIQUE KEY `id` (`id`),
  KEY `configid` (`configid`),
  CONSTRAINT `mod_stdmsgs_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=203469 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modnotifs`
--

DROP TABLE IF EXISTS `modnotifs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modnotifs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `modnotifs_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=323538 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed`
--

DROP TABLE IF EXISTS `newsfeed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsfeed` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Message','CommunityEvent','VolunteerOpportunity','CentralPublicity','Alert','Story','ReferToWanted','ReferToOffer','ReferToTaken','ReferToReceived','AboutMe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Message',
  `userid` bigint(20) unsigned DEFAULT NULL,
  `imageid` bigint(20) unsigned DEFAULT NULL,
  `msgid` bigint(20) unsigned DEFAULT NULL,
  `replyto` bigint(20) unsigned DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `eventid` bigint(20) unsigned DEFAULT NULL,
  `volunteeringid` bigint(20) unsigned DEFAULT NULL,
  `publicityid` bigint(20) unsigned DEFAULT NULL,
  `storyid` bigint(20) unsigned DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `position` point NOT NULL,
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0',
  `deleted` timestamp NULL DEFAULT NULL,
  `deletedby` bigint(20) unsigned DEFAULT NULL,
  `hidden` timestamp NULL DEFAULT NULL,
  `hiddenby` bigint(20) unsigned DEFAULT NULL,
  `closed` tinyint(1) NOT NULL DEFAULT '0',
  `html` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `eventid` (`eventid`),
  KEY `userid` (`userid`),
  KEY `imageid` (`imageid`),
  KEY `msgid` (`msgid`),
  KEY `replyto` (`replyto`),
  SPATIAL KEY `position` (`position`),
  KEY `groupid` (`groupid`),
  KEY `volunteeringid` (`volunteeringid`),
  KEY `publicityid` (`publicityid`),
  KEY `timestamp` (`timestamp`),
  KEY `storyid` (`storyid`),
  CONSTRAINT `newsfeed_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_4` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_5` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_6` FOREIGN KEY (`publicityid`) REFERENCES `groups_facebook_toshare` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_7` FOREIGN KEY (`storyid`) REFERENCES `users_stories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=83097 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_images`
--

DROP TABLE IF EXISTS `newsfeed_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsfeed_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `newsfeedid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the community events table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`newsfeedid`),
  KEY `hash` (`hash`)
) ENGINE=InnoDB AUTO_INCREMENT=4554 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_likes`
--

DROP TABLE IF EXISTS `newsfeed_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsfeed_likes` (
  `newsfeedid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `newsfeedid_2` (`newsfeedid`,`userid`),
  KEY `newsfeedid` (`newsfeedid`),
  KEY `userid` (`userid`),
  CONSTRAINT `newsfeed_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_likes_ibfk_3` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_reports`
--

DROP TABLE IF EXISTS `newsfeed_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsfeed_reports` (
  `newsfeedid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reason` text COLLATE utf8mb4_unicode_ci,
  UNIQUE KEY `newsfeedid_2` (`newsfeedid`,`userid`),
  KEY `newsfeedid` (`newsfeedid`),
  KEY `userid` (`userid`),
  CONSTRAINT `newsfeed_reports_ibfk_1` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_reports_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_unfollow`
--

DROP TABLE IF EXISTS `newsfeed_unfollow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsfeed_unfollow` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `newsfeedid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`newsfeedid`),
  KEY `userid` (`userid`),
  KEY `newsfeedid` (`newsfeedid`),
  CONSTRAINT `newsfeed_unfollow_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_unfollow_ibfk_2` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3358 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_users`
--

DROP TABLE IF EXISTS `newsfeed_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsfeed_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `newsfeedid` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  KEY `newsfeedid` (`newsfeedid`),
  CONSTRAINT `newsfeed_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=111214275 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters`
--

DROP TABLE IF EXISTS `newsletters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `subject` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `textbody` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'For people who don''t read HTML',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `uptouser` bigint(20) unsigned DEFAULT NULL COMMENT 'User id we are upto, roughly',
  `type` enum('General','Stories','','') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `newsletters_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=718 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters_articles`
--

DROP TABLE IF EXISTS `newsletters_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletters_articles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `newsletterid` bigint(20) unsigned NOT NULL,
  `type` enum('Header','Article') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Article',
  `position` int(11) NOT NULL,
  `html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `photoid` bigint(20) unsigned DEFAULT NULL,
  `width` int(11) NOT NULL DEFAULT '250',
  PRIMARY KEY (`id`),
  KEY `mailid` (`newsletterid`),
  KEY `photo` (`photoid`),
  CONSTRAINT `newsletters_articles_ibfk_1` FOREIGN KEY (`newsletterid`) REFERENCES `newsletters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsletters_articles_ibfk_2` FOREIGN KEY (`photoid`) REFERENCES `newsletters_images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2731 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters_images`
--

DROP TABLE IF EXISTS `newsletters_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletters_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `articleid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the groups table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`articleid`),
  KEY `hash` (`hash`),
  CONSTRAINT `newsletters_images_ibfk_1` FOREIGN KEY (`articleid`) REFERENCES `newsletters_articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `os_county_electoral_division_region`
--

DROP TABLE IF EXISTS `os_county_electoral_division_region`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `os_county_electoral_division_region` (
  `OGR_FID` int(11) NOT NULL AUTO_INCREMENT,
  `SHAPE` geometry NOT NULL,
  UNIQUE KEY `OGR_FID` (`OGR_FID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_addresses`
--

DROP TABLE IF EXISTS `paf_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `postcodeid` bigint(20) unsigned DEFAULT NULL,
  `posttownid` bigint(20) unsigned DEFAULT NULL,
  `dependentlocalityid` bigint(20) unsigned DEFAULT NULL,
  `doubledependentlocalityid` bigint(20) unsigned DEFAULT NULL,
  `thoroughfaredescriptorid` bigint(20) unsigned DEFAULT NULL,
  `dependentthoroughfaredescriptorid` bigint(20) unsigned DEFAULT NULL,
  `buildingnumber` int(11) DEFAULT NULL,
  `buildingnameid` bigint(20) unsigned DEFAULT NULL,
  `subbuildingnameid` bigint(20) unsigned DEFAULT NULL,
  `poboxid` bigint(20) unsigned DEFAULT NULL,
  `departmentnameid` bigint(20) unsigned DEFAULT NULL,
  `organisationnameid` bigint(20) unsigned DEFAULT NULL,
  `udprn` bigint(20) unsigned DEFAULT NULL,
  `postcodetype` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suorganisationindicator` char(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deliverypointsuffix` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `udprn` (`udprn`),
  KEY `postcodeid` (`postcodeid`),
  CONSTRAINT `paf_addresses_ibfk_11` FOREIGN KEY (`postcodeid`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=126404096 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_buildingname`
--

DROP TABLE IF EXISTS `paf_buildingname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_buildingname` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `buildingname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buildingname` (`buildingname`)
) ENGINE=InnoDB AUTO_INCREMENT=7910 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_departmentname`
--

DROP TABLE IF EXISTS `paf_departmentname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_departmentname` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `departmentname` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departmentname` (`departmentname`)
) ENGINE=InnoDB AUTO_INCREMENT=67028 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_dependentlocality`
--

DROP TABLE IF EXISTS `paf_dependentlocality`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_dependentlocality` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dependentlocality` varchar(35) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dependentlocality` (`dependentlocality`)
) ENGINE=InnoDB AUTO_INCREMENT=68999 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_dependentthoroughfaredescriptor`
--

DROP TABLE IF EXISTS `paf_dependentthoroughfaredescriptor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_dependentthoroughfaredescriptor` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dependentthoroughfaredescriptor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dependentthoroughfaredescriptor` (`dependentthoroughfaredescriptor`)
) ENGINE=InnoDB AUTO_INCREMENT=74873 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_doubledependentlocality`
--

DROP TABLE IF EXISTS `paf_doubledependentlocality`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_doubledependentlocality` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doubledependentlocality` varchar(35) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doubledependentlocality` (`doubledependentlocality`)
) ENGINE=InnoDB AUTO_INCREMENT=11855 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_organisationname`
--

DROP TABLE IF EXISTS `paf_organisationname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_organisationname` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organisationname` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organisationname` (`organisationname`)
) ENGINE=InnoDB AUTO_INCREMENT=48659 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_pobox`
--

DROP TABLE IF EXISTS `paf_pobox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_pobox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pobox` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pobox` (`pobox`)
) ENGINE=InnoDB AUTO_INCREMENT=424232 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_posttown`
--

DROP TABLE IF EXISTS `paf_posttown`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_posttown` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `posttown` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `posttown` (`posttown`)
) ENGINE=InnoDB AUTO_INCREMENT=4414 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_subbuildingname`
--

DROP TABLE IF EXISTS `paf_subbuildingname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_subbuildingname` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subbuildingname` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subbuildingname` (`subbuildingname`)
) ENGINE=InnoDB AUTO_INCREMENT=4238729 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_thoroughfaredescriptor`
--

DROP TABLE IF EXISTS `paf_thoroughfaredescriptor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paf_thoroughfaredescriptor` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `thoroughfaredescriptor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `thoroughfaredescriptor` (`thoroughfaredescriptor`)
) ENGINE=InnoDB AUTO_INCREMENT=1096613 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `partners_keys`
--

DROP TABLE IF EXISTS `partners_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `partners_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `partner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='For site-to-site integration';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `plugin`
--

DROP TABLE IF EXISTS `plugin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plugin` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `groupid` bigint(20) unsigned NOT NULL,
  `data` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `plugin_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5730354 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Outstanding work required to be performed by the plugin';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `polls`
--

DROP TABLE IF EXISTS `polls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `polls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `template` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `logintype` enum('Facebook','Google','Yahoo','Native') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `polls_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=177 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `polls_users`
--

DROP TABLE IF EXISTS `polls_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `polls_users` (
  `pollid` bigint(20) unsigned NOT NULL,
  `userid` bigint(10) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `shown` tinyint(4) DEFAULT '1',
  `response` text COLLATE utf8mb4_unicode_ci,
  UNIQUE KEY `pollid` (`pollid`,`userid`),
  KEY `pollid_2` (`pollid`),
  KEY `userid` (`userid`),
  CONSTRAINT `polls_users_ibfk_1` FOREIGN KEY (`pollid`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `polls_users_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `predictions`
--

DROP TABLE IF EXISTS `predictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `predictions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `prediction` enum('Up','Down','Disabled','Unknown') COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `probs` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rater_2` (`userid`),
  CONSTRAINT `predictions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=235221 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prerender`
--

DROP TABLE IF EXISTS `prerender`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prerender` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci,
  `head` longtext COLLATE utf8mb4_unicode_ci,
  `retrieved` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `timeout` int(11) NOT NULL DEFAULT '60' COMMENT 'In minutes',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB AUTO_INCREMENT=28032195 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED COMMENT='Saved copies of HTML for logged out view of pages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ratings`
--

DROP TABLE IF EXISTS `ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ratings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rater` bigint(20) unsigned NOT NULL,
  `ratee` bigint(10) unsigned NOT NULL,
  `rating` enum('Up','Down') COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rater_2` (`rater`,`ratee`),
  KEY `rater` (`rater`),
  KEY `ratee` (`ratee`),
  CONSTRAINT `ratings_ibfk_1` FOREIGN KEY (`rater`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ratings_ibfk_2` FOREIGN KEY (`ratee`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=136728 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `search_history`
--

DROP TABLE IF EXISTS `search_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `search_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `term` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `locationid` bigint(20) unsigned DEFAULT NULL,
  `groups` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `locationid` (`locationid`),
  CONSTRAINT `search_history_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49883967 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `series` bigint(20) unsigned NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastactive` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `id_3` (`id`,`series`,`token`),
  KEY `date` (`date`),
  KEY `userid` (`userid`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11006790 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shortlinks`
--

DROP TABLE IF EXISTS `shortlinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shortlinks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Group','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clicks` bigint(20) NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `name` (`name`),
  CONSTRAINT `shortlinks_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23661 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_countries`
--

DROP TABLE IF EXISTS `spam_countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spam_countries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'A country we want to block',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `country` (`country`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_keywords`
--

DROP TABLE IF EXISTS `spam_keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spam_keywords` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exclude` text COLLATE utf8mb4_unicode_ci,
  `action` enum('Review','Spam') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Review',
  `type` enum('Literal','Regex') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Literal',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=516 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Keywords often used by spammers';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_users`
--

DROP TABLE IF EXISTS `spam_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spam_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `byuserid` bigint(20) unsigned DEFAULT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `collection` enum('Spammer','Whitelisted','PendingAdd','PendingRemove') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Spammer',
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  KEY `byuserid` (`byuserid`),
  KEY `added` (`added`),
  KEY `collection` (`collection`),
  CONSTRAINT `spam_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `spam_users_ibfk_2` FOREIGN KEY (`byuserid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24234 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Users who are spammers or trusted';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_whitelist_ips`
--

DROP TABLE IF EXISTS `spam_whitelist_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spam_whitelist_ips` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB AUTO_INCREMENT=3043 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Whitelisted IP addresses';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_whitelist_links`
--

DROP TABLE IF EXISTS `spam_whitelist_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spam_whitelist_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `domain` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `count` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `userid` (`userid`),
  CONSTRAINT `spam_whitelist_links_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=313467 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted domains for URLs';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_whitelist_subjects`
--

DROP TABLE IF EXISTS `spam_whitelist_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spam_whitelist_subjects` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `ip` (`subject`)
) ENGINE=InnoDB AUTO_INCREMENT=15049 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Whitelisted subjects';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spatial_ref_sys`
--

DROP TABLE IF EXISTS `spatial_ref_sys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `spatial_ref_sys` (
  `SRID` int(11) NOT NULL,
  `AUTH_NAME` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `AUTH_SRID` int(11) DEFAULT NULL,
  `SRTEXT` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stats`
--

DROP TABLE IF EXISTS `stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `type` enum('ApprovedMessageCount','SpamMessageCount','MessageBreakdown','SpamMemberCount','PostMethodBreakdown','YahooDeliveryBreakdown','YahooPostingBreakdown','ApprovedMemberCount','SupportQueries','Happy','Fine','Unhappy','Searches','Activity','Weight','Outcomes') COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` bigint(20) unsigned DEFAULT NULL,
  `breakdown` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`,`type`,`groupid`),
  KEY `groupid` (`groupid`),
  KEY `type` (`type`,`date`,`groupid`),
  CONSTRAINT `_stats_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51759636 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Stats information used for dashboard';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stats_outcomes`
--

DROP TABLE IF EXISTS `stats_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stats_outcomes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint(20) unsigned NOT NULL,
  `count` int(11) NOT NULL,
  `date` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid_2` (`groupid`,`date`),
  KEY `groupid` (`groupid`) USING BTREE,
  CONSTRAINT `stats_outcomes_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2661204 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For efficient stats calculations, refreshed via cron';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supporters`
--

DROP TABLE IF EXISTS `supporters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supporters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Wowzer','Front Page','Supporter','Buyer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voucher` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Voucher code',
  `vouchercount` int(11) NOT NULL DEFAULT '1' COMMENT 'Number of licenses in this voucher',
  `voucheryears` int(11) NOT NULL DEFAULT '1' COMMENT 'Number of years voucher licenses are valid for',
  `anonymous` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `id` (`id`),
  KEY `name` (`name`,`type`,`email`),
  KEY `display` (`display`)
) ENGINE=InnoDB AUTO_INCREMENT=133569 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='People who have supported this site';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` enum('Team','Role') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Team',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Users who have particular roles in the organisation';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teams_members`
--

DROP TABLE IF EXISTS `teams_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teams_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `teamid` bigint(20) unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text COLLATE utf8mb4_unicode_ci,
  `nameoverride` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imageoverride` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`teamid`),
  KEY `userid` (`userid`),
  KEY `teamid` (`teamid`),
  CONSTRAINT `teams_members_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teams_members_ibfk_2` FOREIGN KEY (`teamid`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=795 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `yahooUserId` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unique ID of user on Yahoo if known',
  `firstname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fullname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `systemrole` set('User','Moderator','Support','Admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User' COMMENT 'System-wide roles',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastaccess` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settings` mediumtext COLLATE utf8mb4_unicode_ci COMMENT 'JSON-encoded settings',
  `gotrealemail` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Until migrated, whether polled FD/TN to get real email',
  `suspectcount` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'Number of reports of this user as suspicious',
  `suspectreason` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last reason for suspecting this user',
  `yahooid` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any known YahooID for this user',
  `licenses` int(11) NOT NULL DEFAULT '0' COMMENT 'Any licenses not added to groups',
  `newslettersallowed` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Central mails',
  `relevantallowed` tinyint(4) NOT NULL DEFAULT '1',
  `onholidaytill` date DEFAULT NULL,
  `ripaconsent` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether we have consent for humans to vet their messages',
  `publishconsent` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'Can we republish posts to non-members?',
  `lastlocation` bigint(20) unsigned DEFAULT NULL,
  `lastrelevantcheck` timestamp NULL DEFAULT NULL,
  `lastidlechaseup` timestamp NULL DEFAULT NULL,
  `bouncing` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether preferred email has been determined to be bouncing',
  `permissions` set('BusinessCardsAdmin','Newsletter','NationalVolunteers','Teams') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitesleft` int(10) unsigned DEFAULT '10',
  `source` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chatmodstatus` enum('Moderated','Unmoderated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Moderated',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `yahooUserId` (`yahooUserId`),
  UNIQUE KEY `yahooid` (`yahooid`),
  KEY `systemrole` (`systemrole`),
  KEY `added` (`added`,`lastaccess`),
  KEY `fullname` (`fullname`),
  KEY `firstname` (`firstname`),
  KEY `lastname` (`lastname`),
  KEY `firstname_2` (`firstname`,`lastname`),
  KEY `gotrealemail` (`gotrealemail`),
  KEY `suspectcount` (`suspectcount`),
  KEY `suspectcount_2` (`suspectcount`),
  KEY `lastlocation` (`lastlocation`),
  KEY `lastrelevantcheck` (`lastrelevantcheck`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`lastlocation`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=37233072 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_aboutme`
--

DROP TABLE IF EXISTS `users_aboutme`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_aboutme` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `text` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `userid_2` (`userid`,`timestamp`),
  CONSTRAINT `users_aboutme_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28779 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_active`
--

DROP TABLE IF EXISTS `users_active`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_active` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`timestamp`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `users_active_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11023203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track when users are active hourly';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_addresses`
--

DROP TABLE IF EXISTS `users_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `pafid` bigint(20) unsigned DEFAULT NULL,
  `to` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instructions` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`pafid`),
  KEY `userid` (`userid`),
  KEY `pafid` (`pafid`),
  CONSTRAINT `users_addresses_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_addresses_ibfk_3` FOREIGN KEY (`pafid`) REFERENCES `paf_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31755 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_banned`
--

DROP TABLE IF EXISTS `users_banned`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_banned` (
  `userid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `byuser` bigint(20) unsigned DEFAULT NULL,
  UNIQUE KEY `userid_2` (`userid`,`groupid`),
  KEY `groupid` (`groupid`),
  KEY `userid` (`userid`),
  KEY `date` (`date`),
  KEY `byuser` (`byuser`),
  CONSTRAINT `users_banned_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_banned_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_banned_ibfk_3` FOREIGN KEY (`byuser`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_chatlists`
--

DROP TABLE IF EXISTS `users_chatlists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_chatlists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chatlist` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `background` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`key`),
  KEY `userid` (`userid`) USING BTREE,
  CONSTRAINT `users_chatlists_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59842304 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache of lists of chats for performance';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_chatlists_index`
--

DROP TABLE IF EXISTS `users_chatlists_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_chatlists_index` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint(20) unsigned NOT NULL,
  `chatlistid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatid_3` (`chatid`,`chatlistid`,`userid`),
  KEY `chatid` (`chatlistid`),
  KEY `userid` (`userid`),
  KEY `chatid_2` (`chatid`),
  CONSTRAINT `users_chatlists_index_ibfk_1` FOREIGN KEY (`chatlistid`) REFERENCES `users_chatlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_chatlists_index_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_chatlists_index_ibfk_3` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=536489400 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_comments`
--

DROP TABLE IF EXISTS `users_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `byuserid` bigint(20) unsigned DEFAULT NULL,
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
  `user11` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `modid` (`byuserid`),
  KEY `userid` (`userid`,`groupid`),
  CONSTRAINT `users_comments_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_comments_ibfk_2` FOREIGN KEY (`byuserid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_comments_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=168144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Comments from mods on members';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_dashboard`
--

DROP TABLE IF EXISTS `users_dashboard`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_dashboard` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('Reuse','Freegle','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `systemwide` tinyint(1) DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL,
  `start` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `groupid` (`groupid`),
  KEY `userid` (`userid`),
  KEY `systemwide` (`systemwide`),
  CONSTRAINT `users_dashboard_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_dashboard_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3832437 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached copy of mod dashboard, gen in cron';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_donations`
--

DROP TABLE IF EXISTS `users_donations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_donations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('PayPal','External') COLLATE utf8mb4_unicode_ci NOT NULL,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `Payer` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `PayerDisplayName` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `TransactionID` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `GrossAmount` decimal(10,2) NOT NULL,
  `source` enum('DonateWithPayPal','PayPalGivingFund') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DonateWithPayPal',
  PRIMARY KEY (`id`),
  UNIQUE KEY `TransactionID` (`TransactionID`),
  KEY `userid` (`userid`),
  KEY `GrossAmount` (`GrossAmount`),
  KEY `timestamp` (`timestamp`,`GrossAmount`),
  KEY `timestamp_2` (`timestamp`,`userid`,`GrossAmount`),
  CONSTRAINT `users_donations_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2753139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_donations_asks`
--

DROP TABLE IF EXISTS `users_donations_asks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_donations_asks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  CONSTRAINT `users_donations_asks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=128370 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_emails`
--

DROP TABLE IF EXISTS `users_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL COMMENT 'Unique ID in users table',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The email',
  `preferred` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'Preferred email for this user',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `validatekey` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `validated` timestamp NULL DEFAULT NULL,
  `canon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For spotting duplicates',
  `backwards` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Allows domain search',
  `bounced` timestamp NULL DEFAULT NULL,
  `viewed` timestamp NULL DEFAULT NULL,
  `md5hash` varchar(32) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (md5(lower(`email`))) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `validatekey` (`validatekey`),
  KEY `userid` (`userid`),
  KEY `validated` (`validated`),
  KEY `canon` (`canon`),
  KEY `backwards` (`backwards`),
  KEY `bounced` (`bounced`),
  KEY `viewed` (`viewed`),
  KEY `md5hash` (`md5hash`),
  CONSTRAINT `users_emails_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=122229102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_emails_verify`
--

DROP TABLE IF EXISTS `users_emails_verify`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_emails_verify` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `emailid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `result` text COLLATE utf8mb4_unicode_ci,
  `status` enum('valid','invalid','unknown','accept_all') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emailid` (`emailid`),
  KEY `status` (`status`),
  CONSTRAINT `users_emails_verify_ibfk_1` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45269 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Email verification via BriteVerify or similar';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_emails_verify_domains`
--

DROP TABLE IF EXISTS `users_emails_verify_domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_emails_verify_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB AUTO_INCREMENT=959 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Domains which can''t be verified ';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_exports`
--

DROP TABLE IF EXISTS `users_exports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_exports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `requested` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started` timestamp NULL DEFAULT NULL,
  `completed` timestamp NULL DEFAULT NULL,
  `tag` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` longblob,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `completed` (`completed`),
  CONSTRAINT `users_exports_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1629 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_images`
--

DROP TABLE IF EXISTS `users_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the users table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`userid`),
  KEY `hash` (`hash`),
  CONSTRAINT `users_images_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3399249 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_invitations`
--

DROP TABLE IF EXISTS `users_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `outcome` enum('Pending','Accepted','Declined','') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `outcometimestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`email`),
  KEY `userid` (`userid`),
  KEY `email` (`email`),
  CONSTRAINT `users_invitations_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17391 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_kudos`
--

DROP TABLE IF EXISTS `users_kudos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_kudos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kudos` int(11) NOT NULL DEFAULT '0',
  `userid` bigint(20) unsigned NOT NULL,
  `posts` int(11) NOT NULL DEFAULT '0',
  `chats` int(11) NOT NULL DEFAULT '0',
  `newsfeed` int(11) NOT NULL DEFAULT '0',
  `events` int(11) NOT NULL DEFAULT '0',
  `vols` int(11) NOT NULL DEFAULT '0',
  `facebook` tinyint(1) NOT NULL DEFAULT '0',
  `platform` tinyint(1) NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `users_kudos_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39789 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_logins`
--

DROP TABLE IF EXISTS `users_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_logins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL COMMENT 'Unique ID in users table',
  `type` enum('Yahoo','Facebook','Google','Native','Link') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unique identifier for login',
  `credentials` text COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastaccess` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `credentials2` text COLLATE utf8mb4_unicode_ci COMMENT 'For Link logins',
  `credentialsrotated` timestamp NULL DEFAULT NULL COMMENT 'For Link logins',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`uid`,`type`),
  UNIQUE KEY `userid_3` (`userid`,`type`,`uid`),
  KEY `userid` (`userid`),
  KEY `validated` (`lastaccess`),
  CONSTRAINT `users_logins_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7905780 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_modmails`
--

DROP TABLE IF EXISTS `users_modmails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_modmails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `logid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `groupid` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `logid` (`logid`),
  KEY `userid_2` (`userid`,`groupid`)
) ENGINE=InnoDB AUTO_INCREMENT=1240014 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_nearby`
--

DROP TABLE IF EXISTS `users_nearby`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_nearby` (
  `userid` bigint(20) unsigned NOT NULL,
  `msgid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `userid_2` (`userid`,`msgid`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `users_nearby_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_nearby_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_notifications`
--

DROP TABLE IF EXISTS `users_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fromuser` bigint(20) unsigned DEFAULT NULL,
  `touser` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('CommentOnYourPost','CommentOnCommented','LovedPost','LovedComment','TryFeed','MembershipPending','MembershipApproved','MembershipRejected','AboutMe','Exhort') COLLATE utf8mb4_unicode_ci NOT NULL,
  `newsfeedid` bigint(20) unsigned DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seen` tinyint(1) NOT NULL DEFAULT '0',
  `title` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `newsfeedid` (`newsfeedid`),
  KEY `touser` (`touser`),
  KEY `fromuser` (`fromuser`),
  KEY `userid` (`touser`,`id`,`seen`),
  KEY `touser_2` (`timestamp`,`seen`),
  CONSTRAINT `users_notifications_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_notifications_ibfk_2` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_notifications_ibfk_3` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2842884 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_nudges`
--

DROP TABLE IF EXISTS `users_nudges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_nudges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fromuser` bigint(20) unsigned NOT NULL,
  `touser` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fromuser` (`fromuser`),
  KEY `touser` (`touser`),
  CONSTRAINT `users_nudges_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_nudges_ibfk_2` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=134181 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_phones`
--

DROP TABLE IF EXISTS `users_phones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_phones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid` tinyint(1) NOT NULL DEFAULT '1',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastsent` timestamp NULL DEFAULT NULL,
  `lastresponse` text COLLATE utf8mb4_unicode_ci,
  `laststatus` enum('queued','failed','sent','delivered','undelivered') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `laststatusreceived` timestamp NULL DEFAULT NULL,
  `count` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`) USING BTREE,
  KEY `number` (`number`),
  KEY `laststatus` (`laststatus`,`valid`) USING BTREE,
  CONSTRAINT `users_phones_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=50169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_push_notifications`
--

DROP TABLE IF EXISTS `users_push_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_push_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Google','Firefox','Test','Android','IOS','FCMAndroid','FCMIOS') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Google',
  `lastsent` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subscription` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apptype` enum('User','ModTools') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User',
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription` (`subscription`),
  KEY `userid` (`userid`,`type`),
  KEY `type` (`type`),
  CONSTRAINT `users_push_notifications_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14980209 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='For sending push notifications to users';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_replytime`
--

DROP TABLE IF EXISTS `users_replytime`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_replytime` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `replytime` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`) USING BTREE,
  CONSTRAINT `users_replytime_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4366935 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_requests`
--

DROP TABLE IF EXISTS `users_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `type` enum('BusinessCards') COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `completedby` bigint(20) unsigned DEFAULT NULL,
  `addressid` bigint(20) unsigned DEFAULT NULL,
  `to` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notifiedmods` timestamp NULL DEFAULT NULL,
  `paid` tinyint(1) NOT NULL DEFAULT '0',
  `amount` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `addressid` (`addressid`),
  KEY `userid` (`userid`),
  KEY `completedby` (`completedby`),
  KEY `completed` (`completed`),
  CONSTRAINT `users_requests_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_requests_ibfk_2` FOREIGN KEY (`addressid`) REFERENCES `users_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_requests_ibfk_3` FOREIGN KEY (`completedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5794 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_schedules`
--

DROP TABLE IF EXISTS `users_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `schedule` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `users_schedules_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=125268 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_searches`
--

DROP TABLE IF EXISTS `users_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_searches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `term` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `maxmsg` bigint(20) unsigned DEFAULT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT '0',
  `locationid` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`,`term`),
  KEY `locationid` (`locationid`),
  KEY `userid_2` (`userid`),
  KEY `maxmsg` (`maxmsg`),
  KEY `userid_3` (`userid`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=23761737 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories`
--

DROP TABLE IF EXISTS `users_stories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_stories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `public` tinyint(1) NOT NULL DEFAULT '1',
  `reviewed` tinyint(1) NOT NULL DEFAULT '0',
  `headline` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `story` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `tweeted` tinyint(4) NOT NULL DEFAULT '0',
  `mailedtocentral` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Mailed to groups mailing list',
  `mailedtomembers` tinyint(1) DEFAULT '0',
  `newsletterreviewed` tinyint(1) NOT NULL DEFAULT '0',
  `newsletter` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `date` (`date`),
  KEY `reviewed` (`reviewed`,`public`,`newsletterreviewed`),
  CONSTRAINT `users_stories_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5134 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories_images`
--

DROP TABLE IF EXISTS `users_stories_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_stories_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `storyid` bigint(20) unsigned DEFAULT NULL,
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`storyid`),
  KEY `hash` (`hash`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories_likes`
--

DROP TABLE IF EXISTS `users_stories_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_stories_likes` (
  `storyid` bigint(20) unsigned NOT NULL,
  `userid` bigint(20) unsigned NOT NULL,
  UNIQUE KEY `storyid_2` (`storyid`,`userid`),
  KEY `storyid` (`storyid`),
  KEY `userid` (`userid`),
  CONSTRAINT `users_stories_likes_ibfk_1` FOREIGN KEY (`storyid`) REFERENCES `users_stories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_stories_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories_requested`
--

DROP TABLE IF EXISTS `users_stories_requested`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_stories_requested` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `userid` (`userid`),
  CONSTRAINT `users_stories_requested_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=147424 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_thanks`
--

DROP TABLE IF EXISTS `users_thanks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_thanks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `users_thanks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14034 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `visualise`
--

DROP TABLE IF EXISTS `visualise`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visualise` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint(20) unsigned NOT NULL,
  `attid` bigint(20) unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fromuser` bigint(20) unsigned NOT NULL,
  `touser` bigint(20) unsigned NOT NULL,
  `fromlat` decimal(10,6) NOT NULL,
  `fromlng` decimal(10,6) NOT NULL,
  `tolat` decimal(10,6) NOT NULL,
  `tolng` decimal(10,6) NOT NULL,
  `distance` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid_2` (`msgid`),
  KEY `fromuser` (`fromuser`),
  KEY `touser` (`touser`),
  KEY `attid` (`attid`),
  CONSTRAINT `_visualise_ibfk_4` FOREIGN KEY (`attid`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualise_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualise_ibfk_2` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualise_ibfk_3` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1349601 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Data to allow us to visualise flows of items to people';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering`
--

DROP TABLE IF EXISTS `volunteering`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteering` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint(20) unsigned DEFAULT NULL,
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
  `deletedby` bigint(20) unsigned DEFAULT NULL,
  `askedtorenew` timestamp NULL DEFAULT NULL,
  `renewed` timestamp NULL DEFAULT NULL,
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  `timecommitment` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `title` (`title`),
  KEY `deletedby` (`deletedby`),
  CONSTRAINT `volunteering_ibfk_1` FOREIGN KEY (`deletedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10311 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering_dates`
--

DROP TABLE IF EXISTS `volunteering_dates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteering_dates` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `volunteeringid` bigint(20) unsigned NOT NULL,
  `start` timestamp NULL DEFAULT NULL,
  `end` timestamp NULL DEFAULT NULL,
  `applyby` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `start` (`start`),
  KEY `eventid` (`volunteeringid`),
  KEY `end` (`end`),
  CONSTRAINT `volunteering_dates_ibfk_1` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3555 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering_groups`
--

DROP TABLE IF EXISTS `volunteering_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteering_groups` (
  `volunteeringid` bigint(20) unsigned NOT NULL,
  `groupid` bigint(20) unsigned NOT NULL,
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `eventid_2` (`volunteeringid`,`groupid`),
  KEY `eventid` (`volunteeringid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `volunteering_groups_ibfk_1` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteering_groups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering_images`
--

DROP TABLE IF EXISTS `volunteering_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteering_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `opportunityid` bigint(20) unsigned DEFAULT NULL COMMENT 'id in the volunteering table',
  `contenttype` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint(4) DEFAULT '0',
  `data` longblob,
  `identification` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`opportunityid`),
  KEY `hash` (`hash`),
  CONSTRAINT `volunteering_images_ibfk_1` FOREIGN KEY (`opportunityid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1839 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vouchers`
--

DROP TABLE IF EXISTS `vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vouchers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `voucher` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used` timestamp NULL DEFAULT NULL,
  `groupid` bigint(20) unsigned DEFAULT NULL COMMENT 'Group that a voucher was used on',
  `userid` bigint(20) unsigned DEFAULT NULL COMMENT 'User who redeemed a voucher',
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher` (`voucher`),
  KEY `groupid` (`groupid`),
  KEY `userid` (`userid`),
  CONSTRAINT `vouchers_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vouchers_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4321 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='For licensing groups';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `vw_ECC`
--

DROP TABLE IF EXISTS `vw_ECC`;
/*!50001 DROP VIEW IF EXISTS `vw_ECC`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_ECC` AS SELECT 
 1 AS `date`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_donations`
--

DROP TABLE IF EXISTS `vw_donations`;
/*!50001 DROP VIEW IF EXISTS `vw_donations`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_donations` AS SELECT 
 1 AS `total`,
 1 AS `date`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_freeglegroups_unreached`
--

DROP TABLE IF EXISTS `vw_freeglegroups_unreached`;
/*!50001 DROP VIEW IF EXISTS `vw_freeglegroups_unreached`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_freeglegroups_unreached` AS SELECT 
 1 AS `id`,
 1 AS `nameshort`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_manyemails`
--

DROP TABLE IF EXISTS `vw_manyemails`;
/*!50001 DROP VIEW IF EXISTS `vw_manyemails`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_manyemails` AS SELECT 
 1 AS `id`,
 1 AS `fullname`,
 1 AS `email`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_membersyncpending`
--

DROP TABLE IF EXISTS `vw_membersyncpending`;
/*!50001 DROP VIEW IF EXISTS `vw_membersyncpending`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_membersyncpending` AS SELECT 
 1 AS `id`,
 1 AS `groupid`,
 1 AS `members`,
 1 AS `lastupdated`,
 1 AS `lastprocessed`,
 1 AS `synctime`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_multiemails`
--

DROP TABLE IF EXISTS `vw_multiemails`;
/*!50001 DROP VIEW IF EXISTS `vw_multiemails`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_multiemails` AS SELECT 
 1 AS `id`,
 1 AS `fullname`,
 1 AS `count`,
 1 AS `GROUP_CONCAT(email SEPARATOR ', ')`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_recentgroupaccess`
--

DROP TABLE IF EXISTS `vw_recentgroupaccess`;
/*!50001 DROP VIEW IF EXISTS `vw_recentgroupaccess`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_recentgroupaccess` AS SELECT 
 1 AS `lastaccess`,
 1 AS `nameshort`,
 1 AS `id`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_recentlogins`
--

DROP TABLE IF EXISTS `vw_recentlogins`;
/*!50001 DROP VIEW IF EXISTS `vw_recentlogins`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_recentlogins` AS SELECT 
 1 AS `timestamp`,
 1 AS `id`,
 1 AS `fullname`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_recentposts`
--

DROP TABLE IF EXISTS `vw_recentposts`;
/*!50001 DROP VIEW IF EXISTS `vw_recentposts`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_recentposts` AS SELECT 
 1 AS `id`,
 1 AS `date`,
 1 AS `fromaddr`,
 1 AS `subject`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_src`
--

DROP TABLE IF EXISTS `vw_src`;
/*!50001 DROP VIEW IF EXISTS `vw_src`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_src` AS SELECT 
 1 AS `count`,
 1 AS `src`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `weights`
--

DROP TABLE IF EXISTS `weights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weights` (
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `simplename` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'The name in simpler terms',
  `weight` decimal(5,2) NOT NULL,
  `source` enum('FRN 2009','Freegle') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FRN 2009',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Standard weights, from FRN 2009';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `words`
--

DROP TABLE IF EXISTS `words`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `words` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `firstthree` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `soundex` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` bigint(20) NOT NULL DEFAULT '0' COMMENT 'Negative as DESC index not supported',
  PRIMARY KEY (`id`),
  UNIQUE KEY `word_2` (`word`),
  KEY `popularity` (`popularity`),
  KEY `word` (`word`,`popularity`),
  KEY `soundex` (`soundex`,`popularity`),
  KEY `firstthree` (`firstthree`,`popularity`)
) ENGINE=InnoDB AUTO_INCREMENT=12769380 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Unique words for searches';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `words_cache`
--

DROP TABLE IF EXISTS `words_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `words_cache` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `search` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `words` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `search` (`search`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=251613 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Final view structure for view `VW_Essex_Searches`
--

/*!50001 DROP VIEW IF EXISTS `VW_Essex_Searches`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_Essex_Searches` AS select cast(`users_searches`.`date` as date) AS `DATE`,count(0) AS `count` from (`users_searches` join `locations` on((`users_searches`.`locationid` = `locations`.`id`))) where (mbrwithin(`locations`.`geometry`,(select `authorities`.`polygon` from `authorities` where (`authorities`.`name` like '%essex%'))) and (`users_searches`.`date` > '2017-07-01')) group by cast(`users_searches`.`date` as date) order by `DATE` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_SupportCallAverage`
--

/*!50001 DROP VIEW IF EXISTS `VW_SupportCallAverage`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_SupportCallAverage` AS select avg(`t`.`total`) AS `AVG(total)` from (select `iznik`.`stats`.`date` AS `date`,sum(`iznik`.`stats`.`count`) AS `total` from `iznik`.`stats` where ((`iznik`.`stats`.`type` = 'SupportQueries') and ((to_days(now()) - to_days(`iznik`.`stats`.`date`)) <= 31)) group by `iznik`.`stats`.`date`) `t` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_invalid_emails_by_month`
--

/*!50001 DROP VIEW IF EXISTS `VW_invalid_emails_by_month`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_invalid_emails_by_month` AS select concat(convert(date_format(`users_emails`.`added`,'%Y-%m-') using utf8mb4),'01') AS `date`,count(0) AS `count` from (`users_emails_verify` join `users_emails` on((`users_emails`.`id` = `users_emails_verify`.`emailid`))) where (`users_emails_verify`.`status` = 'invalid') group by `date` order by `date` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_not_on_central`
--

/*!50001 DROP VIEW IF EXISTS `VW_not_on_central`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_not_on_central` AS select `groups`.`id` AS `id`,`groups`.`nameshort` AS `nameshort` from `groups` where ((`groups`.`type` = 'Freegle') and (`groups`.`publish` = 1) and (not(`groups`.`id` in (select distinct `memberships`.`groupid` from (`memberships` join `groups` on((`groups`.`id` = `memberships`.`groupid`))) where (`memberships`.`userid` in (select distinct `memberships`.`userid` from (`memberships` join `groups` on(((`groups`.`id` = `memberships`.`groupid`) and (`groups`.`nameshort` like 'FreegleUK-Central'))))) and (`memberships`.`role` in ('Owner','Moderator')) and (`groups`.`type` = 'Freegle'))))) and isnull(`groups`.`external`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_recentqueries`
--

/*!50001 DROP VIEW IF EXISTS `VW_recentqueries`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_recentqueries` AS select `chat_messages`.`id` AS `id`,`chat_messages`.`chatid` AS `chatid`,`chat_messages`.`userid` AS `userid`,`chat_messages`.`type` AS `type`,`chat_messages`.`reportreason` AS `reportreason`,`chat_messages`.`refmsgid` AS `refmsgid`,`chat_messages`.`refchatid` AS `refchatid`,`chat_messages`.`date` AS `date`,`chat_messages`.`message` AS `message`,`chat_messages`.`platform` AS `platform`,`chat_messages`.`seenbyall` AS `seenbyall`,`chat_messages`.`reviewrequired` AS `reviewrequired`,`chat_messages`.`reviewedby` AS `reviewedby`,`chat_messages`.`reviewrejected` AS `reviewrejected`,`chat_messages`.`spamscore` AS `spamscore` from (`chat_messages` join `chat_rooms` on((`chat_messages`.`chatid` = `chat_rooms`.`id`))) where (`chat_rooms`.`chattype` = 'User2Mod') order by `chat_messages`.`date` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_routes`
--

/*!50001 DROP VIEW IF EXISTS `VW_routes`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_routes` AS select `logs_events`.`route` AS `route`,count(0) AS `count` from `logs_events` group by `logs_events`.`route` order by `count` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_ECC`
--

/*!50001 DROP VIEW IF EXISTS `vw_ECC`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_ECC` AS select `logs_src`.`date` AS `date`,count(0) AS `count` from `logs_src` where (`logs_src`.`src` = 'ECC') group by cast(`logs_src`.`date` as date) order by `logs_src`.`date` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_donations`
--

/*!50001 DROP VIEW IF EXISTS `vw_donations`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_donations` AS select sum(`users_donations`.`GrossAmount`) AS `total`,cast(`users_donations`.`timestamp` as date) AS `date` from `users_donations` where (((to_days(now()) - to_days(`users_donations`.`timestamp`)) < 31) and (`users_donations`.`Payer` <> 'ppgfukpay@paypalgivingfund.org')) group by `date` order by `date` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_freeglegroups_unreached`
--

/*!50001 DROP VIEW IF EXISTS `vw_freeglegroups_unreached`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_freeglegroups_unreached` AS select `groups`.`id` AS `id`,`groups`.`nameshort` AS `nameshort` from `groups` where ((`groups`.`type` = 'Freegle') and (not((`groups`.`nameshort` like '%playground%'))) and (not((`groups`.`nameshort` like '%test%'))) and (not(`groups`.`id` in (select `alerts_tracking`.`groupid` from `alerts_tracking` where (`alerts_tracking`.`response` is not null))))) order by `groups`.`nameshort` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_manyemails`
--

/*!50001 DROP VIEW IF EXISTS `vw_manyemails`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_manyemails` AS select `users`.`id` AS `id`,`users`.`fullname` AS `fullname`,`users_emails`.`email` AS `email` from (`users` join `users_emails` on((`users`.`id` = `users_emails`.`userid`))) where `users`.`id` in (select `users_emails`.`userid` from `users_emails` group by `users_emails`.`userid` having (count(0) > 4) order by count(0) desc) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_membersyncpending`
--

/*!50001 DROP VIEW IF EXISTS `vw_membersyncpending`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_membersyncpending` AS select `memberships_yahoo_dump`.`id` AS `id`,`memberships_yahoo_dump`.`groupid` AS `groupid`,`memberships_yahoo_dump`.`members` AS `members`,`memberships_yahoo_dump`.`lastupdated` AS `lastupdated`,`memberships_yahoo_dump`.`lastprocessed` AS `lastprocessed`,`memberships_yahoo_dump`.`synctime` AS `synctime` from `memberships_yahoo_dump` where (isnull(`memberships_yahoo_dump`.`lastprocessed`) or (`memberships_yahoo_dump`.`lastupdated` > `memberships_yahoo_dump`.`lastprocessed`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_multiemails`
--

/*!50001 DROP VIEW IF EXISTS `vw_multiemails`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_multiemails` AS select `vw_manyemails`.`id` AS `id`,`vw_manyemails`.`fullname` AS `fullname`,count(0) AS `count`,group_concat(`vw_manyemails`.`email` separator ', ') AS `GROUP_CONCAT(email SEPARATOR ', ')` from `vw_manyemails` group by `vw_manyemails`.`id` order by `count` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_recentgroupaccess`
--

/*!50001 DROP VIEW IF EXISTS `vw_recentgroupaccess`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_recentgroupaccess` AS select `users_logins`.`lastaccess` AS `lastaccess`,`groups`.`nameshort` AS `nameshort`,`groups`.`id` AS `id` from ((`users_logins` join `memberships` on(((`users_logins`.`userid` = `memberships`.`userid`) and (`memberships`.`role` in ('Owner','Moderator'))))) join `groups` on((`memberships`.`groupid` = `groups`.`id`))) order by `users_logins`.`lastaccess` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_recentlogins`
--

/*!50001 DROP VIEW IF EXISTS `vw_recentlogins`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_recentlogins` AS select `logs`.`timestamp` AS `timestamp`,`users`.`id` AS `id`,`users`.`fullname` AS `fullname` from (`users` join `logs` on((`users`.`id` = `logs`.`byuser`))) where ((`logs`.`type` = 'User') and (`logs`.`subtype` = 'Login')) order by `logs`.`timestamp` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_recentposts`
--

/*!50001 DROP VIEW IF EXISTS `vw_recentposts`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_recentposts` AS select `messages`.`id` AS `id`,`messages`.`date` AS `date`,`messages`.`fromaddr` AS `fromaddr`,`messages`.`subject` AS `subject` from (`messages` left join `messages_drafts` on((`messages_drafts`.`msgid` = `messages`.`id`))) where ((`messages`.`source` = 'Platform') and isnull(`messages_drafts`.`msgid`)) order by `messages`.`date` desc limit 20 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_src`
--

/*!50001 DROP VIEW IF EXISTS `vw_src`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_src` AS select count(0) AS `count`,`logs_src`.`src` AS `src` from `logs_src` group by `logs_src`.`src` order by `count` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2019-01-23 11:22:50

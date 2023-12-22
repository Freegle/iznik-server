-- MySQL dump 10.13  Distrib 8.0.35, for Linux (x86_64)
--
-- Host: percona    Database: iznik
-- ------------------------------------------------------
-- Server version	8.0.33-25

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Temporary view structure for view `VW_Essex_Searches`
--

DROP TABLE IF EXISTS `VW_Essex_Searches`;
/*!50001 DROP VIEW IF EXISTS `VW_Essex_Searches`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_Essex_Searches` AS SELECT 
 1 AS `DATE`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_SupportCallAverage`
--

DROP TABLE IF EXISTS `VW_SupportCallAverage`;
/*!50001 DROP VIEW IF EXISTS `VW_SupportCallAverage`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_SupportCallAverage` AS SELECT 
 1 AS `AVG(total)`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_item_similarities`
--

DROP TABLE IF EXISTS `VW_item_similarities`;
/*!50001 DROP VIEW IF EXISTS `VW_item_similarities`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_item_similarities` AS SELECT 
 1 AS `namr1`,
 1 AS `name2`,
 1 AS `a`,
 1 AS `b`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_landing_button`
--

DROP TABLE IF EXISTS `VW_landing_button`;
/*!50001 DROP VIEW IF EXISTS `VW_landing_button`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_landing_button` AS SELECT 
 1 AS `id`,
 1 AS `uid`,
 1 AS `variant`,
 1 AS `shown`,
 1 AS `action`,
 1 AS `rate`,
 1 AS `suggest`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_not_on_central`
--

DROP TABLE IF EXISTS `VW_not_on_central`;
/*!50001 DROP VIEW IF EXISTS `VW_not_on_central`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_not_on_central` AS SELECT 
 1 AS `id`,
 1 AS `nameshort`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_recentqueries`
--

DROP TABLE IF EXISTS `VW_recentqueries`;
/*!50001 DROP VIEW IF EXISTS `VW_recentqueries`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
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
-- Temporary view structure for view `VW_routes`
--

DROP TABLE IF EXISTS `VW_routes`;
/*!50001 DROP VIEW IF EXISTS `VW_routes`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_routes` AS SELECT 
 1 AS `route`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_search_term_similarities`
--

DROP TABLE IF EXISTS `VW_search_term_similarities`;
/*!50001 DROP VIEW IF EXISTS `VW_search_term_similarities`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_search_term_similarities` AS SELECT 
 1 AS `term1`,
 1 AS `term2`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `VW_sincelockdown`
--

DROP TABLE IF EXISTS `VW_sincelockdown`;
/*!50001 DROP VIEW IF EXISTS `VW_sincelockdown`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `VW_sincelockdown` AS SELECT 
 1 AS `msgid`,
 1 AS `date`,
 1 AS `subject`,
 1 AS `nameshort`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `abtest`
--

DROP TABLE IF EXISTS `abtest`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `abtest` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variant` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shown` bigint unsigned NOT NULL,
  `action` bigint unsigned NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `suggest` tinyint(1) NOT NULL DEFAULT '1',
  `timestamp` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_2` (`uid`,`variant`)
) ENGINE=InnoDB AUTO_INCREMENT=1703257356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For testing site changes to see which work';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `createdby` bigint unsigned DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `editedby` bigint unsigned DEFAULT NULL,
  `editedat` timestamp NULL DEFAULT NULL,
  `complete` timestamp NULL DEFAULT NULL,
  `subject` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ctalink` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ctatext` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pending` tinyint(1) NOT NULL DEFAULT '1',
  `parentid` bigint unsigned DEFAULT NULL,
  `heldby` bigint unsigned DEFAULT NULL,
  `heldat` timestamp NULL DEFAULT NULL,
  `activeonly` tinyint(1) NOT NULL DEFAULT '0',
  `sendafter` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `createdby` (`createdby`),
  KEY `complete` (`complete`,`pending`),
  KEY `groupid_2` (`groupid`,`complete`,`pending`),
  KEY `parentid` (`parentid`),
  KEY `editedby` (`editedby`),
  KEY `heldby` (`heldby`),
  CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`editedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703267356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Try all means to reach people with these';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admins_users`
--

DROP TABLE IF EXISTS `admins_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `adminid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `adminid` (`adminid`,`userid`) USING BTREE,
  CONSTRAINT `admins_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admins_users_ibfk_2` FOREIGN KEY (`adminid`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703277356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to prevent dups of related admins';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alerts`
--

DROP TABLE IF EXISTS `alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `createdby` bigint unsigned DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `from` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `to` enum('Users','Mods') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Mods',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `groupprogress` bigint unsigned NOT NULL DEFAULT '0' COMMENT 'For alerts to multiple groups',
  `complete` timestamp NULL DEFAULT NULL,
  `subject` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `askclick` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether to ask them to click to confirm receipt',
  `tryhard` tinyint NOT NULL DEFAULT '1' COMMENT 'Whether to mail all mods addresses too',
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `createdby` (`createdby`),
  CONSTRAINT `alerts_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_ibfk_2` FOREIGN KEY (`createdby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703287356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Try all means to reach people with these';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `alerts_tracking`
--

DROP TABLE IF EXISTS `alerts_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alerts_tracking` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alertid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `emailid` bigint unsigned DEFAULT NULL,
  `type` enum('ModEmail','OwnerEmail','PushNotif','ModToolsNotif') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL,
  `response` enum('Read','Clicked','Bounce','Unsubscribe') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `alertid` (`alertid`),
  KEY `emailid` (`emailid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `_alerts_tracking_ibfk_3` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_tracking_ibfk_1` FOREIGN KEY (`alertid`) REFERENCES `alerts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_tracking_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alerts_tracking_ibfk_4` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703297356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `authorities`
--

DROP TABLE IF EXISTS `authorities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `authorities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `polygon` geometry NOT NULL /*!80003 SRID 3857 */,
  `area_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `simplified` geometry DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`,`area_code`),
  KEY `name_2` (`name`),
  SPATIAL KEY `polygon` (`polygon`)
) ENGINE=InnoDB AUTO_INCREMENT=1703307356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Counties and Unitary Authorities.  May be multigeometries';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `aviva_history`
--

DROP TABLE IF EXISTS `aviva_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aviva_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `position` int NOT NULL,
  `votes` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1703317356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `aviva_votes`
--

DROP TABLE IF EXISTS `aviva_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aviva_votes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `project` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `votes` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project` (`project`),
  KEY `timestamp` (`timestamp`),
  KEY `votes` (`votes`)
) ENGINE=InnoDB AUTO_INCREMENT=1703327356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bounces`
--

DROP TABLE IF EXISTS `bounces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bounces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `msg` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1703337356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bounce messages received by email';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bounces_emails`
--

DROP TABLE IF EXISTS `bounces_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bounces_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `emailid` bigint unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `permanent` tinyint(1) NOT NULL DEFAULT '0',
  `reset` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'If we have reset bounces for this email',
  PRIMARY KEY (`id`),
  KEY `emailid` (`emailid`,`date`),
  CONSTRAINT `bounces_emails_ibfk_1` FOREIGN KEY (`emailid`) REFERENCES `users_emails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703347356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `changes`
--

DROP TABLE IF EXISTS `changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `msgid` bigint unsigned DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `chatid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`),
  KEY `groupid` (`groupid`),
  KEY `chatid` (`chatid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703357356 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_images`
--

DROP TABLE IF EXISTS `chat_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatmsgid` bigint unsigned DEFAULT NULL,
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`chatmsgid`),
  KEY `hash` (`hash`),
  CONSTRAINT `_chat_images_ibfk_1` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703367356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL COMMENT 'From',
  `type` enum('Default','System','ModMail','Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge','Schedule','ScheduleUpdated') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Default',
  `reportreason` enum('Spam','Other','Last','Force','Fully','TooMany','User','UnknownMessage','SameImage') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refmsgid` bigint unsigned DEFAULT NULL,
  `refchatid` bigint unsigned DEFAULT NULL,
  `imageid` bigint unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `platform` tinyint NOT NULL DEFAULT '1' COMMENT 'Whether this was created on the platform vs email',
  `seenbyall` tinyint(1) NOT NULL DEFAULT '0',
  `mailedtoall` tinyint(1) NOT NULL DEFAULT '0',
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether a volunteer should review before it''s passed on',
  `reviewedby` bigint unsigned DEFAULT NULL COMMENT 'User id of volunteer who reviewed it',
  `reviewrejected` tinyint(1) NOT NULL DEFAULT '0',
  `spamscore` int DEFAULT NULL COMMENT 'SpamAssassin score for mail replies',
  `facebookid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduleid` bigint unsigned DEFAULT NULL,
  `replyexpected` tinyint(1) DEFAULT NULL,
  `replyreceived` tinyint(1) NOT NULL,
  `processingrequired` tinyint(1) NOT NULL DEFAULT '0',
  `processingsuccessful` tinyint(1) NOT NULL DEFAULT '0',
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
  KEY `processingrequired` (`processingrequired`),
  CONSTRAINT `_chat_messages_ibfk_1` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_chat_messages_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_chat_messages_ibfk_3` FOREIGN KEY (`refmsgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_chat_messages_ibfk_4` FOREIGN KEY (`reviewedby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_chat_messages_ibfk_5` FOREIGN KEY (`refchatid`) REFERENCES `chat_rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`imageid`) REFERENCES `chat_images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703377356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages_byemail`
--

DROP TABLE IF EXISTS `chat_messages_byemail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages_byemail` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatmsgid` bigint unsigned NOT NULL,
  `msgid` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `chatmsgid` (`chatmsgid`),
  KEY `msgid` (`msgid`),
  CONSTRAINT `chat_messages_byemail_ibfk_1` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_byemail_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703387356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_messages_held`
--

DROP TABLE IF EXISTS `chat_messages_held`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages_held` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`) USING BTREE,
  KEY `userid` (`userid`),
  CONSTRAINT `chat_messages_held_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_held_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703397356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_rooms`
--

DROP TABLE IF EXISTS `chat_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chattype` enum('Mod2Mod','User2Mod','User2User','Group') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User2User',
  `groupid` bigint unsigned DEFAULT NULL COMMENT 'Restricted to a group',
  `user1` bigint unsigned DEFAULT NULL COMMENT 'For DMs',
  `user2` bigint unsigned DEFAULT NULL COMMENT 'For DMs',
  `description` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `synctofacebook` enum('Dont','RepliedOnFacebook','RepliedOnPlatform','PostedLink') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dont',
  `synctofacebookgroupid` bigint unsigned DEFAULT NULL,
  `latestmessage` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Really when chat last active',
  `msgvalid` int unsigned NOT NULL DEFAULT '0',
  `msginvalid` int unsigned NOT NULL DEFAULT '0',
  `flaggedspam` tinyint(1) NOT NULL DEFAULT '0',
  `ljofferid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user1_2` (`user1`,`user2`,`chattype`),
  KEY `user1` (`user1`),
  KEY `user2` (`user2`),
  KEY `synctofacebook` (`synctofacebook`),
  KEY `synctofacebookgroupid` (`synctofacebookgroupid`),
  KEY `chattype` (`chattype`),
  KEY `groupid` (`groupid`),
  KEY `typelatest` (`chattype`,`latestmessage`),
  KEY `rooms` (`user1`,`chattype`,`latestmessage`),
  KEY `rooms2` (`user2`,`chattype`,`latestmessage`),
  KEY `room3` (`groupid`,`latestmessage`,`chattype`),
  CONSTRAINT `chat_rooms_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_rooms_ibfk_2` FOREIGN KEY (`user1`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_rooms_ibfk_3` FOREIGN KEY (`user2`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703407356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chat_roster`
--

DROP TABLE IF EXISTS `chat_roster`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_roster` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Online','Away','Offline','Closed','Blocked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Online',
  `lastmsgseen` bigint unsigned DEFAULT NULL,
  `lastemailed` timestamp NULL DEFAULT NULL,
  `lastmsgemailed` bigint unsigned DEFAULT NULL,
  `lastip` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lasttype` timestamp NULL DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=1703417356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents`
--

DROP TABLE IF EXISTS `communityevents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communityevents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `pending` tinyint(1) NOT NULL DEFAULT '0',
  `title` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contactname` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactphone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactemail` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacturl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted` tinyint NOT NULL DEFAULT '0',
  `legacyid` bigint unsigned DEFAULT NULL COMMENT 'For migration from FDv1',
  `heldby` bigint unsigned DEFAULT NULL,
  `deletedcovid` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Deleted as part of reopening',
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `title` (`title`),
  KEY `legacyid` (`legacyid`),
  KEY `heldby` (`heldby`),
  CONSTRAINT `communityevents_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `communityevents_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703427356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents_dates`
--

DROP TABLE IF EXISTS `communityevents_dates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communityevents_dates` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `eventid` bigint unsigned NOT NULL,
  `start` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `end` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  KEY `start` (`start`),
  KEY `eventid` (`eventid`),
  KEY `end` (`end`),
  CONSTRAINT `communityevents_dates_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703437356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `communityevents_groups`
--

DROP TABLE IF EXISTS `communityevents_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communityevents_groups` (
  `eventid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communityevents_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `eventid` bigint unsigned DEFAULT NULL COMMENT 'id in the community events table',
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`eventid`),
  KEY `hash` (`hash`),
  CONSTRAINT `communityevents_images_ibfk_1` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703457356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `config`
--

DROP TABLE IF EXISTS `config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `config` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1703467356 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domains`
--

DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent` int NOT NULL,
  `defers` int NOT NULL,
  `avgdly` int NOT NULL,
  `problem` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `problem` (`problem`)
) ENGINE=InnoDB AUTO_INCREMENT=1703477356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Statistics on email domains we''ve sent to recently';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `domains_common`
--

DROP TABLE IF EXISTS `domains_common`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains_common` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=InnoDB AUTO_INCREMENT=1703487356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ebay_favourites`
--

DROP TABLE IF EXISTS `ebay_favourites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ebay_favourites` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `count` int NOT NULL,
  `rival` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1703497356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `engage`
--

DROP TABLE IF EXISTS `engage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `engage` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `engagement` enum('UT','New','Occasional','Frequent','Obsessed','Inactive','Dormant') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mailid` bigint unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `succeeded` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `timestamp` (`timestamp`),
  KEY `userid_2` (`userid`,`mailid`) USING BTREE,
  CONSTRAINT `engage_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703507356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User re-engagement attempts';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `engage_mails`
--

DROP TABLE IF EXISTS `engage_mails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `engage_mails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `engagement` enum('UT','New','Occasional','Frequent','Obsessed','Inactive','AtRisk','Dormant') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shown` bigint NOT NULL DEFAULT '0',
  `action` bigint NOT NULL DEFAULT '0',
  `rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `suggest` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `engagement` (`engagement`)
) ENGINE=InnoDB AUTO_INCREMENT=1703517356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `giftaid`
--

DROP TABLE IF EXISTS `giftaid`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `giftaid` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `period` enum('This','Since','Future','Declined','Past4YearsAndFuture') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'This',
  `fullname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `homeaddress` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deleted` timestamp NULL DEFAULT NULL,
  `reviewed` timestamp NULL DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `postcode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `housenameornumber` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `giftaid_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703527356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups`
--

DROP TABLE IF EXISTS `groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of group',
  `legacyid` bigint unsigned DEFAULT NULL COMMENT '(Freegle) Groupid on old system',
  `nameshort` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A short name for the group',
  `namefull` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'A longer name for the group',
  `nameabbr` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'An abbreviated name for the group',
  `namealt` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Alternative name, e.g. as used by GAT',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON-encoded settings for group',
  `type` set('Reuse','Freegle','Other','UnitTest') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other' COMMENT 'High-level characteristics of the group',
  `region` enum('East','East Midlands','West Midlands','North East','North West','Northern Ireland','South East','South West','London','Wales','Yorkshire and the Humber','Scotland') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Freegle only',
  `onyahoo` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether this group is also on Yahoo Groups',
  `onhere` tinyint NOT NULL DEFAULT '0' COMMENT 'Whether this group is available on this platform',
  `ontn` tinyint(1) NOT NULL DEFAULT '0',
  `showonyahoo` tinyint(1) NOT NULL DEFAULT '1' COMMENT '(Freegle) Whether to show Yahoo links',
  `lastyahoomembersync` timestamp NULL DEFAULT NULL COMMENT 'When we last synced approved members',
  `lastyahoomessagesync` timestamp NULL DEFAULT NULL COMMENT 'When we last synced approved messages',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `poly` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Any polygon defining core area',
  `polyofficial` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'If present, GAT area and poly is catchment',
  `polyindex` geometry NOT NULL /*!80003 SRID 3857 */,
  `confirmkey` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Key used to verify some operations by email',
  `publish` tinyint NOT NULL DEFAULT '1' COMMENT '(Freegle) Whether this group is visible to members',
  `listable` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Whether shows up in groups API call',
  `onmap` tinyint NOT NULL DEFAULT '1' COMMENT '(Freegle) Whether to show on the map of groups',
  `licenserequired` tinyint DEFAULT '1' COMMENT 'Whether a license is required for this group',
  `trial` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'For ModTools, when a trial was started',
  `licensed` date DEFAULT NULL COMMENT 'For ModTools, when a group was licensed',
  `licenseduntil` date DEFAULT NULL COMMENT 'For ModTools, when a group is licensed until',
  `membercount` int NOT NULL DEFAULT '0' COMMENT 'Automatically refreshed',
  `modcount` int NOT NULL DEFAULT '0',
  `profile` bigint unsigned DEFAULT NULL,
  `cover` bigint unsigned DEFAULT NULL,
  `tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '(Freegle) One liner slogan for this group',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `founded` date DEFAULT NULL,
  `lasteventsroundup` timestamp NULL DEFAULT NULL COMMENT '(Freegle) Last event roundup sent',
  `lastvolunteeringroundup` timestamp NULL DEFAULT NULL,
  `external` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Link to some other system e.g. Norfolk',
  `contactmail` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For external sites',
  `welcomemail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '(Freegle) Text for welcome mail',
  `activitypercent` decimal(10,2) DEFAULT NULL COMMENT 'Within a group type, the proportion of overall activity that this group accounts for.',
  `fundingtarget` int NOT NULL DEFAULT '0',
  `lastmoderated` timestamp NULL DEFAULT NULL COMMENT 'Last moderated inc Yahoo',
  `lastmodactive` timestamp NULL DEFAULT NULL COMMENT 'Last mod active on here',
  `activemodcount` int DEFAULT NULL COMMENT 'How many currently active mods',
  `backupownersactive` int NOT NULL DEFAULT '0',
  `backupmodsactive` int NOT NULL DEFAULT '0',
  `lastautoapprove` timestamp NULL DEFAULT NULL,
  `affiliationconfirmed` timestamp NULL DEFAULT NULL,
  `affiliationconfirmedby` bigint unsigned DEFAULT NULL,
  `mentored` tinyint(1) NOT NULL DEFAULT '0',
  `seekingmods` tinyint(1) NOT NULL DEFAULT '0',
  `privategroup` tinyint(1) NOT NULL DEFAULT '0',
  `defaultlocation` bigint unsigned DEFAULT NULL,
  `overridemoderation` enum('None','ModerateAll') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'None',
  `altlat` decimal(10,6) DEFAULT NULL,
  `altlng` decimal(10,6) DEFAULT NULL,
  `welcomereview` date DEFAULT NULL,
  `microvolunteering` tinyint(1) NOT NULL DEFAULT '0',
  `microvolunteeringoptions` json DEFAULT NULL,
  `autofunctionoverride` tinyint(1) NOT NULL DEFAULT '0',
  `postvisibility` polygon DEFAULT NULL,
  `onlovejunk` tinyint(1) NOT NULL DEFAULT '1',
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
  KEY `type` (`type`),
  KEY `mentored` (`mentored`),
  KEY `defaultlocation` (`defaultlocation`),
  KEY `affiliationconfirmedby` (`affiliationconfirmedby`),
  KEY `altlat` (`altlat`,`altlng`),
  SPATIAL KEY `polyindex` (`polyindex`),
  CONSTRAINT `groups_ibfk_1` FOREIGN KEY (`profile`) REFERENCES `groups_images` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_ibfk_2` FOREIGN KEY (`cover`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_ibfk_4` FOREIGN KEY (`defaultlocation`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `groups_ibfk_5` FOREIGN KEY (`affiliationconfirmedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=567174 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='The different groups that we host';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_digests`
--

DROP TABLE IF EXISTS `groups_digests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_digests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned NOT NULL,
  `frequency` int NOT NULL,
  `msgid` bigint unsigned DEFAULT NULL COMMENT 'Which message we got upto when sending',
  `msgdate` timestamp NULL DEFAULT NULL COMMENT 'Arrival of message we have sent upto',
  `started` timestamp NULL DEFAULT NULL,
  `ended` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid_2` (`groupid`,`frequency`),
  KEY `groupid` (`groupid`),
  KEY `msggrpid` (`msgid`),
  CONSTRAINT `groups_digests_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_digests_ibfk_3` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703537356 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_facebook`
--

DROP TABLE IF EXISTS `groups_facebook`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_facebook` (
  `uid` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Page','Group') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Page',
  `id` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `authdate` timestamp NULL DEFAULT NULL,
  `msgid` bigint unsigned DEFAULT NULL COMMENT 'Last message posted',
  `msgarrival` timestamp NULL DEFAULT NULL COMMENT 'Time of last message posted',
  `eventid` bigint unsigned DEFAULT NULL COMMENT 'Last event tweeted',
  `valid` tinyint NOT NULL DEFAULT '1',
  `lasterror` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `lasterrortime` timestamp NULL DEFAULT NULL,
  `sharefrom` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '134117207097' COMMENT 'Facebook page to republish from',
  `lastupdated` timestamp NULL DEFAULT NULL COMMENT 'From Graph API',
  `postablecount` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`uid`),
  UNIQUE KEY `groupid_2` (`groupid`,`id`),
  KEY `msgid` (`msgid`),
  KEY `eventid` (`eventid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `groups_facebook_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703547356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_facebook_shares`
--

DROP TABLE IF EXISTS `groups_facebook_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_facebook_shares` (
  `uid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `postid` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('Shared','Hidden','','') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Shared',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_facebook_toshare` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sharefrom` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Page to share from',
  `postid` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Facebook postid',
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `postid` (`postid`),
  KEY `date` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=1703567356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores central posts for sharing out to group pages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_images`
--

DROP TABLE IF EXISTS `groups_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned DEFAULT NULL COMMENT 'id in the groups table',
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`groupid`),
  KEY `hash` (`hash`),
  CONSTRAINT `groups_images_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703577356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_mods_welfare`
--

DROP TABLE IF EXISTS `groups_mods_welfare`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_mods_welfare` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned DEFAULT NULL,
  `modid` bigint unsigned NOT NULL,
  `state` enum('Inactive','Ignore') NOT NULL DEFAULT 'Inactive',
  `warnedat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid_2` (`groupid`,`modid`),
  KEY `groupid` (`groupid`),
  KEY `modid` (`modid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703587356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_sponsorship`
--

DROP TABLE IF EXISTS `groups_sponsorship`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_sponsorship` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `linkurl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `startdate` date NOT NULL,
  `enddate` date NOT NULL,
  `contactname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contactemail` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` int NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `imageurl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT '1',
  `tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`,`startdate`,`enddate`,`visible`) USING BTREE,
  CONSTRAINT `groups_sponsorship_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703597356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `groups_twitter`
--

DROP TABLE IF EXISTS `groups_twitter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_twitter` (
  `groupid` bigint unsigned NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `authdate` timestamp NULL DEFAULT NULL,
  `msgid` bigint unsigned DEFAULT NULL COMMENT 'Last message tweeted',
  `msgarrival` timestamp NULL DEFAULT NULL,
  `eventid` bigint unsigned DEFAULT NULL COMMENT 'Last event tweeted',
  `valid` tinyint NOT NULL DEFAULT '1',
  `locked` tinyint(1) NOT NULL DEFAULT '0',
  `lasterror` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
-- Table structure for table `isochrones`
--

DROP TABLE IF EXISTS `isochrones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `isochrones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `locationid` bigint unsigned DEFAULT NULL,
  `transport` enum('Walk','Cycle','Drive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `minutes` int NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `polygon` geometry NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`locationid`,`transport`,`minutes`),
  KEY `locationid` (`locationid`),
  KEY `locationid_2` (`locationid`,`transport`,`minutes`),
  CONSTRAINT `isochrones_ibfk_2` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=1703617356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `isochrones_users`
--

DROP TABLE IF EXISTS `isochrones_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `isochrones_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `isochroneid` bigint unsigned NOT NULL,
  `nickname` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`isochroneid`),
  KEY `userid` (`userid`),
  KEY `isochroneid` (`isochroneid`),
  CONSTRAINT `isochrones_users_ibfk_1` FOREIGN KEY (`isochroneid`) REFERENCES `isochrones` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `isochrones_users_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=1703627356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `items`
--

DROP TABLE IF EXISTS `items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` int NOT NULL DEFAULT '0',
  `weight` decimal(10,2) DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `suggestfromphoto` tinyint NOT NULL DEFAULT '1' COMMENT 'We can exclude from image recognition',
  `suggestfromtypeahead` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'We can exclude from typeahead',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `popularity` (`popularity`)
) ENGINE=InnoDB AUTO_INCREMENT=1703637356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `items_index`
--

DROP TABLE IF EXISTS `items_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `items_index` (
  `itemid` bigint unsigned NOT NULL,
  `wordid` bigint unsigned NOT NULL,
  `popularity` int NOT NULL DEFAULT '0',
  `categoryid` bigint unsigned DEFAULT NULL,
  UNIQUE KEY `itemid` (`itemid`,`wordid`),
  KEY `itemid_2` (`itemid`),
  KEY `wordid` (`wordid`,`popularity`) USING BTREE,
  CONSTRAINT `items_index_ibfk_1` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `items_index_ibfk_2` FOREIGN KEY (`wordid`) REFERENCES `words` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `items_non`
--

DROP TABLE IF EXISTS `items_non`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `items_non` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` int NOT NULL DEFAULT '1',
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastexample` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1703657356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Not considered items by us, but by image recognition';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location` varchar(256) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `city` varchar(256) DEFAULT NULL,
  `state` varchar(256) DEFAULT NULL,
  `zip` varchar(32) DEFAULT NULL,
  `country` varchar(256) DEFAULT NULL,
  `job_type` varchar(32) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `job_reference` varchar(32) DEFAULT NULL,
  `company` varchar(256) DEFAULT NULL,
  `mobile_friendly_apply` varchar(32) DEFAULT NULL,
  `category` varchar(64) DEFAULT NULL,
  `html_jobs` varchar(32) DEFAULT NULL,
  `url` varchar(1024) DEFAULT NULL,
  `body` text,
  `cpc` decimal(4,4) DEFAULT NULL,
  `geometry` geometry NOT NULL /*!80003 SRID 3857 */,
  `seenat` timestamp NULL DEFAULT NULL,
  `clickability` int NOT NULL DEFAULT '0',
  `bodyhash` varchar(32) DEFAULT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_reference` (`job_reference`),
  UNIQUE KEY `job_reference_2` (`job_reference`),
  UNIQUE KEY `location` (`location`,`title`),
  KEY `bodyhash` (`bodyhash`),
  SPATIAL KEY `geometry` (`geometry`),
  KEY `seenat` (`seenat`)
) ENGINE=InnoDB AUTO_INCREMENT=1703667356 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `jobs_keywords`
--

DROP TABLE IF EXISTS `jobs_keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs_keywords` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword` (`keyword`)
) ENGINE=InnoDB AUTO_INCREMENT=1703677356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `link_previews`
--

DROP TABLE IF EXISTS `link_previews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `link_previews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invalid` tinyint(1) NOT NULL DEFAULT '0',
  `spam` tinyint(1) NOT NULL DEFAULT '0',
  `retrieved` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB AUTO_INCREMENT=1703687356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `osm_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Road','Polygon','Line','Point','Postcode') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `osm_place` tinyint(1) DEFAULT '0',
  `geometry` geometry DEFAULT NULL,
  `ourgeometry` geometry DEFAULT NULL COMMENT 'geometry comes from OSM; this comes from us',
  `gridid` bigint unsigned DEFAULT NULL,
  `postcodeid` bigint unsigned DEFAULT NULL,
  `areaid` bigint unsigned DEFAULT NULL,
  `canon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `popularity` bigint unsigned DEFAULT '0',
  `osm_amenity` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'For OSM locations, whether this is an amenity',
  `osm_shop` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'For OSM locations, whether this is a shop',
  `maxdimension` decimal(10,6) DEFAULT NULL COMMENT 'GetMaxDimension on geomtry',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `newareaid` bigint unsigned DEFAULT NULL,
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
  KEY `newareaid` (`newareaid`),
  CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`gridid`) REFERENCES `locations_grids` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703697356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Location data, the bulk derived from OSM';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_dodgy`
--

DROP TABLE IF EXISTS `locations_dodgy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations_dodgy` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `locationid` bigint unsigned DEFAULT NULL,
  `lat` decimal(10,6) NOT NULL,
  `lng` decimal(10,6) NOT NULL,
  `oldlocationid` bigint unsigned DEFAULT NULL,
  `newlocationid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lat` (`lat`),
  KEY `lng` (`lng`),
  KEY `lat_2` (`lat`,`lng`),
  KEY `oldlocationid` (`oldlocationid`),
  KEY `newlocationid` (`newlocationid`),
  KEY `lat_3` (`lat`,`lng`),
  KEY `locationid` (`locationid`),
  KEY `locationid_2` (`locationid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703707356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_excluded`
--

DROP TABLE IF EXISTS `locations_excluded`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations_excluded` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `locationid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `norfolk` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `locationid_2` (`locationid`,`groupid`),
  KEY `locationid` (`locationid`),
  KEY `groupid` (`groupid`),
  KEY `by` (`userid`),
  CONSTRAINT `_locations_excluded_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `locations_excluded_ibfk_3` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `locations_excluded_ibfk_4` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703717356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stops locations being suggested on a group';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_grids`
--

DROP TABLE IF EXISTS `locations_grids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations_grids` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `swlat` decimal(10,6) NOT NULL,
  `swlng` decimal(10,6) NOT NULL,
  `nelat` decimal(10,6) NOT NULL,
  `nelng` decimal(10,6) NOT NULL,
  `box` geometry NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `swlat` (`swlat`,`swlng`,`nelat`,`nelng`)
) ENGINE=InnoDB AUTO_INCREMENT=1703727356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to map lat/lng to gridid for location searches';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_grids_touches`
--

DROP TABLE IF EXISTS `locations_grids_touches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations_grids_touches` (
  `gridid` bigint unsigned NOT NULL,
  `touches` bigint unsigned NOT NULL,
  UNIQUE KEY `gridid` (`gridid`,`touches`),
  KEY `touches` (`touches`),
  CONSTRAINT `locations_grids_touches_ibfk_1` FOREIGN KEY (`gridid`) REFERENCES `locations_grids` (`id`) ON DELETE CASCADE,
  CONSTRAINT `locations_grids_touches_ibfk_2` FOREIGN KEY (`touches`) REFERENCES `locations_grids` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A record of which grid squares touch others';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_newareas`
--

DROP TABLE IF EXISTS `locations_newareas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations_newareas` (
  `locationid` bigint unsigned NOT NULL,
  `areaid` bigint unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations_spatial`
--

DROP TABLE IF EXISTS `locations_spatial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations_spatial` (
  `locationid` bigint unsigned NOT NULL,
  `geometry` geometry NOT NULL /*!80003 SRID 3857 */,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `date` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=1703767356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Machine assumed set to GMT',
  `byuser` bigint unsigned DEFAULT NULL COMMENT 'User responsible for action, if any',
  `type` enum('Group','Message','User','Plugin','Config','StdMsg','Location','BulkOp','Chat') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtype` enum('Created','Deleted','Received','Sent','Failure','ClassifiedSpam','Joined','Left','Approved','Rejected','YahooDeliveryType','YahooPostingStatus','NotSpam','Login','Hold','Release','Edit','RoleChange','Merged','Split','Replied','Mailed','Applied','Suspect','Licensed','LicensePurchase','YahooApplied','YahooConfirmed','YahooJoined','MailOff','EventsOff','NewslettersOff','RelevantOff','Logout','Bounce','SuspendMail','Autoreposted','Outcome','OurPostingStatus','VolunteersOff','Autoapproved','Unbounce','WorryWords','NoteAdded','PostcodeChange','Repost') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL COMMENT 'Any group this log is for',
  `user` bigint unsigned DEFAULT NULL COMMENT 'Any user that this log is about',
  `msgid` bigint unsigned DEFAULT NULL COMMENT 'id in the messages table',
  `configid` bigint unsigned DEFAULT NULL COMMENT 'id in the mod_configs table',
  `stdmsgid` bigint unsigned DEFAULT NULL COMMENT 'Any stdmsg for this log',
  `bulkopid` bigint unsigned DEFAULT NULL,
  `text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `group` (`groupid`),
  KEY `type` (`type`,`subtype`),
  KEY `timestamp` (`timestamp`),
  KEY `byuser` (`byuser`),
  KEY `user` (`user`),
  KEY `msgid` (`msgid`),
  KEY `timestamp_2` (`timestamp`,`type`,`subtype`)
) ENGINE=InnoDB AUTO_INCREMENT=1703777356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs.  Not guaranteed against loss';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_api`
--

DROP TABLE IF EXISTS `logs_api`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_api` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userid` bigint DEFAULT NULL,
  `ip` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `session` (`session`),
  KEY `date` (`date`),
  KEY `userid` (`userid`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB AUTO_INCREMENT=1703787356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci KEY_BLOCK_SIZE=8 COMMENT='Log of all API requests and responses';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_emails`
--

DROP TABLE IF EXISTS `logs_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `eximid` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messageid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`),
  KEY `userid` (`userid`),
  KEY `timestamp_2` (`eximid`) USING BTREE,
  CONSTRAINT `logs_emails_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703797356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_errors`
--

DROP TABLE IF EXISTS `logs_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_errors` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Exception') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint DEFAULT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703807356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Errors from client';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_events`
--

DROP TABLE IF EXISTS `logs_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `sessionid` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `clienttimestamp` timestamp NULL DEFAULT NULL,
  `posx` int DEFAULT NULL,
  `posy` int DEFAULT NULL,
  `viewx` int DEFAULT NULL,
  `viewy` int DEFAULT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `datasameas` bigint unsigned DEFAULT NULL COMMENT 'Allows use to reuse data stored in table once for other rows',
  `datahash` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `route` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`,`timestamp`),
  KEY `sessionid` (`sessionid`),
  KEY `datasameas` (`datasameas`),
  KEY `datahash` (`datahash`,`datasameas`),
  KEY `ip` (`ip`),
  KEY `timestamp` (`timestamp`),
  KEY `sessionid_2` (`sessionid`,`userid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703817356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_jobs`
--

DROP TABLE IF EXISTS `logs_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `jobid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timestamp` (`timestamp`),
  KEY `jobid` (`jobid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703827356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_sql`
--

DROP TABLE IF EXISTS `logs_sql`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_sql` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duration` decimal(15,10) unsigned DEFAULT '0.0000000000' COMMENT 'seconds',
  `userid` bigint unsigned DEFAULT NULL,
  `session` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'rc:lastInsertId',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `session` (`session`),
  KEY `date` (`date`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703837356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 COMMENT='Log of modification SQL operations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `logs_src`
--

DROP TABLE IF EXISTS `logs_src`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs_src` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `src` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userid` bigint unsigned DEFAULT NULL,
  `session` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `src` (`src`)
) ENGINE=InnoDB AUTO_INCREMENT=1703847356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Record which mails we sent generated website traffic';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lovejunk`
--

DROP TABLE IF EXISTS `lovejunk`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lovejunk` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint unsigned NOT NULL,
  `success` tinyint(1) NOT NULL,
  `status` text NOT NULL,
  `deleted` timestamp NULL DEFAULT NULL,
  `deletestatus` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`)
) ENGINE=InnoDB AUTO_INCREMENT=1703857357 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships`
--

DROP TABLE IF EXISTS `memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `memberships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `role` enum('Member','Moderator','Owner') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `collection` enum('Approved','Pending','Banned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `configid` bigint unsigned DEFAULT NULL COMMENT 'Configuration used to moderate this group if a moderator',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settings` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Other group settings, e.g. for moderators',
  `syncdelete` tinyint NOT NULL DEFAULT '0' COMMENT 'Used during member sync',
  `heldby` bigint unsigned DEFAULT NULL,
  `emailfrequency` int NOT NULL DEFAULT '24' COMMENT 'In hours; -1 immediately, 0 never',
  `eventsallowed` tinyint(1) DEFAULT '1',
  `volunteeringallowed` bigint NOT NULL DEFAULT '1',
  `ourPostingStatus` enum('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, NULL; for ours, the posting status',
  `reviewrequestedat` timestamp NULL DEFAULT NULL,
  `reviewreason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewedat` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_groupid` (`userid`,`groupid`),
  KEY `groupid_2` (`groupid`,`role`),
  KEY `userid` (`userid`,`role`),
  KEY `role` (`role`),
  KEY `configid` (`configid`),
  KEY `groupid` (`groupid`,`collection`),
  KEY `heldby` (`heldby`),
  KEY `collection` (`collection`),
  KEY `added_groupid` (`added`,`groupid`),
  KEY `reviewrequestedat` (`reviewrequestedat`),
  CONSTRAINT `memberships_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_ibfk_3` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_ibfk_4` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memberships_ibfk_5` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703867356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Which groups users are members of';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships_history`
--

DROP TABLE IF EXISTS `memberships_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `memberships_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `collection` enum('Approved','Pending','Banned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processingrequired` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `date` (`added`),
  KEY `userid` (`userid`,`groupid`),
  KEY `processingrequired` (`processingrequired`),
  CONSTRAINT `memberships_history_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_history_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703877356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to spot multijoiners';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships_yahoo`
--

DROP TABLE IF EXISTS `memberships_yahoo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `memberships_yahoo` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `membershipid` bigint unsigned NOT NULL,
  `role` enum('Member','Moderator','Owner') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `collection` enum('Approved','Pending','Banned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Approved',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailid` bigint unsigned NOT NULL COMMENT 'Which of their emails they use on this group',
  `yahooAlias` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `yahooPostingStatus` enum('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Yahoo mod status if applicable',
  `yahooDeliveryType` enum('DIGEST','NONE','SINGLE','ANNOUNCEMENT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Yahoo delivery settings if applicable',
  `syncdelete` tinyint NOT NULL DEFAULT '0' COMMENT 'Used during member sync',
  `yahooapprove` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, email to approve member if known and relevant',
  `yahooreject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo groups, email to reject member if known and relevant',
  `joincomment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any joining comment for this member',
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
) ENGINE=InnoDB AUTO_INCREMENT=1703887356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Which groups users are members of';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `memberships_yahoo_dump`
--

DROP TABLE IF EXISTS `memberships_yahoo_dump`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `memberships_yahoo_dump` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned NOT NULL,
  `members` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lastupdated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastprocessed` timestamp NULL DEFAULT NULL COMMENT 'When this was last processed into the main tables',
  `synctime` timestamp NULL DEFAULT NULL COMMENT 'Time on client when sync started',
  `backgroundok` tinyint NOT NULL DEFAULT '1',
  `needsprocessing` tinyint(1) GENERATED ALWAYS AS ((`lastupdated` > `lastprocessed`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid` (`groupid`),
  KEY `lastprocessed` (`lastprocessed`),
  KEY `lastupdated` (`lastupdated`,`lastprocessed`),
  KEY `needsprocessing` (`needsprocessing`),
  CONSTRAINT `memberships_yahoo_dump_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703897356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Copy of last member sync from Yahoo';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `merges`
--

DROP TABLE IF EXISTS `merges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `merges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user1` bigint unsigned NOT NULL,
  `user2` bigint unsigned NOT NULL,
  `offered` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted` timestamp NULL DEFAULT NULL,
  `rejected` timestamp NULL DEFAULT NULL,
  `offeredby` bigint unsigned NOT NULL,
  `uid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1703907356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Offers of merges to members';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique iD',
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this message arrived at our server',
  `date` timestamp NULL DEFAULT NULL COMMENT 'When this message was created, e.g. Date header',
  `deleted` timestamp NULL DEFAULT NULL COMMENT 'When this message was deleted',
  `heldby` bigint unsigned DEFAULT NULL COMMENT 'If this message is held by a moderator',
  `source` enum('Yahoo Approved','Yahoo Pending','Yahoo System','Platform','Email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Source of incoming message',
  `sourceheader` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any source header, e.g. X-Freegle-Source',
  `fromip` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IP we think this message came from',
  `fromcountry` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'fromip geocoded to country',
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The unparsed message',
  `fromuser` bigint unsigned DEFAULT NULL,
  `envelopefrom` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fromname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fromaddr` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `envelopeto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replyto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suggestedsubject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Offer','Taken','Wanted','Received','Admin','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For reuse groups, the message categorisation',
  `messageid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tnpostid` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'If this message came from Trash Nothing, the unique post ID',
  `textbody` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `htmlbody` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `retrycount` int NOT NULL DEFAULT '0' COMMENT 'We might fail to route, and later retry',
  `retrylastfailure` timestamp NULL DEFAULT NULL,
  `spamtype` enum('CountryBlocked','IPUsedForDifferentUsers','IPUsedForDifferentGroups','SubjectUsedForDifferentGroups','SpamAssassin','NotSpam','WorryWord') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spamreason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Why we think this message may be spam',
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  `locationid` bigint unsigned DEFAULT NULL,
  `editedby` bigint unsigned DEFAULT NULL,
  `editedat` timestamp NULL DEFAULT NULL,
  `availableinitially` int NOT NULL DEFAULT '1',
  `availablenow` int NOT NULL DEFAULT '1',
  `lastroute` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  KEY `lng` (`lng`) KEY_BLOCK_SIZE=16,
  KEY `locationid` (`locationid`) KEY_BLOCK_SIZE=16,
  KEY `fromuser_2` (`fromuser`,`arrival`,`type`),
  KEY `lat` (`lat`) USING BTREE,
  CONSTRAINT `_messages_ibfk_1` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_messages_ibfk_2` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `_messages_ibfk_3` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703917356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 COMMENT='All our messages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_attachments`
--

DROP TABLE IF EXISTS `messages_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned DEFAULT NULL COMMENT 'id in the messages table',
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `externaluid` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rotated` tinyint(1) NOT NULL DEFAULT '0',
  `primary` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `incomingid` (`msgid`),
  KEY `hash` (`hash`),
  KEY `externaluid` (`externaluid`),
  CONSTRAINT `_messages_attachments_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703927356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_attachments_items`
--

DROP TABLE IF EXISTS `messages_attachments_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_attachments_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attid` bigint unsigned NOT NULL,
  `itemid` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `msgid` (`attid`),
  KEY `itemid` (`itemid`),
  CONSTRAINT `messages_attachments_items_ibfk_1` FOREIGN KEY (`attid`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_attachments_items_ibfk_2` FOREIGN KEY (`itemid`) REFERENCES `items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703937356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_by`
--

DROP TABLE IF EXISTS `messages_by`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_by` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `count` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid_2` (`msgid`,`userid`),
  KEY `msgid` (`msgid`),
  KEY `userid` (`userid`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `messages_by_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_by_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703947356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_deadlines`
--

DROP TABLE IF EXISTS `messages_deadlines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_deadlines` (
  `msgid` bigint unsigned NOT NULL,
  `FOP` tinyint NOT NULL DEFAULT '1',
  `mustgoby` date DEFAULT NULL,
  UNIQUE KEY `msgid` (`msgid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_drafts`
--

DROP TABLE IF EXISTS `messages_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `session` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`),
  KEY `userid` (`userid`),
  KEY `session` (`session`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `messages_drafts_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_drafts_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_drafts_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1703967356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_edits`
--

DROP TABLE IF EXISTS `messages_edits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_edits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `byuser` bigint unsigned DEFAULT NULL,
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0',
  `revertedat` timestamp NULL DEFAULT NULL,
  `approvedat` timestamp NULL DEFAULT NULL,
  `oldtext` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `newtext` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `oldsubject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `newsubject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oldtype` enum('Offer','Taken','Wanted','Received','Admin','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `newtype` enum('Offer','Taken','Wanted','Received','Admin','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `olditems` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `newitems` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `oldimages` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `newimages` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `oldlocation` bigint unsigned DEFAULT NULL,
  `newlocation` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `byuser` (`byuser`),
  KEY `timestamp` (`timestamp`,`reviewrequired`),
  CONSTRAINT `messages_edits_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_edits_ibfk_2` FOREIGN KEY (`byuser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703977356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_groups`
--

DROP TABLE IF EXISTS `messages_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_groups` (
  `msgid` bigint unsigned NOT NULL COMMENT 'id in the messages table',
  `groupid` bigint unsigned NOT NULL,
  `collection` enum('Incoming','Pending','Approved','Spam','QueuedYahooUser','Rejected','QueuedUser') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `autoreposts` tinyint NOT NULL DEFAULT '0' COMMENT 'How many times this message has been auto-reposted',
  `lastautopostwarning` timestamp NULL DEFAULT NULL,
  `lastchaseup` timestamp NULL DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `senttoyahoo` tinyint(1) NOT NULL DEFAULT '0',
  `yahoopendingid` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, pending id if relevant',
  `yahooapprovedid` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, approved id if relevant',
  `yahooapprove` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, email to trigger approve if relevant',
  `yahooreject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For Yahoo messages, email to trigger reject if relevant',
  `approvedby` bigint unsigned DEFAULT NULL COMMENT 'Mod who approved this post (if any)',
  `approvedat` timestamp NULL DEFAULT NULL,
  `rejectedat` timestamp NULL DEFAULT NULL,
  `msgtype` enum('Offer','Taken','Wanted','Received','Admin','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'In here for performance optimisation',
  UNIQUE KEY `msgid` (`msgid`,`groupid`),
  UNIQUE KEY `groupid_3` (`groupid`,`yahooapprovedid`),
  UNIQUE KEY `groupid_2` (`groupid`,`yahoopendingid`),
  KEY `messageid` (`msgid`,`groupid`,`collection`,`arrival`),
  KEY `collection` (`collection`),
  KEY `approvedby` (`approvedby`),
  KEY `groupid` (`groupid`,`collection`,`deleted`,`arrival`),
  KEY `deleted` (`deleted`),
  KEY `arrival` (`arrival`,`groupid`,`msgtype`) USING BTREE,
  KEY `lastapproved` (`approvedby`,`groupid`,`arrival`),
  CONSTRAINT `_messages_groups_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_messages_groups_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_messages_groups_ibfk_3` FOREIGN KEY (`approvedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='The state of the message on each group';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_history`
--

DROP TABLE IF EXISTS `messages_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique iD',
  `msgid` bigint unsigned DEFAULT NULL COMMENT 'id in the messages table',
  `arrival` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this message arrived at our server',
  `source` enum('Yahoo Approved','Yahoo Pending','Yahoo System','Platform') CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL COMMENT 'Source of incoming message',
  `fromip` varchar(40) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL COMMENT 'IP we think this message came from',
  `fromhost` varchar(80) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL COMMENT 'Hostname for fromip if resolvable, or NULL',
  `fromuser` bigint unsigned DEFAULT NULL,
  `envelopefrom` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fromname` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `fromaddr` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `envelopeto` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL COMMENT 'Destination group, if identified',
  `subject` varchar(1024) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `prunedsubject` varchar(1024) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL COMMENT 'For spam detection',
  `messageid` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=1703997356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Message arrivals, used for spam checking';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_index`
--

DROP TABLE IF EXISTS `messages_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_index` (
  `msgid` bigint unsigned NOT NULL,
  `wordid` bigint unsigned NOT NULL,
  `arrival` bigint NOT NULL COMMENT 'We prioritise recent messages',
  `groupid` bigint unsigned DEFAULT NULL,
  UNIQUE KEY `msgid` (`msgid`,`wordid`),
  KEY `arrival` (`arrival`),
  KEY `groupid` (`groupid`),
  KEY `wordid` (`wordid`,`groupid`),
  CONSTRAINT `_messages_index_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `_messages_index_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_index_ibfk_1` FOREIGN KEY (`wordid`) REFERENCES `words` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For indexing messages for search keywords';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_items`
--

DROP TABLE IF EXISTS `messages_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_items` (
  `msgid` bigint unsigned NOT NULL,
  `itemid` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_likes` (
  `msgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `type` enum('Love','Laugh','View') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `count` int NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint unsigned NOT NULL,
  `outcome` enum('Taken','Received','Withdrawn','Repost') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `happiness` enum('Happy','Fine','Unhappy') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  KEY `timestamp_2` (`timestamp`,`outcome`),
  KEY `timestamp_3` (`reviewed`,`timestamp`,`happiness`) USING BTREE,
  CONSTRAINT `messages_outcomes_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704037356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_outcomes_intended`
--

DROP TABLE IF EXISTS `messages_outcomes_intended`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_outcomes_intended` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint unsigned NOT NULL,
  `outcome` enum('Taken','Received','Withdrawn','Repost') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid_2` (`msgid`,`outcome`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  KEY `timestamp_2` (`timestamp`,`outcome`),
  KEY `msgid_3` (`msgid`),
  CONSTRAINT `messages_outcomes_intended_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704047356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='When someone starts telling us an outcome but doesn''t finish';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_popular`
--

DROP TABLE IF EXISTS `messages_popular`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_popular` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `shared` tinyint(1) NOT NULL DEFAULT '0',
  `declined` tinyint(1) NOT NULL DEFAULT '0',
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `groupid` (`groupid`) USING BTREE,
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `messages_popular_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `messages_popular_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=1704057356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Recent popular messages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_postings`
--

DROP TABLE IF EXISTS `messages_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_postings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `repost` tinyint(1) NOT NULL DEFAULT '0',
  `autorepost` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `msgid` (`msgid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `messages_postings_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_postings_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704067356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_promises`
--

DROP TABLE IF EXISTS `messages_promises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_promises` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `promisedat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`,`userid`) USING BTREE,
  KEY `userid` (`userid`),
  KEY `promisedat` (`promisedat`),
  CONSTRAINT `messages_promises_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_promises_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704077356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_related`
--

DROP TABLE IF EXISTS `messages_related`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_related` (
  `id1` bigint unsigned NOT NULL,
  `id2` bigint unsigned NOT NULL,
  UNIQUE KEY `id1_2` (`id1`,`id2`),
  KEY `id1` (`id1`),
  KEY `id2` (`id2`),
  CONSTRAINT `messages_related_ibfk_1` FOREIGN KEY (`id1`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_related_ibfk_2` FOREIGN KEY (`id2`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Messages which are related to each other';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_reneged`
--

DROP TABLE IF EXISTS `messages_reneged`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_reneged` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `msgid` bigint unsigned NOT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `messages_reneged_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_reneged_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704097356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_spamham`
--

DROP TABLE IF EXISTS `messages_spamham`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_spamham` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `spamham` enum('Spam','Ham') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`),
  CONSTRAINT `messages_spamham_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704107356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User feedback on messages ';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages_spatial`
--

DROP TABLE IF EXISTS `messages_spatial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_spatial` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `point` geometry NOT NULL /*!80003 SRID 3857 */,
  `successful` tinyint(1) NOT NULL DEFAULT '0',
  `promised` tinyint(1) NOT NULL DEFAULT '0',
  `groupid` bigint unsigned DEFAULT NULL,
  `msgtype` enum('Offer','Taken','Wanted','Received','Admin','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrival` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid` (`msgid`) USING BTREE,
  KEY `groupid` (`groupid`),
  SPATIAL KEY `point` (`point`),
  CONSTRAINT `messages_spatial_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_spatial_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704117356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Recent open messages with locations';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `microactions`
--

DROP TABLE IF EXISTS `microactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `microactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actiontype` enum('CheckMessage','SearchTerm','Items','FacebookShare','PhotoRotate','ItemSize','ItemWeight') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint unsigned NOT NULL,
  `msgid` bigint unsigned DEFAULT NULL,
  `msgcategory` enum('CouldBeBetter','ShouldntBeHere','NotSure') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` enum('Approve','Reject') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `searchterm1` bigint unsigned DEFAULT NULL,
  `searchterm2` bigint unsigned DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1' COMMENT 'For when we make changes which affect the validity of the data',
  `item1` bigint unsigned DEFAULT NULL,
  `item2` bigint unsigned DEFAULT NULL,
  `facebook_post` bigint unsigned DEFAULT NULL,
  `rotatedimage` bigint unsigned DEFAULT NULL,
  `score_positive` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `score_negative` decimal(10,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`msgid`),
  UNIQUE KEY `userid_3` (`userid`,`searchterm1`,`searchterm2`),
  UNIQUE KEY `userid_4` (`userid`,`item1`,`item2`),
  UNIQUE KEY `userid_5` (`userid`,`facebook_post`),
  KEY `userid` (`userid`),
  KEY `msgid` (`msgid`),
  KEY `searchterm1` (`searchterm1`),
  KEY `searchterm2` (`searchterm2`),
  KEY `item1` (`item1`),
  KEY `item2` (`item2`),
  KEY `facebook_post` (`facebook_post`),
  KEY `rotatedimage` (`rotatedimage`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `microactions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_3` FOREIGN KEY (`searchterm1`) REFERENCES `search_terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_4` FOREIGN KEY (`searchterm2`) REFERENCES `search_terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_5` FOREIGN KEY (`item1`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_6` FOREIGN KEY (`item2`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_7` FOREIGN KEY (`facebook_post`) REFERENCES `groups_facebook_toshare` (`id`) ON DELETE CASCADE,
  CONSTRAINT `microactions_ibfk_8` FOREIGN KEY (`rotatedimage`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704127356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Micro-volunteering tasks';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_bulkops`
--

DROP TABLE IF EXISTS `mod_bulkops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mod_bulkops` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `configid` bigint unsigned DEFAULT NULL,
  `set` enum('Members') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `criterion` enum('Bouncing','BouncingFor','WebOnly','All') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `runevery` int NOT NULL DEFAULT '168' COMMENT 'In hours',
  `action` enum('Unbounce','Remove','ToGroup','ToSpecialNotices') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bouncingfor` int NOT NULL DEFAULT '90',
  UNIQUE KEY `uniqueid` (`id`),
  KEY `configid` (`configid`),
  CONSTRAINT `mod_bulkops_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704137356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_bulkops_run`
--

DROP TABLE IF EXISTS `mod_bulkops_run`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mod_bulkops_run` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bulkopid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `runstarted` timestamp NULL DEFAULT NULL,
  `runfinished` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bulkopid_2` (`bulkopid`,`groupid`),
  KEY `bulkopid` (`bulkopid`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `mod_bulkops_run_ibfk_1` FOREIGN KEY (`bulkopid`) REFERENCES `mod_bulkops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mod_bulkops_run_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704147356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_configs`
--

DROP TABLE IF EXISTS `mod_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mod_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of config',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name of config set',
  `createdby` bigint unsigned DEFAULT NULL COMMENT 'Moderator ID who created it',
  `fromname` enum('My name','Groupname Moderator') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'My name',
  `ccrejectto` enum('Nobody','Me','Specific') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccrejectaddr` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccfollowupto` enum('Nobody','Me','Specific') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccfollowupaddr` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccrejmembto` enum('Nobody','Me','Specific') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccrejmembaddr` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ccfollmembto` enum('Nobody','Me','Specific') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Nobody',
  `ccfollmembaddr` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `protected` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Protect from edit?',
  `messageorder` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'CSL of ids of standard messages in order in which they should appear',
  `network` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `coloursubj` tinyint(1) NOT NULL DEFAULT '1',
  `subjreg` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '^(OFFER|WANTED|TAKEN|RECEIVED) *[\\:-].*\\(.*\\)',
  `subjlen` int NOT NULL DEFAULT '68',
  `default` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Default configs are always visible',
  `chatread` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `uniqueid` (`id`,`createdby`),
  KEY `createdby` (`createdby`),
  KEY `default` (`default`),
  CONSTRAINT `mod_configs_ibfk_1` FOREIGN KEY (`createdby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704157356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurations for use by moderators';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_stdmsgs`
--

DROP TABLE IF EXISTS `mod_stdmsgs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mod_stdmsgs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID of standard message',
  `configid` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Title of standard message',
  `action` enum('Approve','Reject','Leave','Approve Member','Reject Member','Leave Member','Leave Approved Message','Delete Approved Message','Leave Approved Member','Delete Approved Member','Edit','Hold Message') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Reject' COMMENT 'What action to take',
  `subjpref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Subject prefix',
  `subjsuff` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Subject suffix',
  `body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rarelyused` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Rarely used messages may be hidden in the UI',
  `autosend` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Send the message immediately rather than wait for user',
  `newmodstatus` enum('UNCHANGED','MODERATED','DEFAULT','PROHIBITED','UNMODERATED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNCHANGED' COMMENT 'Yahoo mod status afterwards',
  `newdelstatus` enum('UNCHANGED','DIGEST','NONE','SINGLE','ANNOUNCEMENT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNCHANGED' COMMENT 'Yahoo delivery status afterwards',
  `edittext` enum('Unchanged','Correct Case') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unchanged',
  `insert` enum('Top','Bottom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Top',
  UNIQUE KEY `id` (`id`),
  KEY `configid` (`configid`),
  CONSTRAINT `mod_stdmsgs_ibfk_1` FOREIGN KEY (`configid`) REFERENCES `mod_configs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704167356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modnotifs`
--

DROP TABLE IF EXISTS `modnotifs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modnotifs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `modnotifs_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704177356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed`
--

DROP TABLE IF EXISTS `newsfeed`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsfeed` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Message','CommunityEvent','VolunteerOpportunity','CentralPublicity','Alert','Story','ReferToWanted','ReferToOffer','ReferToTaken','ReferToReceived','AboutMe','Noticeboard') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Message',
  `userid` bigint unsigned DEFAULT NULL,
  `imageid` bigint unsigned DEFAULT NULL,
  `msgid` bigint unsigned DEFAULT NULL,
  `replyto` bigint unsigned DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `eventid` bigint unsigned DEFAULT NULL,
  `volunteeringid` bigint unsigned DEFAULT NULL,
  `publicityid` bigint unsigned DEFAULT NULL,
  `storyid` bigint unsigned DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `position` geometry NOT NULL /*!80003 SRID 3857 */,
  `reviewrequired` tinyint(1) NOT NULL DEFAULT '0',
  `deleted` timestamp NULL DEFAULT NULL,
  `deletedby` bigint unsigned DEFAULT NULL,
  `hidden` timestamp NULL DEFAULT NULL,
  `hiddenby` bigint unsigned DEFAULT NULL,
  `closed` tinyint(1) NOT NULL DEFAULT '0',
  `html` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pinned` tinyint(1) NOT NULL DEFAULT '0',
  `location` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `imageid` (`imageid`),
  KEY `msgid` (`msgid`),
  KEY `replyto` (`replyto`),
  KEY `groupid` (`groupid`),
  KEY `volunteeringid` (`volunteeringid`),
  KEY `publicityid` (`publicityid`),
  KEY `timestamp` (`timestamp`),
  KEY `storyid` (`storyid`),
  KEY `pinned` (`pinned`,`timestamp`),
  KEY `eventid` (`eventid`) USING BTREE,
  SPATIAL KEY `position` (`position`),
  CONSTRAINT `newsfeed_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_4` FOREIGN KEY (`eventid`) REFERENCES `communityevents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_5` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_6` FOREIGN KEY (`publicityid`) REFERENCES `groups_facebook_toshare` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_ibfk_7` FOREIGN KEY (`storyid`) REFERENCES `users_stories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704187356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_images`
--

DROP TABLE IF EXISTS `newsfeed_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsfeed_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `newsfeedid` bigint unsigned DEFAULT NULL COMMENT 'id in the community events table',
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`newsfeedid`),
  KEY `hash` (`hash`)
) ENGINE=InnoDB AUTO_INCREMENT=1704197356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_likes`
--

DROP TABLE IF EXISTS `newsfeed_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsfeed_likes` (
  `newsfeedid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsfeed_reports` (
  `newsfeedid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsfeed_unfollow` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `newsfeedid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`newsfeedid`),
  KEY `userid` (`userid`),
  KEY `newsfeedid` (`newsfeedid`),
  CONSTRAINT `newsfeed_unfollow_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsfeed_unfollow_ibfk_2` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704227356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsfeed_users`
--

DROP TABLE IF EXISTS `newsfeed_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsfeed_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `newsfeedid` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  KEY `newsfeedid` (`newsfeedid`),
  CONSTRAINT `newsfeed_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704237356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters`
--

DROP TABLE IF EXISTS `newsletters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsletters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned DEFAULT NULL,
  `subject` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `textbody` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'For people who don''t read HTML',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `uptouser` bigint unsigned DEFAULT NULL COMMENT 'User id we are upto, roughly',
  `type` enum('General','Stories','','') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `newsletters_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704247356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters_articles`
--

DROP TABLE IF EXISTS `newsletters_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsletters_articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `newsletterid` bigint unsigned NOT NULL,
  `type` enum('Header','Article') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Article',
  `position` int NOT NULL,
  `html` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `photoid` bigint unsigned DEFAULT NULL,
  `width` int NOT NULL DEFAULT '250',
  PRIMARY KEY (`id`),
  KEY `mailid` (`newsletterid`),
  KEY `photo` (`photoid`),
  CONSTRAINT `newsletters_articles_ibfk_1` FOREIGN KEY (`newsletterid`) REFERENCES `newsletters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsletters_articles_ibfk_2` FOREIGN KEY (`photoid`) REFERENCES `newsletters_images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704257356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `newsletters_images`
--

DROP TABLE IF EXISTS `newsletters_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsletters_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `articleid` bigint unsigned DEFAULT NULL COMMENT 'id in the groups table',
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`articleid`),
  KEY `hash` (`hash`),
  CONSTRAINT `newsletters_images_ibfk_1` FOREIGN KEY (`articleid`) REFERENCES `newsletters_articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704267356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `noticeboards`
--

DROP TABLE IF EXISTS `noticeboards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `noticeboards` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lat` decimal(10,4) NOT NULL,
  `lng` decimal(10,4) NOT NULL,
  `position` geometry NOT NULL /*!80003 SRID 3857 */,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `addedby` bigint unsigned DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `lastcheckedat` timestamp NULL DEFAULT NULL,
  `thanked` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `added` (`added`),
  KEY `lastcheckedat` (`lastcheckedat`),
  KEY `addedby` (`addedby`),
  KEY `thanked` (`thanked`),
  SPATIAL KEY `position` (`position`),
  CONSTRAINT `noticeboards_ibfk_1` FOREIGN KEY (`addedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704277356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `noticeboards_checks`
--

DROP TABLE IF EXISTS `noticeboards_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `noticeboards_checks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `noticeboardid` bigint unsigned NOT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `askedat` timestamp NULL DEFAULT NULL,
  `checkedat` timestamp NULL DEFAULT NULL,
  `inactive` tinyint(1) NOT NULL,
  `refreshed` tinyint(1) NOT NULL DEFAULT '0',
  `declined` tinyint(1) NOT NULL DEFAULT '0',
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `checkedby` (`userid`),
  KEY `noticeboardid` (`noticeboardid`),
  CONSTRAINT `noticeboards_checks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `noticeboards_checks_ibfk_2` FOREIGN KEY (`noticeboardid`) REFERENCES `noticeboards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704287356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `noticeboards_images`
--

DROP TABLE IF EXISTS `noticeboards_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `noticeboards_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `noticeboardid` bigint unsigned DEFAULT NULL,
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`noticeboardid`),
  KEY `hash` (`hash`)
) ENGINE=InnoDB AUTO_INCREMENT=1704297356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `os_county_electoral_division_region`
--

DROP TABLE IF EXISTS `os_county_electoral_division_region`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `os_county_electoral_division_region` (
  `OGR_FID` int NOT NULL AUTO_INCREMENT,
  `SHAPE` geometry NOT NULL,
  UNIQUE KEY `OGR_FID` (`OGR_FID`)
) ENGINE=InnoDB AUTO_INCREMENT=1704307356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_addresses`
--

DROP TABLE IF EXISTS `paf_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `postcodeid` bigint unsigned DEFAULT NULL,
  `posttownid` bigint unsigned DEFAULT NULL,
  `dependentlocalityid` bigint unsigned DEFAULT NULL,
  `doubledependentlocalityid` bigint unsigned DEFAULT NULL,
  `thoroughfaredescriptorid` bigint unsigned DEFAULT NULL,
  `dependentthoroughfaredescriptorid` bigint unsigned DEFAULT NULL,
  `buildingnumber` int DEFAULT NULL,
  `buildingnameid` bigint unsigned DEFAULT NULL,
  `subbuildingnameid` bigint unsigned DEFAULT NULL,
  `poboxid` bigint unsigned DEFAULT NULL,
  `departmentnameid` bigint unsigned DEFAULT NULL,
  `organisationnameid` bigint unsigned DEFAULT NULL,
  `udprn` bigint unsigned DEFAULT NULL,
  `postcodetype` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suorganisationindicator` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deliverypointsuffix` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `udprn` (`udprn`),
  KEY `postcodeid` (`postcodeid`),
  CONSTRAINT `paf_addresses_ibfk_11` FOREIGN KEY (`postcodeid`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704317356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_buildingname`
--

DROP TABLE IF EXISTS `paf_buildingname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_buildingname` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `buildingname` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buildingname` (`buildingname`)
) ENGINE=InnoDB AUTO_INCREMENT=1704327356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_departmentname`
--

DROP TABLE IF EXISTS `paf_departmentname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_departmentname` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `departmentname` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departmentname` (`departmentname`)
) ENGINE=InnoDB AUTO_INCREMENT=1704337356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_dependentlocality`
--

DROP TABLE IF EXISTS `paf_dependentlocality`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_dependentlocality` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dependentlocality` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dependentlocality` (`dependentlocality`)
) ENGINE=InnoDB AUTO_INCREMENT=1704347356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_dependentthoroughfaredescriptor`
--

DROP TABLE IF EXISTS `paf_dependentthoroughfaredescriptor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_dependentthoroughfaredescriptor` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dependentthoroughfaredescriptor` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dependentthoroughfaredescriptor` (`dependentthoroughfaredescriptor`)
) ENGINE=InnoDB AUTO_INCREMENT=1704357356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_doubledependentlocality`
--

DROP TABLE IF EXISTS `paf_doubledependentlocality`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_doubledependentlocality` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `doubledependentlocality` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doubledependentlocality` (`doubledependentlocality`)
) ENGINE=InnoDB AUTO_INCREMENT=1704367356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_organisationname`
--

DROP TABLE IF EXISTS `paf_organisationname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_organisationname` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organisationname` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `organisationname` (`organisationname`)
) ENGINE=InnoDB AUTO_INCREMENT=1704377356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_pobox`
--

DROP TABLE IF EXISTS `paf_pobox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_pobox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pobox` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pobox` (`pobox`)
) ENGINE=InnoDB AUTO_INCREMENT=1704387356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_posttown`
--

DROP TABLE IF EXISTS `paf_posttown`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_posttown` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `posttown` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `posttown` (`posttown`)
) ENGINE=InnoDB AUTO_INCREMENT=1704397356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_subbuildingname`
--

DROP TABLE IF EXISTS `paf_subbuildingname`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_subbuildingname` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subbuildingname` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subbuildingname` (`subbuildingname`)
) ENGINE=InnoDB AUTO_INCREMENT=1704407356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `paf_thoroughfaredescriptor`
--

DROP TABLE IF EXISTS `paf_thoroughfaredescriptor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paf_thoroughfaredescriptor` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `thoroughfaredescriptor` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `thoroughfaredescriptor` (`thoroughfaredescriptor`)
) ENGINE=InnoDB AUTO_INCREMENT=1704417356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `partners_keys`
--

DROP TABLE IF EXISTS `partners_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partners_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704427356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For site-to-site integration';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `partners_messages`
--

DROP TABLE IF EXISTS `partners_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partners_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partnerid` bigint unsigned NOT NULL,
  `msgid` bigint unsigned NOT NULL,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partnerid_2` (`partnerid`,`msgid`),
  KEY `partnerid` (`partnerid`),
  KEY `msgid` (`msgid`),
  CONSTRAINT `partners_messages_ibfk_1` FOREIGN KEY (`partnerid`) REFERENCES `partners_keys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partners_messages_ibfk_2` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704437356 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `plugin`
--

DROP TABLE IF EXISTS `plugin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `groupid` bigint unsigned NOT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `plugin_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704447356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Outstanding work required to be performed by the plugin';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `polls`
--

DROP TABLE IF EXISTS `polls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `polls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `groupid` bigint unsigned DEFAULT NULL,
  `template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `logintype` enum('Facebook','Google','Yahoo','Native') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  CONSTRAINT `polls_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704457356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `polls_users`
--

DROP TABLE IF EXISTS `polls_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `polls_users` (
  `pollid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `shown` tinyint DEFAULT '1',
  `response` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `predictions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `prediction` enum('Up','Down','Disabled','Unknown') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `probs` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rater_2` (`userid`),
  CONSTRAINT `predictions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704477356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prerender`
--

DROP TABLE IF EXISTS `prerender`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prerender` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `head` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `retrieved` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `timeout` int NOT NULL DEFAULT '60' COMMENT 'In minutes',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB AUTO_INCREMENT=1704487356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED COMMENT='Saved copies of HTML for logged out view of pages';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ratings`
--

DROP TABLE IF EXISTS `ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rater` bigint unsigned DEFAULT NULL,
  `ratee` bigint unsigned NOT NULL,
  `rating` enum('Up','Down') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `visible` tinyint(1) NOT NULL DEFAULT '0',
  `tn_rating_id` bigint unsigned DEFAULT NULL,
  `reason` enum('NoShow','Punctuality','Ghosted','Rude','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewrequired` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `rater_2` (`rater`,`ratee`),
  UNIQUE KEY `tn_rating_id` (`tn_rating_id`) USING BTREE,
  KEY `rater` (`rater`),
  KEY `ratee` (`ratee`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `ratings_ibfk_1` FOREIGN KEY (`rater`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ratings_ibfk_2` FOREIGN KEY (`ratee`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704497356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `returnpath_seedlist`
--

DROP TABLE IF EXISTS `returnpath_seedlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `returnpath_seedlist` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `email` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `type` enum('ReturnPath','Litmus','Freegle') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ReturnPath',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `oneshot` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `userid` (`userid`),
  CONSTRAINT `returnpath_seedlist_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704507356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `search_history`
--

DROP TABLE IF EXISTS `search_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `search_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `term` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `locationid` bigint unsigned DEFAULT NULL,
  `groups` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `locationid` (`locationid`),
  CONSTRAINT `search_history_ibfk_1` FOREIGN KEY (`locationid`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704517356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `search_terms`
--

DROP TABLE IF EXISTS `search_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `search_terms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `term` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `term` (`term`),
  KEY `count` (`count`)
) ENGINE=InnoDB AUTO_INCREMENT=1704527356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `series` bigint unsigned NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `lastactive` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `id_3` (`id`,`series`,`token`),
  KEY `date` (`date`),
  KEY `userid` (`userid`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704537356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shortlink_clicks`
--

DROP TABLE IF EXISTS `shortlink_clicks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shortlink_clicks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shortlinkid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `shortlinkid` (`shortlinkid`),
  KEY `shortlinkid_2` (`shortlinkid`,`timestamp`),
  CONSTRAINT `shortlink_clicks_ibfk_1` FOREIGN KEY (`shortlinkid`) REFERENCES `shortlinks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704547356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shortlinks`
--

DROP TABLE IF EXISTS `shortlinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shortlinks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Group','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `groupid` bigint unsigned DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clicks` bigint NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `name` (`name`),
  CONSTRAINT `shortlinks_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704557356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_countries`
--

DROP TABLE IF EXISTS `spam_countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spam_countries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `country` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'A country we want to block',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `country` (`country`)
) ENGINE=InnoDB AUTO_INCREMENT=1704567356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_keywords`
--

DROP TABLE IF EXISTS `spam_keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spam_keywords` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exclude` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `action` enum('Review','Spam','Whitelist') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Review',
  `type` enum('Literal','Regex') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Literal',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704577356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Keywords often used by spammers';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_users`
--

DROP TABLE IF EXISTS `spam_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spam_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `byuserid` bigint unsigned DEFAULT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `addedby` bigint unsigned DEFAULT NULL,
  `collection` enum('Spammer','Whitelisted','PendingAdd','PendingRemove') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Spammer',
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `heldby` bigint unsigned DEFAULT NULL,
  `heldat` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  KEY `byuserid` (`byuserid`),
  KEY `added` (`added`),
  KEY `collection` (`collection`),
  KEY `addedby` (`addedby`),
  KEY `spam_users_ibfk_4` (`heldby`),
  CONSTRAINT `spam_users_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `spam_users_ibfk_2` FOREIGN KEY (`byuserid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `spam_users_ibfk_4` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704587356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Users who are spammers or trusted';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_whitelist_ips`
--

DROP TABLE IF EXISTS `spam_whitelist_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spam_whitelist_ips` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `ip` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB AUTO_INCREMENT=1704597356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted IP addresses';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_whitelist_links`
--

DROP TABLE IF EXISTS `spam_whitelist_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spam_whitelist_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `domain` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `count` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`),
  KEY `userid` (`userid`),
  CONSTRAINT `spam_whitelist_links_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704607356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted domains for URLs';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spam_whitelist_subjects`
--

DROP TABLE IF EXISTS `spam_whitelist_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spam_whitelist_subjects` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `ip` (`subject`)
) ENGINE=InnoDB AUTO_INCREMENT=1704617356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Whitelisted subjects';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spatial_ref_sys`
--

DROP TABLE IF EXISTS `spatial_ref_sys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spatial_ref_sys` (
  `SRID` int NOT NULL,
  `AUTH_NAME` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `AUTH_SRID` int DEFAULT NULL,
  `SRTEXT` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stats`
--

DROP TABLE IF EXISTS `stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `end` date NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `type` enum('ApprovedMessageCount','SpamMessageCount','MessageBreakdown','SpamMemberCount','PostMethodBreakdown','YahooDeliveryBreakdown','YahooPostingBreakdown','ApprovedMemberCount','SupportQueries','Happy','Fine','Unhappy','Searches','Activity','Weight','Outcomes','Replies','ActiveUsers') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` bigint unsigned DEFAULT NULL,
  `breakdown` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`,`type`,`groupid`),
  KEY `groupid` (`groupid`),
  KEY `type` (`type`,`date`,`groupid`),
  CONSTRAINT `_stats_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704637356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stats information used for dashboard';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stats_outcomes`
--

DROP TABLE IF EXISTS `stats_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stats_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `groupid` bigint unsigned NOT NULL,
  `count` int NOT NULL,
  `date` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `groupid_2` (`groupid`,`date`),
  KEY `groupid` (`groupid`) USING BTREE,
  CONSTRAINT `stats_outcomes_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704647356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For efficient stats calculations, refreshed via cron';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stats_summaries`
--

DROP TABLE IF EXISTS `stats_summaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stats_summaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `start` date NOT NULL,
  `minusstart` bigint DEFAULT NULL COMMENT 'Negative timestamp for indexing',
  `period` enum('P7D','P1M','P1Y') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `type` enum('ApprovedMessageCount','SpamMessageCount','MessageBreakdown','SpamMemberCount','PostMethodBreakdown','YahooDeliveryBreakdown','YahooPostingBreakdown','ApprovedMemberCount','SupportQueries','Happy','Fine','Unhappy','Searches','Activity','Weight','Outcomes','Replies','ActiveUsers') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` bigint unsigned DEFAULT NULL,
  `breakdown` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `start` (`start`,`period`,`type`,`groupid`) USING BTREE,
  KEY `minusstart` (`minusstart`,`period`,`type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1704657356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stats information used for dashboard';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stroll_blogs`
--

DROP TABLE IF EXISTS `stroll_blogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stroll_blogs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `title` varchar(266) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704667356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stroll_close`
--

DROP TABLE IF EXISTS `stroll_close`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stroll_close` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `dist` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704677356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stroll_nights`
--

DROP TABLE IF EXISTS `stroll_nights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stroll_nights` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `lat` decimal(10,4) NOT NULL,
  `lng` decimal(10,4) NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704687356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stroll_route`
--

DROP TABLE IF EXISTS `stroll_route`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stroll_route` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lat` decimal(10,4) NOT NULL,
  `lng` decimal(10,4) NOT NULL,
  `fromlast` decimal(10,4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704697356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Edward''s 2019 stroll; can delete after';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stroll_sponsors`
--

DROP TABLE IF EXISTS `stroll_sponsors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stroll_sponsors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704707356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Edward''s 2019 stroll; can delete after';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supporters`
--

DROP TABLE IF EXISTS `supporters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supporters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Wowzer','Front Page','Supporter','Buyer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voucher` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Voucher code',
  `vouchercount` int NOT NULL DEFAULT '1' COMMENT 'Number of licenses in this voucher',
  `voucheryears` int NOT NULL DEFAULT '1' COMMENT 'Number of years voucher licenses are valid for',
  `anonymous` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `id` (`id`),
  KEY `name` (`name`,`type`,`email`),
  KEY `display` (`display`)
) ENGINE=InnoDB AUTO_INCREMENT=1704717356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='People who have supported this site';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `type` enum('Team','Role') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Team',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `wikiurl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1704727356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Users who have particular roles in the organisation';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `teams_members`
--

DROP TABLE IF EXISTS `teams_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `teamid` bigint unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nameoverride` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imageoverride` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`teamid`),
  KEY `userid` (`userid`),
  KEY `teamid` (`teamid`),
  CONSTRAINT `teams_members_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teams_members_ibfk_2` FOREIGN KEY (`teamid`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704737356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `towns`
--

DROP TABLE IF EXISTS `towns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `towns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lat` decimal(10,4) DEFAULT NULL,
  `lng` decimal(10,4) DEFAULT NULL,
  `position` point DEFAULT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1704747356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trysts`
--

DROP TABLE IF EXISTS `trysts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trysts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `arrangedat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `arrangedfor` timestamp NULL DEFAULT NULL,
  `user1` bigint unsigned NOT NULL,
  `user2` bigint unsigned NOT NULL,
  `icssent` tinyint(1) NOT NULL DEFAULT '0',
  `ics1uid` text,
  `ics2uid` text,
  `user1response` enum('Accepted','Declined','Other') DEFAULT NULL,
  `user2response` enum('Accepted','Declined','Other') DEFAULT NULL,
  `remindersent` timestamp NULL DEFAULT NULL,
  `user1confirmed` timestamp NULL DEFAULT NULL,
  `user2confirmed` timestamp NULL DEFAULT NULL,
  `user1declined` timestamp NULL DEFAULT NULL,
  `user2declined` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `arrangedfor_2` (`arrangedfor`,`user1`,`user2`),
  KEY `arrangedfor` (`arrangedfor`),
  KEY `user1` (`user1`),
  KEY `user2` (`user2`),
  KEY `icssent` (`icssent`,`arrangedfor`) USING BTREE,
  KEY `arrangedfor_3` (`remindersent`,`arrangedfor`) USING BTREE,
  CONSTRAINT `trysts_ibfk_1` FOREIGN KEY (`user1`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trysts_ibfk_2` FOREIGN KEY (`user2`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704757356 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `yahooUserId` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unique ID of user on Yahoo if known',
  `firstname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fullname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `systemrole` set('User','Moderator','Support','Admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User' COMMENT 'System-wide roles',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastaccess` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `settings` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'JSON-encoded settings',
  `gotrealemail` tinyint NOT NULL DEFAULT '0' COMMENT 'Until migrated, whether polled FD/TN to get real email',
  `yahooid` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Any known YahooID for this user',
  `licenses` int NOT NULL DEFAULT '0' COMMENT 'Any licenses not added to groups',
  `newslettersallowed` tinyint NOT NULL DEFAULT '1' COMMENT 'Central mails',
  `relevantallowed` tinyint NOT NULL DEFAULT '1',
  `onholidaytill` date DEFAULT NULL,
  `marketingconsent` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether we have PECR consent',
  `lastlocation` bigint unsigned DEFAULT NULL,
  `lastrelevantcheck` timestamp NULL DEFAULT NULL,
  `lastidlechaseup` timestamp NULL DEFAULT NULL,
  `bouncing` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether preferred email has been determined to be bouncing',
  `permissions` set('BusinessCardsAdmin','Newsletter','NationalVolunteers','Teams','GiftAid','SpamAdmin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitesleft` int unsigned DEFAULT '10',
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chatmodstatus` enum('Moderated','Unmoderated','Fully') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Moderated',
  `deleted` timestamp NULL DEFAULT NULL,
  `inventedname` tinyint(1) NOT NULL DEFAULT '0',
  `newsfeedmodstatus` enum('Unmoderated','Moderated','Suppressed','') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unmoderated',
  `replyambit` int NOT NULL DEFAULT '0',
  `engagement` enum('New','Occasional','Frequent','Obsessed','Inactive','Dormant') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trustlevel` enum('Declined','Excluded','Basic','Moderate','Advanced') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lastupdated` timestamp NULL DEFAULT NULL,
  `tnuserid` bigint unsigned DEFAULT NULL,
  `ljuserid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `yahooUserId` (`yahooUserId`),
  UNIQUE KEY `yahooid` (`yahooid`),
  UNIQUE KEY `ljuserid` (`ljuserid`),
  KEY `systemrole` (`systemrole`),
  KEY `added` (`added`,`lastaccess`),
  KEY `fullname` (`fullname`),
  KEY `firstname` (`firstname`),
  KEY `lastname` (`lastname`),
  KEY `firstname_2` (`firstname`,`lastname`),
  KEY `gotrealemail` (`gotrealemail`),
  KEY `lastlocation` (`lastlocation`),
  KEY `lastrelevantcheck` (`lastrelevantcheck`),
  KEY `lastupdated` (`lastupdated`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`lastlocation`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704767356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_aboutme`
--

DROP TABLE IF EXISTS `users_aboutme`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_aboutme` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `userid_2` (`userid`,`timestamp`),
  CONSTRAINT `users_aboutme_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704777356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_active`
--

DROP TABLE IF EXISTS `users_active`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_active` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`timestamp`),
  KEY `timestamp` (`timestamp`),
  CONSTRAINT `users_active_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704787356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Track when users are active hourly';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_addresses`
--

DROP TABLE IF EXISTS `users_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `pafid` bigint unsigned DEFAULT NULL,
  `to` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instructions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `lat` decimal(10,6) DEFAULT NULL,
  `lng` decimal(10,6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`pafid`),
  KEY `userid` (`userid`),
  KEY `pafid` (`pafid`),
  CONSTRAINT `users_addresses_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_addresses_ibfk_3` FOREIGN KEY (`pafid`) REFERENCES `paf_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704797356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_approxlocs`
--

DROP TABLE IF EXISTS `users_approxlocs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_approxlocs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `lat` decimal(10,6) NOT NULL,
  `lng` decimal(10,6) NOT NULL,
  `position` geometry NOT NULL /*!80003 SRID 3857 */,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  KEY `timestamp` (`timestamp`),
  SPATIAL KEY `position` (`position`),
  CONSTRAINT `users_approxlocs_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704807356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_banned`
--

DROP TABLE IF EXISTS `users_banned`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_banned` (
  `userid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `byuser` bigint unsigned DEFAULT NULL,
  UNIQUE KEY `userid_2` (`userid`,`groupid`),
  KEY `groupid` (`groupid`),
  KEY `userid` (`userid`),
  KEY `date` (`date`),
  KEY `byuser` (`byuser`),
  CONSTRAINT `users_banned_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_banned_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_banned_ibfk_3` FOREIGN KEY (`byuser`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_builddates`
--

DROP TABLE IF EXISTS `users_builddates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_builddates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `webversion` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `appversion` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `users_builddates_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704827356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Used to spot old clients';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_chatlists`
--

DROP TABLE IF EXISTS `users_chatlists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_chatlists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `chatlist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `background` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`key`),
  KEY `userid` (`userid`) USING BTREE,
  CONSTRAINT `users_chatlists_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704837356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache of lists of chats for performance';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_chatlists_index`
--

DROP TABLE IF EXISTS `users_chatlists_index`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_chatlists_index` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chatid` bigint unsigned NOT NULL,
  `chatlistid` bigint unsigned NOT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatid_3` (`chatid`,`chatlistid`,`userid`),
  KEY `chatid` (`chatlistid`),
  KEY `userid` (`userid`),
  KEY `chatid_2` (`chatid`),
  CONSTRAINT `users_chatlists_index_ibfk_1` FOREIGN KEY (`chatlistid`) REFERENCES `users_chatlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_chatlists_index_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_chatlists_index_ibfk_3` FOREIGN KEY (`chatid`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704847356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_comments`
--

DROP TABLE IF EXISTS `users_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `byuserid` bigint unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user1` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user2` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user3` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user4` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user5` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user6` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user7` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user8` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user9` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user10` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user11` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `flag` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `groupid` (`groupid`),
  KEY `modid` (`byuserid`),
  KEY `userid` (`userid`,`groupid`),
  KEY `reviewed` (`reviewed`),
  CONSTRAINT `users_comments_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_comments_ibfk_2` FOREIGN KEY (`byuserid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_comments_ibfk_3` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704857356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comments from mods on members';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_dashboard`
--

DROP TABLE IF EXISTS `users_dashboard`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_dashboard` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('Reuse','Freegle','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `systemwide` tinyint(1) DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `start` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `groupid` (`groupid`),
  KEY `userid` (`userid`),
  KEY `systemwide` (`systemwide`),
  CONSTRAINT `users_dashboard_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_dashboard_ibfk_2` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704867356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cached copy of mod dashboard, gen in cron';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_donations`
--

DROP TABLE IF EXISTS `users_donations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_donations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('PayPal','External','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PayPal',
  `userid` bigint unsigned DEFAULT NULL,
  `Payer` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `PayerDisplayName` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `TransactionID` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `GrossAmount` decimal(10,2) NOT NULL,
  `source` enum('DonateWithPayPal','PayPalGivingFund','Facebook','eBay','BankTransfer','External') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DonateWithPayPal',
  `giftaidconsent` tinyint(1) NOT NULL DEFAULT '0',
  `giftaidclaimed` timestamp NULL DEFAULT NULL,
  `giftaidchaseup` timestamp NULL DEFAULT NULL,
  `TransactionType` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `TransactionID` (`TransactionID`),
  KEY `userid` (`userid`),
  KEY `GrossAmount` (`GrossAmount`),
  KEY `timestamp` (`timestamp`,`GrossAmount`),
  KEY `timestamp_2` (`timestamp`,`userid`,`GrossAmount`),
  KEY `source` (`source`),
  KEY `Payer` (`Payer`),
  CONSTRAINT `users_donations_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1704877356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_donations_asks`
--

DROP TABLE IF EXISTS `users_donations_asks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_donations_asks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  CONSTRAINT `users_donations_asks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704887356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_emails`
--

DROP TABLE IF EXISTS `users_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL COMMENT 'Unique ID in users table',
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The email',
  `preferred` tinyint NOT NULL DEFAULT '1' COMMENT 'Preferred email for this user',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `validatekey` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `validated` timestamp NULL DEFAULT NULL,
  `canon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'For spotting duplicates',
  `backwards` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Allows domain search',
  `bounced` timestamp NULL DEFAULT NULL,
  `viewed` timestamp NULL DEFAULT NULL,
  `md5hash` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (md5(lower(`email`))) VIRTUAL,
  `validatetime` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
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
) ENGINE=InnoDB AUTO_INCREMENT=1704897356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_expected`
--

DROP TABLE IF EXISTS `users_expected`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_expected` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `expecter` bigint unsigned NOT NULL,
  `expectee` bigint unsigned NOT NULL,
  `chatmsgid` bigint unsigned NOT NULL,
  `value` int NOT NULL COMMENT '1 means replied, -1 means hasn''t',
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatmsgid` (`chatmsgid`) USING BTREE,
  KEY `expectee` (`expectee`),
  KEY `userid` (`expecter`) USING BTREE,
  CONSTRAINT `users_expected_ibfk_1` FOREIGN KEY (`expecter`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_expected_ibfk_2` FOREIGN KEY (`expectee`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_expected_ibfk_3` FOREIGN KEY (`chatmsgid`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704907356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_exports`
--

DROP TABLE IF EXISTS `users_exports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_exports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `requested` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started` timestamp NULL DEFAULT NULL,
  `completed` timestamp NULL DEFAULT NULL,
  `tag` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` longblob,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `completed` (`completed`),
  CONSTRAINT `users_exports_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704917356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_images`
--

DROP TABLE IF EXISTS `users_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL COMMENT 'id in the users table',
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `default` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`userid`),
  KEY `hash` (`hash`),
  CONSTRAINT `users_images_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704927356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_invitations`
--

DROP TABLE IF EXISTS `users_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `outcome` enum('Pending','Accepted','Declined','') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `outcometimestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_2` (`userid`,`email`),
  KEY `userid` (`userid`),
  KEY `email` (`email`),
  CONSTRAINT `users_invitations_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704937356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_kudos`
--

DROP TABLE IF EXISTS `users_kudos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_kudos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kudos` int NOT NULL DEFAULT '0',
  `userid` bigint unsigned NOT NULL,
  `posts` int NOT NULL DEFAULT '0',
  `chats` int NOT NULL DEFAULT '0',
  `newsfeed` int NOT NULL DEFAULT '0',
  `events` int NOT NULL DEFAULT '0',
  `vols` int NOT NULL DEFAULT '0',
  `facebook` tinyint(1) NOT NULL DEFAULT '0',
  `platform` tinyint(1) NOT NULL DEFAULT '0',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `users_kudos_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704947356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_logins`
--

DROP TABLE IF EXISTS `users_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_logins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL COMMENT 'Unique ID in users table',
  `type` enum('Yahoo','Facebook','Google','Native','Link','Apple') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Unique identifier for login',
  `credentials` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastaccess` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `credentials2` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'For Link logins',
  `credentialsrotated` timestamp NULL DEFAULT NULL COMMENT 'For Link logins',
  `salt` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`uid`,`type`),
  UNIQUE KEY `userid_3` (`userid`,`type`,`uid`),
  KEY `userid` (`userid`),
  KEY `validated` (`lastaccess`),
  CONSTRAINT `users_logins_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704957357 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_modmails`
--

DROP TABLE IF EXISTS `users_modmails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_modmails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `logid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `groupid` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `logid` (`logid`),
  KEY `userid_2` (`userid`,`groupid`)
) ENGINE=InnoDB AUTO_INCREMENT=1704967356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_nearby`
--

DROP TABLE IF EXISTS `users_nearby`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_nearby` (
  `userid` bigint unsigned NOT NULL,
  `msgid` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fromuser` bigint unsigned DEFAULT NULL,
  `touser` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('CommentOnYourPost','CommentOnCommented','LovedPost','LovedComment','TryFeed','MembershipPending','MembershipApproved','MembershipRejected','AboutMe','Exhort','GiftAid','OpenPosts') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `newsfeedid` bigint unsigned DEFAULT NULL,
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seen` tinyint(1) NOT NULL DEFAULT '0',
  `mailed` tinyint NOT NULL DEFAULT '0',
  `title` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `newsfeedid` (`newsfeedid`),
  KEY `touser` (`touser`),
  KEY `fromuser` (`fromuser`),
  KEY `userid` (`touser`,`id`,`seen`),
  KEY `touser_2` (`timestamp`,`seen`,`mailed`) USING BTREE,
  CONSTRAINT `users_notifications_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_notifications_ibfk_2` FOREIGN KEY (`newsfeedid`) REFERENCES `newsfeed` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_notifications_ibfk_3` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704987356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_nudges`
--

DROP TABLE IF EXISTS `users_nudges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_nudges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fromuser` bigint unsigned NOT NULL,
  `touser` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fromuser` (`fromuser`),
  KEY `touser` (`touser`),
  CONSTRAINT `users_nudges_ibfk_1` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_nudges_ibfk_2` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1704997356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_phones`
--

DROP TABLE IF EXISTS `users_phones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_phones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid` tinyint(1) NOT NULL DEFAULT '1',
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastsent` timestamp NULL DEFAULT NULL,
  `lastresponse` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `laststatus` enum('queued','failed','sent','delivered','undelivered') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `laststatusreceived` timestamp NULL DEFAULT NULL,
  `count` int NOT NULL DEFAULT '0',
  `lastclicked` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`) USING BTREE,
  KEY `number` (`number`),
  KEY `laststatus` (`laststatus`,`valid`) USING BTREE,
  CONSTRAINT `users_phones_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705007356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_push_notifications`
--

DROP TABLE IF EXISTS `users_push_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_push_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('Google','Firefox','Test','Android','IOS','FCMAndroid','FCMIOS') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Google',
  `lastsent` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subscription` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apptype` enum('User','ModTools') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'User',
  `engageconsidered` timestamp NULL DEFAULT NULL,
  `engagesent` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription` (`subscription`),
  KEY `userid` (`userid`,`type`),
  KEY `type` (`type`),
  CONSTRAINT `users_push_notifications_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705017356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For sending push notifications to users';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_related`
--

DROP TABLE IF EXISTS `users_related`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_related` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user1` bigint unsigned NOT NULL,
  `user2` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notified` tinyint(1) NOT NULL DEFAULT '0',
  `detected` enum('Auto','UserRequest') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Auto',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user1_2` (`user1`,`user2`),
  KEY `user1` (`user1`),
  KEY `user2` (`user2`),
  KEY `notified` (`notified`),
  CONSTRAINT `users_related_ibfk_1` FOREIGN KEY (`user1`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_related_ibfk_2` FOREIGN KEY (`user2`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705027356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_replytime`
--

DROP TABLE IF EXISTS `users_replytime`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_replytime` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `replytime` int DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`) USING BTREE,
  CONSTRAINT `users_replytime_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705037356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_requests`
--

DROP TABLE IF EXISTS `users_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `type` enum('BusinessCards') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed` timestamp NULL DEFAULT NULL,
  `completedby` bigint unsigned DEFAULT NULL,
  `addressid` bigint unsigned DEFAULT NULL,
  `to` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notifiedmods` timestamp NULL DEFAULT NULL,
  `paid` tinyint(1) NOT NULL DEFAULT '0',
  `amount` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `addressid` (`addressid`),
  KEY `userid` (`userid`),
  KEY `completedby` (`completedby`),
  KEY `completed` (`completed`),
  CONSTRAINT `users_requests_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_requests_ibfk_2` FOREIGN KEY (`addressid`) REFERENCES `users_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_requests_ibfk_3` FOREIGN KEY (`completedby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1705047356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_searches`
--

DROP TABLE IF EXISTS `users_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_searches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `term` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `maxmsg` bigint unsigned DEFAULT NULL,
  `deleted` tinyint NOT NULL DEFAULT '0',
  `locationid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`,`term`),
  KEY `locationid` (`locationid`),
  KEY `userid_2` (`userid`),
  KEY `maxmsg` (`maxmsg`),
  KEY `userid_3` (`userid`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=1705057356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories`
--

DROP TABLE IF EXISTS `users_stories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_stories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `public` tinyint(1) NOT NULL DEFAULT '1',
  `reviewed` tinyint(1) NOT NULL DEFAULT '0',
  `headline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `story` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tweeted` tinyint NOT NULL DEFAULT '0',
  `mailedtocentral` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Mailed to groups mailing list',
  `mailedtomembers` tinyint(1) DEFAULT '0',
  `newsletterreviewed` tinyint(1) NOT NULL DEFAULT '0',
  `newsletter` tinyint(1) NOT NULL DEFAULT '0',
  `reviewedby` bigint unsigned DEFAULT NULL,
  `newsletterreviewedby` bigint unsigned DEFAULT NULL,
  `updated` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `reviewed` (`reviewed`,`public`,`newsletterreviewed`),
  KEY `date` (`date`,`reviewed`) USING BTREE,
  KEY `reviewedby` (`reviewedby`),
  KEY `newsletterreviewedby` (`newsletterreviewedby`),
  CONSTRAINT `users_stories_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_stories_ibfk_2` FOREIGN KEY (`reviewedby`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_stories_ibfk_3` FOREIGN KEY (`reviewedby`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705067356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories_images`
--

DROP TABLE IF EXISTS `users_stories_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_stories_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `storyid` bigint unsigned DEFAULT NULL,
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `default` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`storyid`),
  KEY `hash` (`hash`)
) ENGINE=InnoDB AUTO_INCREMENT=1705077356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_stories_likes`
--

DROP TABLE IF EXISTS `users_stories_likes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_stories_likes` (
  `storyid` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_stories_requested` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `userid` (`userid`),
  CONSTRAINT `users_stories_requested_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705097356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users_thanks`
--

DROP TABLE IF EXISTS `users_thanks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_thanks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  CONSTRAINT `users_thanks_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705107356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `visualise`
--

DROP TABLE IF EXISTS `visualise`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visualise` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `msgid` bigint unsigned NOT NULL,
  `attid` bigint unsigned NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `fromuser` bigint unsigned NOT NULL,
  `touser` bigint unsigned NOT NULL,
  `fromlat` decimal(10,6) NOT NULL,
  `fromlng` decimal(10,6) NOT NULL,
  `tolat` decimal(10,6) NOT NULL,
  `tolng` decimal(10,6) NOT NULL,
  `distance` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `msgid_2` (`msgid`),
  KEY `fromuser` (`fromuser`),
  KEY `touser` (`touser`),
  KEY `attid` (`attid`),
  CONSTRAINT `_visualise_ibfk_4` FOREIGN KEY (`attid`) REFERENCES `messages_attachments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualise_ibfk_1` FOREIGN KEY (`msgid`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualise_ibfk_2` FOREIGN KEY (`fromuser`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualise_ibfk_3` FOREIGN KEY (`touser`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705117356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Data to allow us to visualise flows of items to people';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering`
--

DROP TABLE IF EXISTS `volunteering`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `volunteering` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `pending` tinyint(1) NOT NULL DEFAULT '0',
  `title` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `online` tinyint(1) NOT NULL DEFAULT '0',
  `location` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contactname` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactphone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contactemail` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacturl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted` tinyint NOT NULL DEFAULT '0',
  `deletedby` bigint unsigned DEFAULT NULL,
  `askedtorenew` timestamp NULL DEFAULT NULL,
  `renewed` timestamp NULL DEFAULT NULL,
  `expired` tinyint(1) NOT NULL DEFAULT '0',
  `timecommitment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `heldby` bigint unsigned DEFAULT NULL,
  `deletedcovid` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Deleted as part of reopening',
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `title` (`title`),
  KEY `deletedby` (`deletedby`),
  KEY `heldby` (`heldby`),
  CONSTRAINT `volunteering_ibfk_1` FOREIGN KEY (`deletedby`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `volunteering_ibfk_2` FOREIGN KEY (`heldby`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1705127356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering_dates`
--

DROP TABLE IF EXISTS `volunteering_dates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `volunteering_dates` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `volunteeringid` bigint unsigned NOT NULL,
  `start` timestamp NULL DEFAULT NULL,
  `end` timestamp NULL DEFAULT NULL,
  `applyby` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `start` (`start`),
  KEY `eventid` (`volunteeringid`),
  KEY `end` (`end`),
  CONSTRAINT `volunteering_dates_ibfk_1` FOREIGN KEY (`volunteeringid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705137356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `volunteering_groups`
--

DROP TABLE IF EXISTS `volunteering_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `volunteering_groups` (
  `volunteeringid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `volunteering_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `opportunityid` bigint unsigned DEFAULT NULL COMMENT 'id in the volunteering table',
  `contenttype` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `archived` tinyint DEFAULT '0',
  `data` longblob,
  `identification` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hash` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `incomingid` (`opportunityid`),
  KEY `hash` (`hash`),
  CONSTRAINT `volunteering_images_ibfk_1` FOREIGN KEY (`opportunityid`) REFERENCES `volunteering` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1705157356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 COMMENT='Attachments parsed out from messages and resized';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vouchers`
--

DROP TABLE IF EXISTS `vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vouchers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `voucher` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used` timestamp NULL DEFAULT NULL,
  `groupid` bigint unsigned DEFAULT NULL COMMENT 'Group that a voucher was used on',
  `userid` bigint unsigned DEFAULT NULL COMMENT 'User who redeemed a voucher',
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher` (`voucher`),
  KEY `groupid` (`groupid`),
  KEY `userid` (`userid`),
  CONSTRAINT `vouchers_ibfk_1` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vouchers_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1705167356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='For licensing groups';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `vw_ECC`
--

DROP TABLE IF EXISTS `vw_ECC`;
/*!50001 DROP VIEW IF EXISTS `vw_ECC`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_ECC` AS SELECT 
 1 AS `date`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_donations`
--

DROP TABLE IF EXISTS `vw_donations`;
/*!50001 DROP VIEW IF EXISTS `vw_donations`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_donations` AS SELECT 
 1 AS `total`,
 1 AS `date`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_freeglegroups_unreached`
--

DROP TABLE IF EXISTS `vw_freeglegroups_unreached`;
/*!50001 DROP VIEW IF EXISTS `vw_freeglegroups_unreached`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_freeglegroups_unreached` AS SELECT 
 1 AS `id`,
 1 AS `nameshort`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_job_clicks`
--

DROP TABLE IF EXISTS `vw_job_clicks`;
/*!50001 DROP VIEW IF EXISTS `vw_job_clicks`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_job_clicks` AS SELECT 
 1 AS `date`,
 1 AS `count`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_manyemails`
--

DROP TABLE IF EXISTS `vw_manyemails`;
/*!50001 DROP VIEW IF EXISTS `vw_manyemails`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_manyemails` AS SELECT 
 1 AS `id`,
 1 AS `fullname`,
 1 AS `email`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_membersyncpending`
--

DROP TABLE IF EXISTS `vw_membersyncpending`;
/*!50001 DROP VIEW IF EXISTS `vw_membersyncpending`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_membersyncpending` AS SELECT 
 1 AS `id`,
 1 AS `groupid`,
 1 AS `members`,
 1 AS `lastupdated`,
 1 AS `lastprocessed`,
 1 AS `synctime`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_multiemails`
--

DROP TABLE IF EXISTS `vw_multiemails`;
/*!50001 DROP VIEW IF EXISTS `vw_multiemails`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_multiemails` AS SELECT 
 1 AS `id`,
 1 AS `fullname`,
 1 AS `count`,
 1 AS `GROUP_CONCAT(email SEPARATOR ', ')`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_recentgroupaccess`
--

DROP TABLE IF EXISTS `vw_recentgroupaccess`;
/*!50001 DROP VIEW IF EXISTS `vw_recentgroupaccess`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_recentgroupaccess` AS SELECT 
 1 AS `lastaccess`,
 1 AS `nameshort`,
 1 AS `id`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_recentlogins`
--

DROP TABLE IF EXISTS `vw_recentlogins`;
/*!50001 DROP VIEW IF EXISTS `vw_recentlogins`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_recentlogins` AS SELECT 
 1 AS `timestamp`,
 1 AS `id`,
 1 AS `fullname`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_recentposts`
--

DROP TABLE IF EXISTS `vw_recentposts`;
/*!50001 DROP VIEW IF EXISTS `vw_recentposts`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_recentposts` AS SELECT 
 1 AS `id`,
 1 AS `date`,
 1 AS `fromaddr`,
 1 AS `subject`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_simple_version`
--

DROP TABLE IF EXISTS `vw_simple_version`;
/*!50001 DROP VIEW IF EXISTS `vw_simple_version`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_simple_version` AS SELECT 
 1 AS `count`,
 1 AS `simple`*/;
SET character_set_client = @saved_cs_client;

--
-- Temporary view structure for view `vw_src`
--

DROP TABLE IF EXISTS `vw_src`;
/*!50001 DROP VIEW IF EXISTS `vw_src`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `vw_src` AS SELECT 
 1 AS `count`,
 1 AS `src`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `weights`
--

DROP TABLE IF EXISTS `weights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `weights` (
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `simplename` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'The name in simpler terms',
  `weight` decimal(5,2) NOT NULL,
  `source` enum('FRN 2009','Freegle') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FRN 2009',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Standard weights, from FRN 2009';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `words`
--

DROP TABLE IF EXISTS `words`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `words` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `firstthree` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `soundex` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `popularity` bigint NOT NULL DEFAULT '0' COMMENT 'Negative as DESC index not supported',
  PRIMARY KEY (`id`),
  UNIQUE KEY `word_2` (`word`),
  KEY `popularity` (`popularity`),
  KEY `word` (`word`,`popularity`),
  KEY `soundex` (`soundex`,`popularity`),
  KEY `firstthree` (`firstthree`,`popularity`)
) ENGINE=InnoDB AUTO_INCREMENT=1705187356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Unique words for searches';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `words_cache`
--

DROP TABLE IF EXISTS `words_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `words_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `search` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `words` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `search` (`search`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1705197356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `worrywords`
--

DROP TABLE IF EXISTS `worrywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worrywords` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `keyword` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `substance` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('Regulated','Reportable','Medicine','Review','Allowed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword` (`keyword`)
) ENGINE=InnoDB AUTO_INCREMENT=1705207356 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Final view structure for view `VW_Essex_Searches`
--

/*!50001 DROP VIEW IF EXISTS `VW_Essex_Searches`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 VIEW `VW_SupportCallAverage` AS select avg(`t`.`total`) AS `AVG(total)` from (select `stats`.`date` AS `date`,sum(`stats`.`count`) AS `total` from `stats` where ((`stats`.`type` = 'SupportQueries') and ((to_days(now()) - to_days(`stats`.`date`)) <= 31)) group by `stats`.`date`) `t` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_item_similarities`
--

/*!50001 DROP VIEW IF EXISTS `VW_item_similarities`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_item_similarities` AS select `i1`.`name` AS `namr1`,`i2`.`name` AS `name2`,greatest(`microactions`.`item1`,`microactions`.`item2`) AS `a`,least(`microactions`.`item1`,`microactions`.`item2`) AS `b`,count(0) AS `count` from ((`microactions` join `items` `i1` on((`i1`.`id` = greatest(`microactions`.`item1`,`microactions`.`item2`)))) join `items` `i2` on((`i2`.`id` = least(`microactions`.`item1`,`microactions`.`item2`)))) group by `a`,`b` order by `count` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_landing_button`
--

/*!50001 DROP VIEW IF EXISTS `VW_landing_button`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_landing_button` AS select `abtest`.`id` AS `id`,`abtest`.`uid` AS `uid`,`abtest`.`variant` AS `variant`,`abtest`.`shown` AS `shown`,`abtest`.`action` AS `action`,`abtest`.`rate` AS `rate`,`abtest`.`suggest` AS `suggest` from `abtest` where (`abtest`.`uid` like 'landing-button') order by `abtest`.`rate` desc */;
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
/*!50001 VIEW `VW_not_on_central` AS select `groups`.`id` AS `id`,`groups`.`nameshort` AS `nameshort` from `groups` where ((`groups`.`type` = 'Freegle') and (`groups`.`publish` = 1) and `groups`.`id` in (select distinct `memberships`.`groupid` from (`memberships` join `groups` on((`groups`.`id` = `memberships`.`groupid`))) where (`memberships`.`userid` in (select distinct `memberships`.`userid` from (`memberships` join `groups` on(((`groups`.`id` = `memberships`.`groupid`) and (`groups`.`nameshort` like 'FreegleUK-Central'))))) and (`memberships`.`role` in ('Owner','Moderator')) and (`groups`.`type` = 'Freegle'))) is false and (`groups`.`external` is null)) */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_routes` AS select `logs_events`.`route` AS `route`,count(0) AS `count` from `logs_events` group by `logs_events`.`route` order by `count` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_search_term_similarities`
--

/*!50001 DROP VIEW IF EXISTS `VW_search_term_similarities`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_search_term_similarities` AS select `t1`.`term` AS `term1`,`t2`.`term` AS `term2` from ((`microactions` join `search_terms` `t1` on((`t1`.`id` = `microactions`.`searchterm1`))) join `search_terms` `t2` on((`t2`.`id` = `microactions`.`searchterm2`))) order by `microactions`.`id` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `VW_sincelockdown`
--

/*!50001 DROP VIEW IF EXISTS `VW_sincelockdown`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `VW_sincelockdown` AS select `messages_groups`.`msgid` AS `msgid`,`messages`.`date` AS `date`,`messages`.`subject` AS `subject`,`groups`.`nameshort` AS `nameshort` from ((`messages_groups` join `messages` on((`messages`.`id` = `messages_groups`.`msgid`))) join `groups` on((`messages_groups`.`groupid` = `groups`.`id`))) where (`messages`.`date` >= '2020-05-08') order by `messages_groups`.`msgid` desc */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_freeglegroups_unreached` AS select `groups`.`id` AS `id`,`groups`.`nameshort` AS `nameshort` from `groups` where ((`groups`.`type` = 'Freegle') and (not((`groups`.`nameshort` like '%playground%'))) and (not((`groups`.`nameshort` like '%test%'))) and `groups`.`id` in (select `alerts_tracking`.`groupid` from `alerts_tracking` where (`alerts_tracking`.`response` is not null)) is false) order by `groups`.`nameshort` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_job_clicks`
--

/*!50001 DROP VIEW IF EXISTS `vw_job_clicks`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_job_clicks` AS select cast(`logs_jobs`.`timestamp` as date) AS `date`,count(0) AS `count` from `logs_jobs` group by `date` order by `date` */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_membersyncpending` AS select `memberships_yahoo_dump`.`id` AS `id`,`memberships_yahoo_dump`.`groupid` AS `groupid`,`memberships_yahoo_dump`.`members` AS `members`,`memberships_yahoo_dump`.`lastupdated` AS `lastupdated`,`memberships_yahoo_dump`.`lastprocessed` AS `lastprocessed`,`memberships_yahoo_dump`.`synctime` AS `synctime` from `memberships_yahoo_dump` where ((`memberships_yahoo_dump`.`lastprocessed` is null) or (`memberships_yahoo_dump`.`lastupdated` > `memberships_yahoo_dump`.`lastprocessed`)) */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_recentposts` AS select `messages`.`id` AS `id`,`messages`.`date` AS `date`,`messages`.`fromaddr` AS `fromaddr`,`messages`.`subject` AS `subject` from (`messages` left join `messages_drafts` on((`messages_drafts`.`msgid` = `messages`.`id`))) where ((`messages`.`source` = 'Platform') and (`messages_drafts`.`msgid` is null)) order by `messages`.`date` desc limit 20 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_simple_version`
--

/*!50001 DROP VIEW IF EXISTS `vw_simple_version`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_simple_version` AS select count(0) AS `count`,(`users`.`settings` like '%"simple":true%') AS `simple` from `users` where ((`users`.`settings` like '%"simple"%') and (`users`.`systemrole` = 'User')) group by `simple` */;
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
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
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

-- Dump completed on 2023-12-22 16:18:22

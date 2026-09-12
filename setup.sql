-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: wi_attend
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_users`
--

DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(150) NOT NULL,
  `role` enum('superadmin','admin') DEFAULT 'admin',
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_users`
--

LOCK TABLES `admin_users` WRITE;
/*!40000 ALTER TABLE `admin_users` DISABLE KEYS */;
INSERT INTO `admin_users` VALUES (1,'admin','$2y$10$TKh8H1.PfQ0A32/erkzTau2fNiYrgSwxOKJ0buvEM9F2S2BGFP37.','admin@wiattend.com','superadmin',NULL);
/*!40000 ALTER TABLE `admin_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_logs`
--

DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `mac_id` int(11) NOT NULL,
  `wifi_zone_id` int(11) NOT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `status` enum('present','left','absent') DEFAULT 'present',
  `missed_pings` int(11) DEFAULT 0,
  `reconnections` int(11) DEFAULT 0,
  `reconnect_log` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reconnect_log`)),
  PRIMARY KEY (`log_id`),
  KEY `staff_id` (`staff_id`),
  KEY `mac_id` (`mac_id`),
  KEY `wifi_zone_id` (`wifi_zone_id`),
  CONSTRAINT `attendance_logs_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_logs_ibfk_2` FOREIGN KEY (`mac_id`) REFERENCES `mac_addresses` (`mac_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_logs_ibfk_3` FOREIGN KEY (`wifi_zone_id`) REFERENCES `wifi_zones` (`wifi_zone_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_logs`
--

LOCK TABLES `attendance_logs` WRITE;
/*!40000 ALTER TABLE `attendance_logs` DISABLE KEYS */;
INSERT INTO `attendance_logs` VALUES (42,22,34,23,'2026-09-09 22:57:16',NULL,NULL,'present',0,0,NULL),(43,22,34,23,'2026-09-12 22:36:36',NULL,NULL,'present',0,0,NULL);
/*!40000 ALTER TABLE `attendance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `dept_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`department_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'IT Department','Information Technology team','Floor 2','2026-06-28 03:34:01'),(2,'HR Department','Human Resources','Floor 1','2026-06-28 03:34:01'),(3,'Finance','Finance and Accounts','Floor 1','2026-06-28 03:34:01');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mac_addresses`
--

DROP TABLE IF EXISTS `mac_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mac_addresses` (
  `mac_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `mac_address` varchar(17) NOT NULL,
  `device_label` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`mac_id`),
  UNIQUE KEY `mac_address` (`mac_address`),
  KEY `staff_id` (`staff_id`),
  CONSTRAINT `mac_addresses_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mac_addresses`
--

LOCK TABLES `mac_addresses` WRITE;
/*!40000 ALTER TABLE `mac_addresses` DISABLE KEYS */;
INSERT INTO `mac_addresses` VALUES (34,22,'ee:2b:86:3a:86:b8',NULL,1,'2026-09-09 17:12:09');
/*!40000 ALTER TABLE `mac_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff`
--

DROP TABLE IF EXISTS `staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `email` (`email`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff`
--

LOCK TABLES `staff` WRITE;
/*!40000 ALTER TABLE `staff` DISABLE KEYS */;
INSERT INTO `staff` VALUES (22,'Anjan Sharma Ghimire','anjan.ghimire@aitm.edu.np',NULL,NULL,NULL,1,'2026-07-15 02:30:55');
/*!40000 ALTER TABLE `staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_schedules`
--

DROP TABLE IF EXISTS `staff_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `shift` enum('morning','day','night') NOT NULL DEFAULT 'day',
  `expected_in` time NOT NULL,
  `expected_out` time NOT NULL,
  `grace_minutes` int(11) DEFAULT 15,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `staff_id` (`staff_id`),
  UNIQUE KEY `uniq_staff` (`staff_id`),
  KEY `idx_staff_schedules_shift` (`shift`),
  CONSTRAINT `staff_schedules_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_schedules`
--

LOCK TABLES `staff_schedules` WRITE;
/*!40000 ALTER TABLE `staff_schedules` DISABLE KEYS */;
INSERT INTO `staff_schedules` VALUES (12,22,'day','14:00:00','22:00:00',15,1);
/*!40000 ALTER TABLE `staff_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wifi_zones`
--

DROP TABLE IF EXISTS `wifi_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wifi_zones` (
  `wifi_zone_id` int(11) NOT NULL AUTO_INCREMENT,
  `zone_name` varchar(100) NOT NULL,
  `router_mac` varchar(17) NOT NULL,
  `ssid` varchar(100) NOT NULL,
  `location_desc` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `gateway_ip` varchar(45) DEFAULT NULL,
  `subnet` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`wifi_zone_id`),
  UNIQUE KEY `router_mac` (`router_mac`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wifi_zones`
--

LOCK TABLES `wifi_zones` WRITE;
/*!40000 ALTER TABLE `wifi_zones` DISABLE KEYS */;
INSERT INTO `wifi_zones` VALUES (23,'Home','0c:88:32:68:0d:59','krishnam11',NULL,1,NULL,NULL);
/*!40000 ALTER TABLE `wifi_zones` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-12 23:32:59

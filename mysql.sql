--
-- Database: `aprs-is`
--
CREATE DATABASE IF NOT EXISTS `aprs-is` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `aprs-is`;

-- --------------------------------------------------------

--
-- Table structure for table `objects`
--

CREATE TABLE IF NOT EXISTS `objects` (
  `name` varchar(9) NOT NULL,
  `symbol` varchar(2) NOT NULL DEFAULT '//',
  `timestamp` int(10) unsigned NOT NULL DEFAULT '0',
  `lat` float(12,10) DEFAULT '0.0000000000',
  `lon` float(13,10) DEFAULT '0.0000000000',
  `comment` varchar(255) NOT NULL DEFAULT '',
  `kill` enum('N','Y') NOT NULL DEFAULT 'N',
  PRIMARY KEY (`name`),
  KEY `timestamp` (`timestamp`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `opts`
--

CREATE TABLE IF NOT EXISTS `opts` (
  `key` varchar(25) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `recv`
--

CREATE TABLE IF NOT EXISTS `recv` (
  `timestamp` int(10) unsigned NOT NULL,
  `src` varchar(9) NOT NULL,
  `ssid` varchar(9) NOT NULL,
  `packet` varchar(512) NOT NULL,
  PRIMARY KEY (`src`,`ssid`,`timestamp`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `stations`
--

CREATE TABLE IF NOT EXISTS `stations` (
  `ssid` varchar(9) NOT NULL,
  `src` varchar(9) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL DEFAULT '',
  `station_type` enum('station','object','item') DEFAULT NULL,
  `symbol` varchar(2) DEFAULT NULL,
  `timestamp` int(10) unsigned NOT NULL,
  `lat` float(12,10) DEFAULT NULL,
  `lon` float(13,10) DEFAULT NULL,
  `comment` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT '',
  `capabilities` varchar(255) NOT NULL,
  `msg_capable` enum('N','Y') NOT NULL DEFAULT 'N',
  `kill` enum('N','Y') NOT NULL DEFAULT 'N',
  PRIMARY KEY (`ssid`),
  KEY `timestamp` (`timestamp`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `txQ`
--

CREATE TABLE IF NOT EXISTS `txQ` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `data` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;
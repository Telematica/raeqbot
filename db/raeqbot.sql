
SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table structure for bk_query
-- ----------------------------
DROP TABLE IF EXISTS `bk_query`;
CREATE TABLE `bk_query` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `content` varchar(255) DEFAULT NULL,
  `result` bit(1) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

-- ----------------------------
-- Table structure for query
-- ----------------------------
DROP TABLE IF EXISTS `query`;
CREATE TABLE `query` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `time` datetime NOT NULL,
  `term_id` int(11) NOT NULL,
  `result` bit(1) DEFAULT NULL,
  `user_id_hash` varchar(32) DEFAULT NULL,
  `from_cache` bit(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_query_term_id` (`term_id`) USING BTREE,
  CONSTRAINT `FK_query_term_id` FOREIGN KEY (`term_id`) REFERENCES `term` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;

-- ----------------------------
-- Table structure for term
-- ----------------------------
DROP TABLE IF EXISTS `term`;
CREATE TABLE `term` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;

-- ----------------------------
-- Table structure for term_result
-- ----------------------------
DROP TABLE IF EXISTS `term_result`;
CREATE TABLE `term_result` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `term_id` int(11) NOT NULL,
  `position` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_term_result_term_id` (`term_id`) USING BTREE,
  CONSTRAINT `FK_term_result_term_id` FOREIGN KEY (`term_id`) REFERENCES `term` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;

-- ----------------------------
-- View structure for view_queries
-- ----------------------------
DROP VIEW IF EXISTS `view_queries`;
CREATE ALGORITHM=UNDEFINED DEFINER=`afliw`@`%` SQL SECURITY DEFINER VIEW `view_queries` AS select year(`query`.`time`) AS `Year`,monthname(`query`.`time`) AS `Month`,count(distinct `query`.`user_id_hash`) AS `Users`,count(distinct `query`.`term_id`) AS `Terms`,sum(`query`.`from_cache`) AS `Cache Usage`,sum(((cast(`query`.`result` as signed) - 1) * -(1))) AS `Failed`,count(0) AS `Queries` from `query` group by 1,2 order by `query`.`time` ;

-- ----------------------------
-- View structure for view_statistics
-- ----------------------------
DROP VIEW IF EXISTS `view_statistics`;
CREATE ALGORITHM=UNDEFINED DEFINER=`afliw`@`%` SQL SECURITY DEFINER VIEW `view_statistics` AS select year(`query`.`time`) AS `Year`,monthname(`query`.`time`) AS `Month`,count(distinct `query`.`user_id_hash`) AS `Users`,count(distinct `query`.`term_id`) AS `Terms`,sum(`query`.`from_cache`) AS `Cache Usage`,count(0) AS `Queries` from `query` group by 1,2 order by `query`.`time` ;

--
-- Table structure for tx_myext_domain_model_article
-- Used for functional test fixtures
--
CREATE TABLE tx_myext_domain_model_article
(
		uid         int(11)                  NOT NULL AUTO_INCREMENT,
		pid         int(11)      DEFAULT '0' NOT NULL,
		title       varchar(255) DEFAULT ''  NOT NULL,
		category_id int(11)      DEFAULT '0' NOT NULL,
		hidden      tinyint(4)   DEFAULT '0' NOT NULL,
		deleted     tinyint(4)   DEFAULT '0' NOT NULL,
		tstamp      int(11)      DEFAULT '0' NOT NULL,
		crdate      int(11)      DEFAULT '0' NOT NULL,
		PRIMARY KEY (uid),
		KEY         parent (pid)
);

CREATE TABLE tx_myext_domain_model_category
(
		uid     int(11)                  NOT NULL AUTO_INCREMENT,
		pid     int(11)      DEFAULT '0' NOT NULL,
		name    varchar(255) DEFAULT ''  NOT NULL,
		hidden  tinyint(4)   DEFAULT '0' NOT NULL,
		deleted tinyint(4)   DEFAULT '0' NOT NULL,
		PRIMARY KEY (uid),
		KEY     parent (pid)
);

CREATE TABLE tx_myext_domain_model_tag
(
		uid     int(11)                  NOT NULL AUTO_INCREMENT,
		pid     int(11)      DEFAULT '0' NOT NULL,
		name    varchar(255) DEFAULT ''  NOT NULL,
		hidden  tinyint(4)   DEFAULT '0' NOT NULL,
		deleted tinyint(4)   DEFAULT '0' NOT NULL,
		PRIMARY KEY (uid),
		KEY     parent (pid)
);

CREATE TABLE tx_myext_article_tag_mm
(
		uid_local   int(11) DEFAULT '0' NOT NULL,
		uid_foreign int(11) DEFAULT '0' NOT NULL,
		sorting     int(11) DEFAULT '0' NOT NULL,
		KEY uid_local (uid_local),
		KEY uid_foreign (uid_foreign)
);

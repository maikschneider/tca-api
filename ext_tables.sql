--
-- Table structure for tx_myext_domain_model_article
-- Used for functional test fixtures
--
CREATE TABLE tx_myext_domain_model_article
(
    uid     int(11)                  NOT NULL AUTO_INCREMENT,
    pid     int(11)      DEFAULT '0' NOT NULL,
    title   varchar(255) DEFAULT ''  NOT NULL,
    hidden  tinyint(4)   DEFAULT '0' NOT NULL,
    deleted tinyint(4)   DEFAULT '0' NOT NULL,
    tstamp  int(11)      DEFAULT '0' NOT NULL,
    crdate  int(11)      DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    KEY     parent (pid)
);

--
-- $Id$
--
create table tbl_users (
  id                    serial,
  billingID             varchar(64) unique NOT NULL,
  description           varchar(64),
  username              varchar(32),
  password              varchar(32),
  email                 varchar(64),
  maxOperations         int DEFAULT 0,
  dns                   text,
  techc                 text,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_transactions (
  id                    serial,
  clTRID                varchar(32),
  clTRType              varchar(32),
  clTRObject            varchar(256),
  clTRData              text
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_responses (
  id                    serial,
  clTRID                varchar(32),
  svTRID                varchar(64),
  svEPPCode             varchar(4),
  status                tinyint unsigned,
  svHTTPCode            smallint unsigned,
  svHTTPHeaders         text,
  svHTTPData            text,
  extValueReasonCode    varchar(4),
  extValueReason        text
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_msgqueue (
  id                    serial,
  clTRID                varchar(32),
  svTRID                varchar(64),
  svCode                varchar(4),
  status                tinyint unsigned,
  svHTTPCode            smallint unsigned,
  svHTTPHeaders         text,
  svHTTPData            text
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_contacts (
  id                    serial,
  userID                bigint unsigned NOT NULL DEFAULT 1,
  status                text,
  handle                varchar(32) unique NOT NULL,
  name                  varchar(64),
  org                   varchar(64),
  street                varchar(64),
  street2               varchar(64),
  street3               varchar(64),
  city                  varchar(64),
  province              varchar(128),
  postalcode            varchar(16),
  countrycode           varchar(2),
  voice                 varchar(64),
  fax                   varchar(64),
  email                 varchar(64),
  authinfo              varchar(64),
  consentforpublishing  tinyint unsigned,
  nationalitycode       varchar(2),
  entitytype            tinyint unsigned,
  regcode               varchar(32),
  schoolcode            varchar(32),
  active                tinyint DEFAULT 1,
  PRIMARY KEY (id),
  KEY (handle),
  CONSTRAINT FOREIGN KEY (userID) REFERENCES tbl_users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_domains (
  id                    serial,
  userID                bigint unsigned NOT NULL DEFAULT 1,
  active                tinyint DEFAULT 1,
  status                text,
  domain                varchar(255) unique NOT NULL,
  authinfo              varchar(64),
  ns                    text,
  registrant            varchar(32) NOT NULL,
  admin                 varchar(32),
  tech                  text,
  crDate                date,
  exDate                date,
  dnssec		text,
  dsAlgorithm           tinyint unsigned NOT NULL DEFAULT 0,
  dsDigest              varchar(255) DEFAULT '',
  dsDigestType          tinyint unsigned NOT NULL DEFAULT 0,
  dsKeyTag              varchar(255) DEFAULT '',
  lastInvoice           timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT FOREIGN KEY (userID) REFERENCES tbl_users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES tbl_contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_transfers (
  id                    serial,
  userID                bigint unsigned NOT NULL DEFAULT 1,
  domain                varchar(255) unique NOT NULL,
  techc                 text,
  dns                   text,
  registrant            varchar(32) NOT NULL,
  time                  timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES tbl_contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;

create table tbl_messages (
  id                    serial,
  clTRID                varchar(32),
  svTRID                varchar(64),
  type                  varchar(64) NOT NULL,
  domain                varchar(255),
  acID                  varchar(255),
  reID                  varchar(255),
  data                  text NOT NULL,
  archived              tinyint DEFAULT 0,
  archivedUserID        bigint unsigned,
  archivedTime          datetime,
  createdTime           timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;
